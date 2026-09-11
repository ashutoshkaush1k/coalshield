"""Compliance score, risk level, and score-history schemas."""

from datetime import datetime

from pydantic import BaseModel


class ComplianceOut(BaseModel):
    """A score with the inputs that produced it, so the dashboard can show the arithmetic."""

    score: float
    risk_level: str
    risk_colour: str
    violation_count: int
    breach_count: int
    violation_penalty: float
    environmental_penalty: float
    weight_ppe: float
    weight_env: float


class ComplianceHistoryPoint(BaseModel):
    score: float
    risk_level: str
    computed_at: datetime

    model_config = {"from_attributes": True}
