from __future__ import annotations

from typing import Any

from backend.adapters.serial_radar import SerialRadarAdapter


class MR24HPC1Adapter(SerialRadarAdapter):
    """Human-presence / human-motion mmWave profile.

    Does not classify vehicles, animals or other object categories.
    """

    profile = "MR24HPC1"

    def normalize_payload(self, payload: dict[str, Any]) -> dict[str, Any] | None:
        validated = self.validate_payload(payload)
        if not validated:
            return None
        validated["sensor_profile"] = self.profile
        validated["classification_source"] = "radar_presence_only"
        return validated

    def poll(self) -> list[dict[str, Any]]:
        events = super().poll()
        normalized: list[dict[str, Any]] = []
        for event in events:
            item = self.normalize_payload(event)
            if item:
                normalized.append(item)
        return normalized
