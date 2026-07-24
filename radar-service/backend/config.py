from __future__ import annotations

import os
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

ROOT = Path(__file__).resolve().parents[1]
PROJECT_ROOT = ROOT.parent


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=(PROJECT_ROOT / ".env", ROOT / ".env"),
        env_file_encoding="utf-8",
        extra="ignore",
    )

    radar_mode: str = "mock"
    radar_serial_port: str = "COM3"
    radar_baud_rate: int = 115200
    radar_sensor_profile: str = "MR24HPC1"
    radar_max_range_m: float = 5.0
    radar_default_range_m: float = 5.0
    motion_confidence_threshold: float = 0.60
    motion_cooldown_seconds: int = 10
    pre_event_seconds: int = 5
    post_event_seconds: int = 10
    event_retention_days: int = 7
    event_media_directory: str = "data/events"
    radar_host: str = "127.0.0.1"
    radar_port: int = 8765

    @property
    def media_dir(self) -> Path:
        path = Path(self.event_media_directory)
        if not path.is_absolute():
            path = ROOT / path
        path.mkdir(parents=True, exist_ok=True)
        return path


settings = Settings()
