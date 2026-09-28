"""The few settings the PPE code reads, from the environment (the same names and defaults as the
removed FastAPI prototype): YOLO_WEIGHTS_PATH, DETECTION_CONFIDENCE, REQUIRED_PPE."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

# The service's own folder: the weights (ml/weights, gitignored, scripts/build_ppe_model.py), the sample
# images (samples/) and the annotated frames (storage/annotated, gitignored) live under it.
SERVICE_DIR = Path(__file__).resolve().parents[1]


@dataclass(frozen=True)
class Settings:
    yolo_weights_path: str = os.environ.get("YOLO_WEIGHTS_PATH", "ml/weights/ppe.pt")
    detection_confidence: float = float(os.environ.get("DETECTION_CONFIDENCE", "0.45"))
    required_ppe: str = os.environ.get("REQUIRED_PPE", "helmet,vest")


settings = Settings()
