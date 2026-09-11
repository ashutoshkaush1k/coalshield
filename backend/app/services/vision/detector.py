"""Loads the pretrained YOLO PPE model once and runs inference on a frame. PRD 8: no custom training.

Two backends implement the same interface:

  YoloDetector    - real inference via ultralytics, used whenever weights are present.
  FixtureDetector - reads detections from a JSON sidecar next to the image.

FixtureDetector is a test double, not a model. It exists so the ingest -> violation -> score ->
dashboard path can be exercised and tested on a machine with no weights and no GPU. It never
invents detections: if there is no sidecar file, it returns nothing.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

from app.core.config import BACKEND_DIR, settings
from app.services.vision.ppe_rules import BBox, normalise_label

SAMPLES_IMAGE_DIR = BACKEND_DIR / "data" / "samples" / "images"


class WeightsNotFoundError(FileNotFoundError):
    """Raised when the configured YOLO weights are absent."""


@dataclass(frozen=True)
class Detection:
    """One detected object. `label` is canonical; `raw_label` is what the model actually said."""

    label: str | None
    raw_label: str
    confidence: float
    bbox: BBox
    frame_index: int = 0


def resolve_weights_path(path: str | None = None) -> Path:
    """Absolute path to the weights, resolved against the backend directory when relative."""
    candidate = Path(path or settings.yolo_weights_path)
    return candidate if candidate.is_absolute() else (BACKEND_DIR / candidate)


class YoloDetector:
    """Ultralytics YOLO wrapper. The model is loaded once and reused across frames."""

    backend = "yolo"

    def __init__(self, weights: str | Path | None = None, confidence: float | None = None):
        self.weights_path = resolve_weights_path(str(weights) if weights else None)
        if not self.weights_path.exists():
            raise WeightsNotFoundError(
                f"YOLO weights not found at {self.weights_path}. "
                "Set YOLO_WEIGHTS_PATH in backend/.env, or see backend/ml/README.md."
            )
        self.confidence = settings.detection_confidence if confidence is None else confidence
        # Imported lazily: ultralytics pulls in torch, which is slow to import and not needed by
        # any other part of the API.
        from ultralytics import YOLO

        self._model = YOLO(str(self.weights_path))

    @property
    def class_names(self) -> list[str]:
        names = getattr(self._model, "names", {}) or {}
        return list(names.values()) if isinstance(names, dict) else list(names)

    def detect(self, source, frame_index: int = 0) -> list[Detection]:
        """Run inference on an image path or a numpy frame."""
        results = self._model.predict(source, conf=self.confidence, verbose=False)
        detections: list[Detection] = []
        for result in results:
            names = result.names
            for box in result.boxes:
                raw = str(names[int(box.cls[0])])
                x1, y1, x2, y2 = (float(v) for v in box.xyxy[0])
                detections.append(
                    Detection(
                        label=normalise_label(raw),
                        raw_label=raw,
                        confidence=float(box.conf[0]),
                        bbox=(x1, y1, x2, y2),
                        frame_index=frame_index,
                    )
                )
        return detections


class FixtureDetector:
    """Reads detections from `<image>.detections.json`.

    Sidecar format - a list of objects, bbox as [x1, y1, x2, y2]:

        [{"label": "NO-Hardhat", "confidence": 0.91, "bbox": [120, 40, 190, 120]}]

    Labels go through the same normalisation as real model output, so a fixture exercises the
    identical code path a model would.
    """

    backend = "fixture"

    def __init__(self, confidence: float | None = None):
        self.confidence = settings.detection_confidence if confidence is None else confidence

    @property
    def class_names(self) -> list[str]:
        return []

    @staticmethod
    def sidecar_for(source) -> Path:
        path = Path(str(source))
        return path.with_suffix(path.suffix + ".detections.json")

    @classmethod
    def resolve_sidecar(cls, source) -> Path | None:
        """Find the sidecar for a file, including one that has been through an upload rename.

        save_upload stores "ppe_sample.jpg" as "ppe_sample_a1b2c3d4.jpg", so the second candidate
        strips that id and looks in the samples directory. Fixture-only behaviour: a real model
        reads pixels and never needs this.
        """
        from app.utils.files import UPLOAD_ID_PATTERN

        path = Path(str(source))
        direct = cls.sidecar_for(path)
        if direct.exists():
            return direct

        original_stem = UPLOAD_ID_PATTERN.sub("", path.stem)
        if original_stem != path.stem:
            fallback = SAMPLES_IMAGE_DIR / f"{original_stem}{path.suffix}.detections.json"
            if fallback.exists():
                return fallback
        return None

    def detect(self, source, frame_index: int = 0) -> list[Detection]:
        sidecar = self.resolve_sidecar(source)
        if sidecar is None:
            return []
        payload = json.loads(sidecar.read_text(encoding="utf-8"))
        return [
            Detection(
                label=normalise_label(item["label"]),
                raw_label=item["label"],
                confidence=float(item.get("confidence", 1.0)),
                bbox=tuple(float(v) for v in item["bbox"]),
                frame_index=frame_index,
            )
            for item in payload
            if float(item.get("confidence", 1.0)) >= self.confidence
        ]


def build_detector(prefer_fixture: bool = False):
    """Return the best available detector.

    Falls back to the fixture backend when no weights are installed, so the pipeline degrades to
    something testable instead of failing at import time. Callers that need real inference should
    check `.backend`.
    """
    if prefer_fixture:
        return FixtureDetector()
    try:
        return YoloDetector()
    except WeightsNotFoundError:
        return FixtureDetector()


@lru_cache(maxsize=1)
def get_detector():
    """Process-wide detector. Cached because loading YOLO weights takes seconds."""
    return build_detector()
