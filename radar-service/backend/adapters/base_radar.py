from __future__ import annotations

import json
from abc import ABC, abstractmethod
from datetime import datetime, timezone
from typing import Any


class BaseRadarAdapter(ABC):
  profile: str = "generic"

  @abstractmethod
  def connect(self) -> bool:
    raise NotImplementedError

  @abstractmethod
  def disconnect(self) -> None:
    raise NotImplementedError

  @abstractmethod
  def is_connected(self) -> bool:
    raise NotImplementedError

  @abstractmethod
  def poll(self) -> list[dict[str, Any]]:
    raise NotImplementedError

  def validate_payload(self, payload: dict[str, Any]) -> dict[str, Any] | None:
    if not isinstance(payload, dict):
      return None
    event = str(payload.get("event", "")).strip().lower()
    if event not in {"motion", "presence", "heartbeat", "offline"}:
      return None

    result: dict[str, Any] = {"event": event, "timestamp": payload.get("timestamp") or datetime.now(timezone.utc).isoformat()}

    for key in ("distance_m", "angle_deg", "speed_mps", "energy"):
      if key in payload and payload[key] is not None:
        try:
          result[key] = float(payload[key])
        except (TypeError, ValueError):
          return None

    if "distance_m" in result and result["distance_m"] < 0:
      return None
    if "angle_deg" in result and not -180 <= result["angle_deg"] <= 180:
      return None

    return result
