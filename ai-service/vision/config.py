"""The few settings the PPE code reads, from the environment (the same names and defaults as the
prototype's backend/.env): YOLO_WEIGHTS_PATH, DETECTION_CONFIDENCE, REQUIRED_PPE."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

# The weights and sample images were built under backend/ (scripts/build_ppe_model.py) and stay there.
BACKEND_DIR = Path(__file__).resolve().parents[2] / "backend"


@dataclass(frozen=True)
class Settings:
    yolo_weights_path: str = os.environ.get("YOLO_WEIGHTS_PATH", "ml/weights/ppe.pt")
    detection_confidence: float = float(os.environ.get("DETECTION_CONFIDENCE", "0.45"))
    required_ppe: str = os.environ.get("REQUIRED_PPE", "helmet,vest")


settings = Settings()
