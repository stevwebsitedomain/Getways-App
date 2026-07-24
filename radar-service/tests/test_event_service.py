import time

from backend.models.detection_event import DetectionEventIn, RadarSettings
from backend.services.event_service import EventService


def test_duplicate_alert_prevention(tmp_path, monkeypatch):
    from backend import db

    monkeypatch.setattr(db, "DB_PATH", tmp_path / "test.db")
    db.init_db()
    service = EventService()
    settings = service.save_settings(RadarSettings(armed=True, cooldown_seconds=5, consecutive_frames_required=1))

    assert service.should_alert("track-1", settings) is True
    assert service.should_alert("track-1", settings) is False


def test_range_validation_in_settings():
    from backend import db
    from pathlib import Path
    import tempfile

    with tempfile.TemporaryDirectory() as tmp:
        db.DB_PATH = Path(tmp) / "settings.db"
        db.init_db()
        service = EventService()
        saved = service.save_settings(
            RadarSettings(selected_range_m=8, maximum_sensor_range_m=5, armed=False)
        )
        assert saved.selected_range_m <= saved.maximum_sensor_range_m
