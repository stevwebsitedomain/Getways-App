from __future__ import annotations

from typing import Any


class AlarmService:
    def __init__(self) -> None:
        self._enabled = True
        self._volume = 0.7

    def configure(self, enabled: bool, volume: float) -> None:
        self._enabled = enabled
        self._volume = max(0.0, min(1.0, volume))

    def payload_for_event(self, event: dict[str, Any]) -> dict[str, Any]:
        return {
            "alarm_enabled": self._enabled,
            "alarm_volume": self._volume,
            "severity": event.get("severity", "medium"),
            "event_id": event.get("id"),
        }


alarm_service = AlarmService()
