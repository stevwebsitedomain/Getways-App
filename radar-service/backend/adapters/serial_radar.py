from __future__ import annotations

import json
from typing import Any

from backend.adapters.base_radar import BaseRadarAdapter


class SerialRadarAdapter(BaseRadarAdapter):
    profile = "serial"

    def __init__(self, port: str, baud_rate: int = 115200) -> None:
        self._port = port
        self._baud_rate = baud_rate
        self._serial = None

    def connect(self) -> bool:
        try:
            import serial

            self._serial = serial.Serial(self._port, self._baud_rate, timeout=0.2)
            return True
        except Exception:
            self._serial = None
            return False

    def disconnect(self) -> None:
        if self._serial is not None:
            try:
                self._serial.close()
            except Exception:
                pass
        self._serial = None

    def is_connected(self) -> bool:
        return self._serial is not None and getattr(self._serial, "is_open", False)

    def poll(self) -> list[dict[str, Any]]:
        if not self.is_connected():
            return []
        try:
            raw = self._serial.readline().decode("utf-8", errors="ignore").strip()
        except Exception:
            return []
        if not raw:
            return []
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            return []
        validated = self.validate_payload(payload)
        return [validated] if validated else []
