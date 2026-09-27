"""CoalShield ai-service: stateless AI only (brief section 2). Phase 2 scope: PPE vision.

POST /vision/ppe   one image (or a short video) -> detections, PPE violation candidates, whether
                   the frame is usable evidence of compliance, and the annotated frame.
GET  /health       liveness.

It never touches users, permissions or the database: the Yii2 API sends the bytes, then stores
violations, alerts and scores itself. The detection code is imported in place from
backend/app/services/vision (PLAN Q13); Phase 7 moves it here and adds the other endpoints.
With backend/ml/weights/ppe.pt (built by scripts/build_ppe_model.py, docs/AI_EVALUATION.md) the
real YOLO model runs. Without it - or with PPE_DETECTOR=fixture - the FixtureDetector answers from
sidecar files next to the sample images, so tests and a weightless machine still work.

Run:  backend\\.venv\\Scripts\\python -m uvicorn main:app --app-dir ai-service --port 8001
"""

from __future__ import annotations

import base64
import os
import re
import sys
import tempfile
from pathlib import Path
from uuid import uuid4

from fastapi import FastAPI, File, Form, HTTPException, UploadFile

BACKEND_DIR = Path(__file__).resolve().parents[1] / "backend"
sys.path.insert(0, str(BACKEND_DIR))

from app.services.compliance.resolution import EVIDENCE_LABELS  # noqa: E402
from app.services.vision.annotate import annotate_image  # noqa: E402
from app.services.vision.detector import FixtureDetector, get_detector  # noqa: E402
from app.services.vision.ppe_rules import (  # noqa: E402
    PpePolicy,
    model_names_have_negatives,
    violations_from_detections,
)

IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}
VIDEO_SUFFIXES = {".mp4", ".avi", ".mov", ".mkv"}
MAX_BYTES = 50 * 1024 * 1024
_UNSAFE = re.compile(r"[^A-Za-z0-9._-]+")

app = FastAPI(title="CoalShield ai-service", version="0.3.0")


def detector():
    """YOLO when backend/ml/weights/ppe.pt exists (scripts/build_ppe_model.py), else the fixture.

    PPE_DETECTOR=fixture forces the fixture backend - the API tests use it for exact numbers.
    """
    if os.environ.get("PPE_DETECTOR", "").lower() == "fixture":
        return _FIXTURE
    return get_detector()


_FIXTURE = FixtureDetector()


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "backend": detector().backend}


def _evidence(detections, candidates) -> dict:
    """Is this run usable evidence that the site is compliant? Same rule as the prototype:
    no violations AND a worker or worn PPE actually seen (an empty corridor proves nothing)."""
    if candidates:
        return {"accepted": False, "code": "VIOLATIONS_DETECTED", "params": {"count": len(candidates)}}
    seen = sorted({d.label for d in detections if d.label} & EVIDENCE_LABELS)
    if not seen:
        return {"accepted": False, "code": "NO_WORKERS_SEEN", "params": {}}
    return {"accepted": True, "code": "CLEAN_FRAME", "params": {"labels": seen}}


@app.post("/vision/ppe")
async def vision_ppe(file: UploadFile = File(...), filename: str = Form(default="")) -> dict:
    original = filename or file.filename or "upload.jpg"
    suffix = Path(original).suffix.lower()
    if suffix not in IMAGE_SUFFIXES | VIDEO_SUFFIXES:
        raise HTTPException(422, {"code": "UNSUPPORTED_MEDIA", "params": {"suffix": suffix}})
    data = await file.read()
    if not data or len(data) > MAX_BYTES:
        raise HTTPException(422, {"code": "FILE_EMPTY" if not data else "FILE_TOO_LARGE", "params": {}})

    model = detector()
    policy = PpePolicy.from_settings()
    infer = not model_names_have_negatives(model.class_names)

    with tempfile.TemporaryDirectory() as tmp:
        # "<stem>_<8 hex><ext>": the fixture detector maps this back to the sample's sidecar.
        stem = _UNSAFE.sub("-", Path(original).stem).strip("-.")[:48] or "upload"
        path = Path(tmp) / f"{stem}_{uuid4().hex[:8]}{suffix}"
        path.write_bytes(data)

        if suffix in VIDEO_SUFFIXES:
            from app.services.vision.video_pipeline import deduplicate, sample_frames

            detections, candidates, frames = [], [], 0
            for index, frame in sample_frames(path, 15, 20):
                found = model.detect(frame, frame_index=index)
                detections.extend(found)
                candidates.extend(violations_from_detections(found, policy=policy, infer=infer))
                frames += 1
            candidates = deduplicate(candidates)
            annotated = None
        else:
            detections = model.detect(path)
            candidates = violations_from_detections(detections, policy=policy, infer=infer)
            frames = 1
            annotated_path = annotate_image(path, detections, candidates)
            annotated = annotated_path.read_bytes() if annotated_path else None
            if annotated_path:
                annotated_path.unlink(missing_ok=True)

    return {
        "backend": model.backend,
        "frames_processed": frames,
        "detections": [
            {"raw_label": d.raw_label, "label": d.label, "confidence": round(d.confidence, 3),
             "bbox": list(d.bbox)}
            for d in detections
        ],
        "violations": [
            {"violation_type": c.violation_type, "confidence": round(c.confidence, 3),
             "basis": c.basis, "bbox": list(c.bbox)}
            for c in candidates
        ],
        "evidence": _evidence(detections, candidates),
        "annotated_jpeg_b64": base64.b64encode(annotated).decode("ascii") if annotated else None,
    }
