"""Mine summary (grid row) and detail (drill-down) schemas."""

from pydantic import BaseModel

from app.schemas.compliance import ComplianceOut


class MineBase(BaseModel):
    id: int
    code: str
    name: str
    location: str
    district: str = ""
    state: str = ""
    region: str
    operator: str

    model_config = {"from_attributes": True}


class MineSummary(MineBase):
    """One row of the Government overview grid."""

    compliance: ComplianceOut
    open_alerts: int = 0


class MineDetail(MineSummary):
    """Drill-down payload; the heavier collections are fetched from their own endpoints."""

    total_readings: int
