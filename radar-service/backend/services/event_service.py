from __future__ import annotations

import json
import re
import time
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

from backend.config import settings
from backend.db import get_conn, row_to_dict
from backend.models.detection_event import DetectionEventIn, RadarSettings
from backend.services.object_detector import ANIMAL_TYPES, VEHICLE_TYPES, severity_for


class EventService:
    def __init__(self) -> None:
        self._track_state: dict[str, dict[str, Any]] = {}
        self._cooldown_until: dict[str, float] = {}

    def get_settings(self) -> RadarSettings:
        with get_conn() as conn:
            row = conn.execute("SELECT * FROM radar_settings WHERE id = 1").fetchone()
        data = row_to_dict(row) or {}
        return RadarSettings(
            armed=bool(data.get("armed")),
            selected_range_m=float(data.get("selected_range_m", settings.radar_default_range_m)),
            maximum_sensor_range_m=float(data.get("maximum_sensor_range_m", settings.radar_max_range_m)),
            sensitivity=data.get("sensitivity", "medium"),
            confidence_threshold=float(data.get("confidence_threshold", settings.motion_confidence_threshold)),
            alert_filter=data.get("alert_filter", "all"),
            alarm_enabled=bool(data.get("alarm_enabled", True)),
            alarm_volume=float(data.get("alarm_volume", 0.7)),
            event_recording_enabled=bool(data.get("event_recording_enabled", True)),
            pre_event_seconds=int(data.get("pre_event_seconds", settings.pre_event_seconds)),
            post_event_seconds=int(data.get("post_event_seconds", settings.post_event_seconds)),
            cooldown_seconds=int(data.get("cooldown_seconds", settings.motion_cooldown_seconds)),
            retention_days=int(data.get("retention_days", settings.event_retention_days)),
            marker_timeout_seconds=int(data.get("marker_timeout_seconds", 30)),
            consecutive_frames_required=int(data.get("consecutive_frames_required", 3)),
        )

    def save_settings(self, payload: RadarSettings) -> RadarSettings:
        with get_conn() as conn:
            conn.execute(
                """
                UPDATE radar_settings SET
                    armed = ?, selected_range_m = ?, maximum_sensor_range_m = ?,
                    sensitivity = ?, confidence_threshold = ?, alert_filter = ?,
                    alarm_enabled = ?, alarm_volume = ?, event_recording_enabled = ?,
                    pre_event_seconds = ?, post_event_seconds = ?, cooldown_seconds = ?,
                    retention_days = ?, marker_timeout_seconds = ?, consecutive_frames_required = ?
                WHERE id = 1
                """,
                (
                    int(payload.armed),
                    min(payload.selected_range_m, payload.maximum_sensor_range_m),
                    payload.maximum_sensor_range_m,
                    payload.sensitivity,
                    payload.confidence_threshold,
                    payload.alert_filter,
                    int(payload.alarm_enabled),
                    payload.alarm_volume,
                    int(payload.event_recording_enabled),
                    payload.pre_event_seconds,
                    payload.post_event_seconds,
                    payload.cooldown_seconds,
                    payload.retention_days,
                    payload.marker_timeout_seconds,
                    payload.consecutive_frames_required,
                ),
            )
        return self.get_settings()

    def set_armed(self, armed: bool) -> RadarSettings:
        current = self.get_settings()
        current.armed = armed
        return self.save_settings(current)

    def passes_filter(self, object_type: str, alert_filter: str) -> bool:
        if alert_filter == "all":
            return True
        if alert_filter == "people":
            return object_type == "person"
        if alert_filter == "vehicles":
            return object_type in VEHICLE_TYPES
        if alert_filter == "animals":
            return object_type in ANIMAL_TYPES
        if alert_filter == "unknown":
            return object_type == "unknown moving object"
        return True

    def should_alert(self, tracking_id: str, settings_obj: RadarSettings) -> bool:
        now = time.time()
        until = self._cooldown_until.get(tracking_id, 0)
        if now < until:
            return False
        state = self._track_state.setdefault(tracking_id, {"frames": 0})
        state["frames"] += 1
        if state["frames"] < settings_obj.consecutive_frames_required:
            return False
        self._cooldown_until[tracking_id] = now + settings_obj.cooldown_seconds
        state["frames"] = 0
        return True

    def create_event(self, payload: DetectionEventIn, snapshot_name: str | None = None, video_name: str | None = None) -> dict[str, Any]:
        detected_at = datetime.now(timezone.utc).isoformat()
        with get_conn() as conn:
            cur = conn.execute(
                """
                INSERT INTO detection_events (
                    tracking_id, object_type, confidence, distance_m, angle_deg, speed_mps,
                    severity, detected_at, snapshot_path, video_path, sensor_mode, camera_name, metadata_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    payload.tracking_id,
                    payload.object_type,
                    payload.confidence,
                    payload.distance_m,
                    payload.angle_deg,
                    payload.speed_mps,
                    payload.severity,
                    detected_at,
                    snapshot_name,
                    video_name,
                    payload.sensor_mode,
                    payload.camera_name,
                    json.dumps(payload.metadata),
                ),
            )
            event_id = int(cur.lastrowid)
            row = conn.execute("SELECT * FROM detection_events WHERE id = ?", (event_id,)).fetchone()
        return self._serialize(row_to_dict(row) or {})

    def list_events(self, filters: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        filters = filters or {}
        clauses = ["1=1"]
        params: list[Any] = []
        if filters.get("category"):
            clauses.append("object_type = ?")
            params.append(filters["category"])
        if filters.get("severity"):
            clauses.append("severity = ?")
            params.append(filters["severity"])
        if filters.get("acknowledged") is not None:
            clauses.append("acknowledged = ?")
            params.append(1 if filters["acknowledged"] else 0)
        if filters.get("false_alarm") is not None:
            clauses.append("false_alarm = ?")
            params.append(1 if filters["false_alarm"] else 0)
        if filters.get("date"):
            clauses.append("date(detected_at) = date(?)")
            params.append(filters["date"])
        query = f"SELECT * FROM detection_events WHERE {' AND '.join(clauses)} ORDER BY detected_at DESC LIMIT 250"
        with get_conn() as conn:
            rows = conn.execute(query, params).fetchall()
        return [self._serialize(row_to_dict(row) or {}) for row in rows]

    def get_event(self, event_id: int) -> dict[str, Any] | None:
        with get_conn() as conn:
            row = conn.execute("SELECT * FROM detection_events WHERE id = ?", (event_id,)).fetchone()
        data = row_to_dict(row)
        return self._serialize(data) if data else None

    def acknowledge(self, event_id: int) -> dict[str, Any] | None:
        with get_conn() as conn:
            conn.execute("UPDATE detection_events SET acknowledged = 1 WHERE id = ?", (event_id,))
        return self.get_event(event_id)

    def false_alarm(self, event_id: int) -> dict[str, Any] | None:
        with get_conn() as conn:
            conn.execute("UPDATE detection_events SET false_alarm = 1, acknowledged = 1 WHERE id = ?", (event_id,))
        return self.get_event(event_id)

    def enforce_retention(self) -> None:
        cfg = self.get_settings()
        cutoff = datetime.now(timezone.utc) - timedelta(days=cfg.retention_days)
        with get_conn() as conn:
            rows = conn.execute(
                "SELECT id, snapshot_path, video_path FROM detection_events WHERE detected_at < ?",
                (cutoff.isoformat(),),
            ).fetchall()
            for row in rows:
                for key in ("snapshot_path", "video_path"):
                    rel = row[key]
                    if rel:
                        path = settings.media_dir / Path(str(rel)).name
                        if path.exists():
                            path.unlink(missing_ok=True)
            conn.execute("DELETE FROM detection_events WHERE detected_at < ?", (cutoff.isoformat(),))

    def save_media(self, filename: str, content: bytes) -> str:
        safe = re_filename_safe(filename)
        path = settings.media_dir / safe
        path.write_bytes(content)
        return safe

    def _serialize(self, row: dict[str, Any]) -> dict[str, Any]:
        return {
            "id": row["id"],
            "tracking_id": row.get("tracking_id"),
            "object_type": row.get("object_type"),
            "confidence": row.get("confidence"),
            "distance_m": row.get("distance_m"),
            "angle_deg": row.get("angle_deg"),
            "speed_mps": row.get("speed_mps"),
            "severity": row.get("severity"),
            "detected_at": row.get("detected_at"),
            "snapshot_path": row.get("snapshot_path"),
            "video_path": row.get("video_path"),
            "acknowledged": bool(row.get("acknowledged")),
            "false_alarm": bool(row.get("false_alarm")),
            "sensor_mode": row.get("sensor_mode"),
            "camera_name": row.get("camera_name"),
            "metadata": json.loads(row.get("metadata_json") or "{}"),
        }


def re_filename_safe(name: str) -> str:
    base = Path(name).name
    cleaned = "".join(ch if ch.isalnum() or ch in "._-" else "_" for ch in base)
    return cleaned or f"event_{uuid.uuid4().hex}.bin"


event_service = EventService()
