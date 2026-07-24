from __future__ import annotations

import json
import uuid
from datetime import datetime, timezone
from typing import Any

from fastapi import APIRouter, File, HTTPException, UploadFile, WebSocket, WebSocketDisconnect
from fastapi.responses import FileResponse

from backend.config import settings
from backend.models.detection_event import DetectionEventIn, RadarSettings, RadarStatus
from backend.services.alarm_service import alarm_service
from backend.services.event_recorder import event_recorder
from backend.services.event_service import event_service
from backend.services.object_detector import MotionDetector, ObjectDetector, severity_for
from backend.services.radar_serial_service import radar_serial_service

router = APIRouter(prefix="/api/radar", tags=["radar"])

_ws_clients: set[WebSocket] = set()
_active_markers: dict[str, dict[str, Any]] = {}
_camera_connected = False


async def broadcast_event(payload: dict[str, Any]) -> None:
    dead: list[WebSocket] = []
    for ws in list(_ws_clients):
        try:
            await ws.send_json(payload)
        except Exception:
            dead.append(ws)
    for ws in dead:
        _ws_clients.discard(ws)


def _status() -> RadarStatus:
    cfg = event_service.get_settings()
    mode = settings.radar_mode.lower()
    if mode == "demo":
        mode = "mock"
    system_status = "armed" if cfg.armed else "disarmed"
    if _active_markers:
        system_status = "alert"
    return RadarStatus(
        system_status=system_status,
        operating_mode=mode if mode in {"camera", "hardware", "mock"} else "mock",
        demo_mode=settings.radar_mode.lower() in {"mock", "demo"},
        camera_connected=_camera_connected,
        radar_connected=radar_serial_service.is_connected(),
        active_markers=len(_active_markers),
    )


async def process_hardware_motion(raw: dict[str, Any]) -> None:
    if raw.get("event") != "motion":
        return
    cfg = event_service.get_settings()
    if not cfg.armed:
        return

    mode = settings.radar_mode.lower()
    sensor_mode = "mock" if mode in {"mock", "demo"} else "hardware"
    distance_m = raw.get("distance_m")
    angle_deg = raw.get("angle_deg")
    tracking_id = f"radar-{raw.get('timestamp') or uuid.uuid4().hex[:10]}"

    energy = raw.get("energy")
    confidence = 0.82
    if energy is not None:
        try:
            confidence = min(0.95, max(0.55, float(energy) / 100))
        except (TypeError, ValueError):
            pass

    object_type = "person"
    if not event_service.passes_filter(object_type, cfg.alert_filter):
        return
    if not event_service.should_alert(tracking_id, cfg):
        return

    severity = severity_for(
        object_type,
        float(distance_m) if distance_m is not None else None,
        cfg.selected_range_m,
    )
    payload = DetectionEventIn(
        tracking_id=tracking_id,
        object_type=object_type,
        confidence=confidence,
        distance_m=float(distance_m) if distance_m is not None else None,
        angle_deg=float(angle_deg) if angle_deg is not None else None,
        speed_mps=float(raw["speed_mps"]) if raw.get("speed_mps") is not None else None,
        sensor_mode=sensor_mode,
        camera_name="mmWave Radar",
        severity=severity,
        metadata={"energy": energy, "source": "hardware"},
    )
    event = event_service.create_event(payload)
    marker = {
        "tracking_id": tracking_id,
        "object_type": object_type,
        "severity": severity,
        "distance_m": payload.distance_m,
        "angle_deg": payload.angle_deg,
        "has_direction": payload.angle_deg is not None,
        "last_seen": datetime.now(timezone.utc).isoformat(),
    }
    _active_markers[tracking_id] = marker
    await broadcast_event(
        {
            "type": "detection",
            "event": event,
            "marker": marker,
            "alarm": alarm_service.payload_for_event(event),
        }
    )


@router.get("/status")
def get_status() -> dict[str, Any]:
    return {"ok": True, **_status().model_dump()}


@router.get("/settings")
def get_settings() -> dict[str, Any]:
    return {"ok": True, "settings": event_service.get_settings().model_dump()}


@router.put("/settings")
def put_settings(payload: RadarSettings) -> dict[str, Any]:
    saved = event_service.save_settings(payload)
    alarm_service.configure(saved.alarm_enabled, saved.alarm_volume)
    radar_serial_service.set_armed(saved.armed)
    return {"ok": True, "settings": saved.model_dump()}


@router.post("/arm")
def arm() -> dict[str, Any]:
    saved = event_service.set_armed(True)
    radar_serial_service.set_armed(True)
    return {"ok": True, "settings": saved.model_dump()}


@router.post("/disarm")
def disarm() -> dict[str, Any]:
    saved = event_service.set_armed(False)
    radar_serial_service.set_armed(False)
    _active_markers.clear()
    return {"ok": True, "settings": saved.model_dump()}


@router.post("/stop-all")
def stop_all() -> dict[str, Any]:
    saved = event_service.set_armed(False)
    radar_serial_service.set_armed(False)
    _active_markers.clear()
    return {"ok": True, "message": "Emergency STOP_ALL executed.", "settings": saved.model_dump()}


@router.get("/events")
def list_events(
    category: str | None = None,
    severity: str | None = None,
    acknowledged: bool | None = None,
    false_alarm: bool | None = None,
    date: str | None = None,
) -> dict[str, Any]:
    events = event_service.list_events(
        {
            "category": category,
            "severity": severity,
            "acknowledged": acknowledged,
            "false_alarm": false_alarm,
            "date": date,
        }
    )
    return {"ok": True, "events": events}


@router.get("/events/{event_id}")
def get_event(event_id: int) -> dict[str, Any]:
    event = event_service.get_event(event_id)
    if not event:
        raise HTTPException(status_code=404, detail="Event not found")
    return {"ok": True, "event": event}


@router.post("/events/{event_id}/acknowledge")
async def acknowledge_event(event_id: int) -> dict[str, Any]:
    event = event_service.acknowledge(event_id)
    if not event:
        raise HTTPException(status_code=404, detail="Event not found")
    await broadcast_event({"type": "acknowledged", "event": event})
    return {"ok": True, "event": event}


@router.post("/events/{event_id}/false-alarm")
async def false_alarm_event(event_id: int) -> dict[str, Any]:
    event = event_service.false_alarm(event_id)
    if not event:
        raise HTTPException(status_code=404, detail="Event not found")
    await broadcast_event({"type": "false_alarm", "event": event})
    return {"ok": True, "event": event}


@router.get("/events/{event_id}/snapshot")
def get_snapshot(event_id: int):
    event = event_service.get_event(event_id)
    if not event or not event.get("snapshot_path"):
        raise HTTPException(status_code=404, detail="Snapshot not found")
    path = settings.media_dir / str(event["snapshot_path"])
    if not path.exists():
        raise HTTPException(status_code=404, detail="Snapshot file missing")
    return FileResponse(path)


@router.get("/events/{event_id}/video")
def get_video(event_id: int):
    event = event_service.get_event(event_id)
    if not event or not event.get("video_path"):
        raise HTTPException(status_code=404, detail="Video not found")
    path = settings.media_dir / str(event["video_path"])
    if not path.exists():
        raise HTTPException(status_code=404, detail="Video file missing")
    return FileResponse(path)


@router.post("/ingest")
async def ingest_detection(payload: DetectionEventIn, snapshot: UploadFile | None = None, video: UploadFile | None = None) -> dict[str, Any]:
    cfg = event_service.get_settings()
    if not cfg.armed:
        return {"ok": False, "message": "System is disarmed"}

    if payload.confidence < cfg.confidence_threshold:
        return {"ok": False, "message": "Below confidence threshold"}

    if not event_service.passes_filter(payload.object_type, cfg.alert_filter):
        return {"ok": False, "message": "Filtered alert type"}

    if not event_service.should_alert(payload.tracking_id, cfg):
        return {"ok": False, "message": "Cooldown or insufficient consecutive frames"}

    snapshot_name = None
    video_name = None
    if snapshot is not None:
        snapshot_name = event_recorder.store_snapshot(snapshot.filename or f"snapshot_{uuid.uuid4().hex}.jpg", await snapshot.read())
    if video is not None and cfg.event_recording_enabled:
        video_name = event_recorder.store_video(video.filename or f"clip_{uuid.uuid4().hex}.webm", await video.read())

    event = event_service.create_event(payload, snapshot_name, video_name)
    marker_key = payload.tracking_id
    marker = {
        "tracking_id": payload.tracking_id,
        "object_type": payload.object_type,
        "severity": payload.severity,
        "distance_m": payload.distance_m,
        "angle_deg": payload.angle_deg,
        "last_seen": datetime.now(timezone.utc).isoformat(),
    }
    if payload.angle_deg is not None:
        marker["has_direction"] = True
    else:
        marker["has_direction"] = False
    _active_markers[marker_key] = marker

    alarm = alarm_service.payload_for_event(event)
    await broadcast_event({"type": "detection", "event": event, "alarm": alarm, "marker": marker})
    event_service.enforce_retention()
    return {"ok": True, "event": event, "alarm": alarm}


@router.post("/frame")
async def process_frame(body: dict[str, Any]) -> dict[str, Any]:
    global _camera_connected
    _camera_connected = True
    cfg = event_service.get_settings()
    if not cfg.armed:
        return {"ok": True, "detections": [], "armed": False}

    image_b64 = str(body.get("image", ""))
    detector = ObjectDetector()
    motion = MotionDetector(cfg.sensitivity)
    frame = detector.decode_frame(image_b64)
    if frame is None:
        _camera_connected = False
        return {"ok": False, "message": "Invalid frame"}

    regions = motion.detect_regions(frame)
    detections = detector.classify_regions(frame, regions)
    created: list[dict[str, Any]] = []
    for idx, det in enumerate(detections):
        if det["confidence"] < cfg.confidence_threshold:
            det["object_type"] = "unknown moving object"
        tracking_id = str(body.get("tracking_id") or f"cam-{idx}-{uuid.uuid4().hex[:8]}")
        distance = body.get("distance_m")
        distance_m = float(distance) if distance is not None else None
        angle = body.get("angle_deg")
        angle_deg = float(angle) if angle is not None else None
        severity = severity_for(det["object_type"], distance_m, cfg.selected_range_m)
        payload = DetectionEventIn(
            tracking_id=tracking_id,
            object_type=det["object_type"],
            confidence=float(det["confidence"]),
            distance_m=distance_m,
            angle_deg=angle_deg,
            sensor_mode="camera",
            camera_name=str(body.get("camera_name", "Webcam")),
            severity=severity,
            metadata={"bbox": det.get("bbox", [])},
        )
        if not event_service.passes_filter(payload.object_type, cfg.alert_filter):
            continue
        if not event_service.should_alert(tracking_id, cfg):
            continue
        event = event_service.create_event(payload)
        created.append(event)
        marker = {
            "tracking_id": tracking_id,
            "object_type": payload.object_type,
            "severity": severity,
            "distance_m": distance_m,
            "angle_deg": angle_deg,
            "has_direction": angle_deg is not None,
            "last_seen": datetime.now(timezone.utc).isoformat(),
        }
        _active_markers[tracking_id] = marker
        await broadcast_event({"type": "detection", "event": event, "marker": marker, "alarm": alarm_service.payload_for_event(event)})

    return {"ok": True, "detections": detections, "events": created}


@router.websocket("/ws/events")
async def ws_events(websocket: WebSocket) -> None:
    await websocket.accept()
    _ws_clients.add(websocket)
    try:
        await websocket.send_json({"type": "status", "payload": _status().model_dump()})
        while True:
            for raw in radar_serial_service.poll_events():
                await process_hardware_motion(raw)
            try:
                import asyncio

                await asyncio.wait_for(websocket.receive_text(), timeout=1.0)
            except asyncio.TimeoutError:
                continue
    except WebSocketDisconnect:
        pass
    finally:
        _ws_clients.discard(websocket)
