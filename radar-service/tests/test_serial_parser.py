import json

from backend.adapters.mock_radar import MockRadarAdapter


def test_serial_message_parser_accepts_valid_payload():
    adapter = MockRadarAdapter()
    payload = {
        "event": "motion",
        "distance_m": 2.8,
        "angle_deg": 35,
        "speed_mps": 0.7,
        "energy": 64,
        "timestamp": "2026-07-24T10:42:18Z",
    }
    result = adapter.validate_payload(payload)
    assert result is not None
    assert result["distance_m"] == 2.8
    assert result["angle_deg"] == 35


def test_serial_message_parser_rejects_invalid_angle():
    adapter = MockRadarAdapter()
    payload = {"event": "motion", "angle_deg": 400}
    assert adapter.validate_payload(payload) is None


def test_mock_radar_generates_queueable_events():
    from backend.adapters.mock_radar import MockRadarAdapter

    radar = MockRadarAdapter(max_range_m=5)
    radar.connect()
    radar.set_armed(True)
    radar.inject_event(
        {
            "event": "motion",
            "distance_m": 1.5,
            "timestamp": "2026-07-24T10:42:18Z",
        }
    )
    events = radar.poll()
    assert len(events) == 1
    assert events[0]["distance_m"] == 1.5
