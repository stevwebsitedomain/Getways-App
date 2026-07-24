from __future__ import annotations

import base64
import re
from pathlib import Path
from typing import Any

import cv2
import numpy as np

COCO_LABELS = [
    "person",
    "bicycle",
    "car",
    "motorcycle",
    "bus",
    "truck",
    "bird",
    "cat",
    "dog",
]

VEHICLE_TYPES = {"car", "motorcycle", "bus", "truck", "bicycle"}
ANIMAL_TYPES = {"dog", "cat", "bird"}


class MotionDetector:
    def __init__(self, sensitivity: str = "medium") -> None:
        self._bg = cv2.createBackgroundSubtractorMOG2(history=300, varThreshold=self._var_threshold(sensitivity), detectShadows=False)
        self._min_area = {"low": 1800, "medium": 1200, "high": 700}[sensitivity]

    @staticmethod
    def _var_threshold(sensitivity: str) -> int:
        return {"low": 40, "medium": 25, "high": 16}.get(sensitivity, 25)

    def detect_regions(self, frame: np.ndarray) -> list[tuple[int, int, int, int]]:
        mask = self._bg.apply(frame)
        _, thresh = cv2.threshold(mask, 200, 255, cv2.THRESH_BINARY)
        contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        regions: list[tuple[int, int, int, int]] = []
        for contour in contours:
            if cv2.contourArea(contour) < self._min_area:
                continue
            x, y, w, h = cv2.boundingRect(contour)
            regions.append((x, y, w, h))
        return regions


class ObjectDetector:
    def __init__(self) -> None:
        self._net = None
        self._labels = COCO_LABELS

    def _ensure_net(self) -> bool:
        if self._net is not None:
            return True
        model_dir = Path(__file__).resolve().parents[2] / "models"
        proto = model_dir / "MobileNetSSD_deploy.prototxt"
        weights = model_dir / "MobileNetSSD_deploy.caffemodel"
        if proto.exists() and weights.exists():
            self._net = cv2.dnn.readNetFromCaffe(str(proto), str(weights))
            return True
        return False

    def classify_regions(self, frame: np.ndarray, regions: list[tuple[int, int, int, int]]) -> list[dict[str, Any]]:
        results: list[dict[str, Any]] = []
        if not regions:
            return results

        if self._ensure_net():
            h, w = frame.shape[:2]
            for (x, y, rw, rh) in regions:
                blob = cv2.dnn.blobFromImage(frame, 0.007843, (300, 300), 127.5)
                self._net.setInput(blob)
                detections = self._net.forward()
                best_label = "unknown moving object"
                best_conf = 0.0
                for i in range(detections.shape[2]):
                    conf = float(detections[0, 0, i, 2])
                    if conf < 0.35:
                        continue
                    idx = int(detections[0, 0, i, 1])
                    label = self._labels[idx] if 0 <= idx < len(self._labels) else "unknown moving object"
                    if conf > best_conf:
                        best_conf = conf
                        best_label = label
                results.append(
                    {
                        "object_type": best_label,
                        "confidence": round(best_conf if best_conf else 0.45, 3),
                        "bbox": [x, y, rw, rh],
                    }
                )
            return results

        for (x, y, rw, rh) in regions:
            aspect = rh / max(rw, 1)
            label = "unknown moving object"
            conf = 0.42
            if aspect > 1.6:
                label = "person"
                conf = 0.55
            elif aspect < 0.8 and rw > 80:
                label = "car"
                conf = 0.5
            results.append({"object_type": label, "confidence": conf, "bbox": [x, y, rw, rh]})
        return results

    def decode_frame(self, image_b64: str) -> np.ndarray | None:
        if not image_b64:
            return None
        payload = image_b64.split(",", 1)[-1]
        try:
            data = base64.b64decode(payload)
        except (ValueError, TypeError):
            return None
        arr = np.frombuffer(data, dtype=np.uint8)
        frame = cv2.imdecode(arr, cv2.IMREAD_COLOR)
        return frame


def severity_for(object_type: str, distance_m: float | None, selected_range_m: float) -> str:
    if object_type in {"person", "car", "motorcycle", "bus", "truck", "bicycle"}:
        if distance_m is not None and distance_m <= selected_range_m:
            return "high"
        if distance_m is None:
            return "high" if object_type == "person" else "medium"
    if object_type in ANIMAL_TYPES or object_type == "unknown moving object":
        return "medium"
    return "low"
