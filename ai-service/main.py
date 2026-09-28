"""CoalShield ai-service: stateless AI only (brief section 2).

POST /vision/ppe          one image (or a short video) -> detections, PPE violation candidates, whether
                          the frame is usable evidence of compliance, and the annotated frame
POST /anomaly/{detector}  one of the seven anomaly detectors (detectors/): payload -> flags
GET  /anomaly             the detectors and their version
POST /risk/predict        the predictive model (risk/): features per mine -> probability and top factors
GET  /risk/model          the model card: training data, split, metrics
GET  /health              liveness, the PPE backend, the model version

It never touches users, permissions or the database: the Yii2 API sends the data, then stores
violations, alerts, flags and predictions itself - and runs the PHP twins of the detectors and
of the model when this service is down (Phase 7). With ai-service/ml/weights/ppe.pt (built by
scripts/build_ppe_model.py, docs/AI_EVALUATION.md) the real YOLO model runs; without it - or with
PPE_DETECTOR=fixture - the FixtureDetector answers from sidecar files next to the sample images.

Run:  ai-service\.venv\Scripts\python -m uvicorn main:app --app-dir ai-service --port 8001
      (ai-service\run_ai_service.bat)
"""

from __future__ import annotations

import base64
import os
import re
import tempfile
from pathlib import Path
from uuid import uuid4

from fastapi import Body, FastAPI, File, Form, HTTPException, UploadFile

from detectors import DETECTORS, VERSION as DETECTORS_VERSION
from risk import model as risk_model
from vision.annotate import annotate_image
from vision.detector import FixtureDetector, get_detector
from vision.ppe_rules import (
    VIOLATION_FOR_ABSENT_PPE,
    PpePolicy,
    model_names_have_negatives,
    violations_from_detections,
)

# What counts as evidence that the frame shows people at work (was compliance/resolution.py).
EVIDENCE_LABELS = {"person", *VIOLATION_FOR_ABSENT_PPE.keys()}

IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}
VIDEO_SUFFIXES = {".mp4", ".avi", ".mov", ".mkv"}
MAX_BYTES = 50 * 1024 * 1024
_UNSAFE = re.compile(r"[^A-Za-z0-9._-]+")

app = FastAPI(title="CoalShield ai-service", version="0.4.0")


def detector():
    """YOLO when ai-service/ml/weights/ppe.pt exists (scripts/build_ppe_model.py), else the fixture.

    PPE_DETECTOR=fixture forces the fixture backend - the API tests use it for exact numbers.
    """
    if os.environ.get("PPE_DETECTOR", "").lower() == "fixture":
        return _FIXTURE
    return get_detector()


_FIXTURE = FixtureDetector()


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "backend": detector().backend, "detectors": DETECTORS_VERSION,
            "risk_model": risk_model.version()}


@app.get("/anomaly")
def anomaly_list() -> dict:
    return {"version": DETECTORS_VERSION, "detectors": sorted(DETECTORS)}


@app.post("/anomaly/{name}")
def anomaly(name: str, payload: dict = Body(...)) -> dict:
    if name not in DETECTORS:
        raise HTTPException(404, {"code": "UNKNOWN_DETECTOR", "params": {"name": name}})
    try:
        flags = DETECTORS[name](payload)
    except (KeyError, TypeError, ValueError) as e:
        raise HTTPException(422, {"code": "INVALID_PAYLOAD", "params": {"detail": str(e)[:200]}}) from e
    return {"detector": name, "engine": "ai-service", "version": DETECTORS_VERSION, "flags": flags}


@app.get("/risk/model")
def risk_card() -> dict:
    return risk_model.card()


@app.post("/risk/predict")
def risk_predict(payload: dict = Body(...)) -> dict:
    try:
        return risk_model.predict(payload.get("mines", []))
    except FileNotFoundError as e:
        raise HTTPException(503, {"code": "MODEL_MISSING", "params": {}}) from e


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
            from vision.video_pipeline import deduplicate, sample_frames

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
