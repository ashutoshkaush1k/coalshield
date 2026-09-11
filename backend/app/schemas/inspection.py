"""Inspection prioritisation schemas (PRD 4.1, Government view)."""

from pydantic import BaseModel

from app.schemas.compliance import ComplianceOut


class TrendOut(BaseModel):
    """Event counts across two adjacent windows, so the UI can show direction, not just a number."""

    window_hours: int
    recent_violations: int
    recent_breaches: int
    recent_events: int
    previous_events: int
    delta: int
    direction: str


class InspectionCandidateOut(BaseModel):
    rank: int
    mine_id: int
    code: str
    name: str
    location: str
    region: str
    urgency: float
    severity: float
    compliance: ComplianceOut
    trend: TrendOut
    reasons: list[str]


class InspectionQueueOut(BaseModel):
    """The ranked queue plus the weight that produced it, so a ranking can be reproduced."""

    generated_for: str
    weight_trend: float
    trend_window_hours: int
    mine_count: int
    candidates: list[InspectionCandidateOut]
