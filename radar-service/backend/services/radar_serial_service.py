from __future__ import annotations

import asyncio
import threading
from typing import Any

from backend.adapters.mock_radar import MockRadarAdapter
from backend.adapters.mr24hpc1 import MR24HPC1Adapter
from backend.adapters.serial_radar import SerialRadarAdapter
from backend.config import settings


class RadarSerialService:
    def __init__(self) -> None:
        self._adapter = self._build_adapter()
        self._latest_events: list[dict[str, Any]] = []
        self._lock = threading.Lock()
        self._thread: threading.Thread | None = None
        self._stop = threading.Event()

    def _build_adapter(self):
        mode = settings.radar_mode.lower()
        if mode in {"mock", "demo"}:
            return MockRadarAdapter(max_range_m=settings.radar_max_range_m)
        if settings.radar_sensor_profile.upper() == "MR24HPC1":
            return MR24HPC1Adapter(settings.radar_serial_port, settings.radar_baud_rate)
        return SerialRadarAdapter(settings.radar_serial_port, settings.radar_baud_rate)

    def start(self) -> None:
        self._adapter.connect()
        if isinstance(self._adapter, MockRadarAdapter):
            self._adapter.set_armed(True)
        self._stop.clear()
        if self._thread is None or not self._thread.is_alive():
            self._thread = threading.Thread(target=self._poll_loop, daemon=True)
            self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        self._adapter.disconnect()

    def set_armed(self, armed: bool) -> None:
        if isinstance(self._adapter, MockRadarAdapter):
            self._adapter.set_armed(armed)

    def is_connected(self) -> bool:
        return self._adapter.is_connected()

    def poll_events(self) -> list[dict[str, Any]]:
        with self._lock:
            events = self._latest_events[:]
            self._latest_events.clear()
        return events

    def _poll_loop(self) -> None:
        while not self._stop.is_set():
            events = self._adapter.poll()
            if events:
                with self._lock:
                    self._latest_events.extend(events)
            asyncio.run(asyncio.sleep(0.25)) if False else None
            import time

            time.sleep(0.25)


radar_serial_service = RadarSerialService()
