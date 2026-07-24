from __future__ import annotations

from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, Field, field_validator

Severity = Literal["low", "medium", "high"]
SensorMode = Literal["camera", "hardware", "mock", "demo"]
AlertFilter = Literal["all", "people", "vehicles", "animals", "unknown"]
Sensitivity = Literal["low", "medium", "high"]


class RadarHardwareEvent(BaseModel):
    event: str
    distance_m: float | None = None
    angle_deg: float | None = None
    speed_mps: float | None = None
    energy: float | None = None
    timestamp: str | None = None

    @field_validator("event")
    @classmethod
    def validate_event(cls, value: str) -> str:
        allowed = {"motion", "presence", "heartbeat", "offline"}
        if value not in allowed:
            raise ValueError(f"Unsupported event type: {value}")
        return value


class DetectionEventIn(BaseModel):
    tracking_id: str
    object_type: str
    confidence: float = Field(ge=0, le=1)
    distance_m: float | None = None
    angle_deg: float | None = None
    speed_mps: float | None = None
    severity: Severity = "medium"
    sensor_mode: SensorMode = "camera"
    camera_name: str = "Webcam"
    metadata: dict[str, Any] = Field(default_factory=dict)


class DetectionEventOut(DetectionEventIn):
    id: int
    detected_at: str
    snapshot_path: str | None = None
    video_path: str | None = None
    acknowledged: bool = False
    false_alarm: bool = False


class RadarSettings(BaseModel):
    armed: bool = False
    selected_range_m: float = 5.0
    maximum_sensor_range_m: float = 5.0
    sensitivity: Sensitivity = "medium"
    confidence_threshold: float = 0.6
    alert_filter: AlertFilter = "all"
    alarm_enabled: bool = True
    alarm_volume: float = 0.7
    event_recording_enabled: bool = True
    pre_event_seconds: int = 5
    post_event_seconds: int = 10
    cooldown_seconds: int = 10
    retention_days: int = 7
    marker_timeout_seconds: int = 30
    consecutive_frames_required: int = 3


class RadarStatus(BaseModel):
    system_status: Literal["armed", "disarmed", "alert", "offline"] = "disarmed"
    operating_mode: SensorMode = "mock"
    demo_mode: bool = True
    camera_connected: bool = False
    radar_connected: bool = False
    server_time: str = Field(default_factory=lambda: datetime.utcnow().isoformat() + "Z")
    active_markers: int = 0
    privacy_notice: str = (
        "Recordings are stored locally. Disable recording or disarm when privacy is required."
    )
