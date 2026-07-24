from __future__ import annotations

import json
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator

from backend.config import settings

DB_PATH = Path(__file__).resolve().parents[1] / "data" / "radar.db"


def _utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def init_db() -> None:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    with get_conn() as conn:
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS detection_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tracking_id TEXT,
                object_type TEXT NOT NULL,
                confidence REAL NOT NULL DEFAULT 0,
                distance_m REAL,
                angle_deg REAL,
                speed_mps REAL,
                severity TEXT NOT NULL DEFAULT 'medium',
                detected_at TEXT NOT NULL,
                snapshot_path TEXT,
                video_path TEXT,
                acknowledged INTEGER NOT NULL DEFAULT 0,
                false_alarm INTEGER NOT NULL DEFAULT 0,
                sensor_mode TEXT NOT NULL DEFAULT 'mock',
                camera_name TEXT DEFAULT 'Webcam',
                metadata_json TEXT
            );

            CREATE TABLE IF NOT EXISTS radar_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                armed INTEGER NOT NULL DEFAULT 0,
                selected_range_m REAL NOT NULL DEFAULT 5,
                maximum_sensor_range_m REAL NOT NULL DEFAULT 5,
                sensitivity TEXT NOT NULL DEFAULT 'medium',
                confidence_threshold REAL NOT NULL DEFAULT 0.6,
                alert_filter TEXT NOT NULL DEFAULT 'all',
                alarm_enabled INTEGER NOT NULL DEFAULT 1,
                alarm_volume REAL NOT NULL DEFAULT 0.7,
                event_recording_enabled INTEGER NOT NULL DEFAULT 1,
                pre_event_seconds INTEGER NOT NULL DEFAULT 5,
                post_event_seconds INTEGER NOT NULL DEFAULT 10,
                cooldown_seconds INTEGER NOT NULL DEFAULT 10,
                retention_days INTEGER NOT NULL DEFAULT 7,
                marker_timeout_seconds INTEGER NOT NULL DEFAULT 30,
                consecutive_frames_required INTEGER NOT NULL DEFAULT 3
            );

            INSERT OR IGNORE INTO radar_settings (id) VALUES (1);
            """
        )


@contextmanager
def get_conn() -> Iterator[sqlite3.Connection]:
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def row_to_dict(row: sqlite3.Row | None) -> dict[str, Any] | None:
    if row is None:
        return None
    return dict(row)
