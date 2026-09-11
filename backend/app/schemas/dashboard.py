"""Composed response shapes for the Government overview and Mine Head dashboard."""

from pydantic import BaseModel

from app.schemas.inspection import InspectionCandidateOut
from app.schemas.mine import MineSummary


class FleetStats(BaseModel):
    """Headline numbers across everything the caller can see."""

    mine_count: int
    average_score: float
    high_risk_count: int
    medium_risk_count: int
    low_risk_count: int
    total_violations: int
    # Breaches currently counting against scores - inside the rolling window, not all-time.
    total_breaches: int
    breach_window_hours: float | None = None


class DashboardOut(BaseModel):
    role: str
    scope: str

    # Which slice of the country this payload describes. `stats` covers every mine in
    # scope; `mines` may be a capped subset of them, which `is_truncated` flags.
    state: str | None = None
    scope_label: str = "National"
    states: list[str] = []
    showing: int = 0
    is_truncated: bool = False

    stats: FleetStats
    mines: list[MineSummary]

    # Ranked inspection queue, Government only. Empty for a Mine Head: a cross-mine ranking has no
    # meaning for a single site and would leak where other mines stand.
    inspection_queue: list[InspectionCandidateOut] = []
