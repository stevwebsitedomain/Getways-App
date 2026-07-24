from __future__ import annotations

import json
import random
import threading
import time
from datetime import datetime, timezone
from typing import Any

from backend.adapters.base_radar import BaseRadarAdapter


class MockRadarAdapter(BaseRadarAdapter):
    profile = "mock"

    def __init__(self, max_range_m: float = 5.0) -> None:
        self._max_range = max_range_m
        self._connected = False
        self._armed = False
        self._lock = threading.Lock()
        self._queue: list[dict[str, Any]] = []
        self._thread: threading.Thread | None = None
        self._stop = threading.Event()

    def connect(self) -> bool:
        self._connected = True
        self._stop.clear()
        if self._thread is None or not self._thread.is_alive():
            self._thread = threading.Thread(target=self._run, daemon=True)
            self._thread.start()
        return True

    def disconnect(self) -> None:
        self._connected = False
        self._stop.set()

    def is_connected(self) -> bool:
        return self._connected

    def set_armed(self, armed: bool) -> None:
        with self._lock:
            self._armed = armed

    def inject_event(self, payload: dict[str, Any]) -> None:
        validated = self.validate_payload(payload)
        if validated:
            with self._lock:
                self._queue.append(validated)

    def poll(self) -> list[dict[str, Any]]:
        with self._lock:
            items = self._queue[:]
            self._queue.clear()
        return items

    def _run(self) -> None:
        while not self._stop.is_set():
            time.sleep(4.5)
            with self._lock:
                armed = self._armed
            if not armed or not self._connected:
                continue
            if random.random() > 0.55:
                continue
            distance = round(random.uniform(1.2, self._max_range), 2)
            angle = round(random.uniform(-60, 60), 1) if random.random() > 0.25 else None
            self.inject_event(
                {
                    "event": "motion",
                    "distance_m": distance,
                    "angle_deg": angle,
                    "speed_mps": round(random.uniform(0.1, 1.4), 2),
                    "energy": random.randint(35, 95),
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }
            )
