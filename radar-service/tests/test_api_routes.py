from fastapi.testclient import TestClient

from backend import db
from main import app


def test_status_route(tmp_path, monkeypatch):
    monkeypatch.setattr("backend.db.DB_PATH", tmp_path / "api.db")
    db.init_db()
    client = TestClient(app)
    res = client.get("/api/radar/status")
    assert res.status_code == 200
    body = res.json()
    assert body["ok"] is True
    assert body["system_status"] in {"armed", "disarmed", "alert", "offline"}


def test_arm_disarm(tmp_path, monkeypatch):
    monkeypatch.setattr("backend.db.DB_PATH", tmp_path / "arm.db")
    db.init_db()
    client = TestClient(app)
    res = client.post("/api/radar/arm")
    assert res.status_code == 200
    assert res.json()["settings"]["armed"] is True
    res = client.post("/api/radar/disarm")
    assert res.json()["settings"]["armed"] is False
