"""Violation read schema and CV detection result schema."""

from pydantic import BaseModel

from app.schemas.compliance import ComplianceOut
from app.utils.datetimes import UTCDateTime


class ViolationOut(BaseModel):
    id: int
    mine_id: int
    violation_type: str
    confidence: float
    source: str
    frame_ref: str
    detected_at: UTCDateTime
    # Resolved violations stay in the log; they simply stop counting against the score.
    resolved: bool = False
    resolved_at: UTCDateTime | None = None

    model_config = {"from_attributes": True}


class DetectionOut(BaseModel):
    raw_label: str
    label: str | None
    confidence: float
    bbox: tuple[float, float, float, float]


class VisionAnalysisOut(BaseModel):
    """What one CV run produced, including the score either side of it.

    Returning both scores lets the dashboard animate the drop instead of silently re-fetching.
    """

    mine_id: int
    backend: str
    frames_processed: int
    detections: list[DetectionOut]
    violations: list[ViolationOut]
    alerts_raised: int
    score_before: ComplianceOut
    score_after: ComplianceOut
    score_delta: float
    risk_changed: bool
    annotated_url: str | None

    # A clean re-inspection clears open violations, so the caller can show a recovery
    # rather than only ever a drop.
    resolved_count: int = 0
    resolution_accepted: bool = False
    resolution_reason: str = ""
