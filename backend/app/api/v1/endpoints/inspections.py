"""Government-only: auto-ranked inspection prioritisation list of high-risk mines."""

from fastapi import APIRouter, Query

from app.api.deps import DbSession, RequireGovernment, Scope
from app.api.v1.endpoints.mines import to_compliance_out, visible_mines
from app.core.config import settings
from app.schemas.inspection import InspectionCandidateOut, InspectionQueueOut, TrendOut
from app.services.risk.prioritisation import InspectionCandidate, build_inspection_queue

router = APIRouter(prefix="/inspections", tags=["inspections"])


def to_candidate_out(candidate: InspectionCandidate) -> InspectionCandidateOut:
    trend = candidate.trend
    return InspectionCandidateOut(
        rank=candidate.rank,
        mine_id=candidate.mine_id,
        code=candidate.code,
        name=candidate.name,
        location=candidate.location,
        region=candidate.region,
        urgency=candidate.urgency,
        severity=candidate.severity,
        compliance=to_compliance_out(candidate.compliance),
        trend=TrendOut(
            window_hours=trend.window_hours,
            recent_violations=trend.recent_violations,
            recent_breaches=trend.recent_breaches,
            recent_events=trend.recent_events,
            previous_events=trend.previous_events,
            delta=trend.delta,
            direction=trend.direction.value,
        ),
        reasons=candidate.reasons,
    )


@router.get("", response_model=InspectionQueueOut)
def inspection_queue(
    db: DbSession,
    scope: Scope,
    user: RequireGovernment,
    limit: int | None = Query(default=None, ge=1, le=100),
    state: str | None = Query(default=None, description="Narrow the ranking to one state"),
) -> InspectionQueueOut:
    """Every mine, ranked most urgent first.

    Government-only by design (PRD 4.1): cross-mine ranking is inherently comparative, so it has
    no meaning for a Mine Head and would leak other mines' standing if it did. `RequireGovernment`
    rejects a Mine Head with 403 before any query runs.
    """
    # Ranked within the selected state so an official working one region is not
    # handed a national list they then have to filter by eye.
    mine_ids = [m.id for m in visible_mines(db, scope, state)] if state else None
    candidates = build_inspection_queue(db, mine_ids)
    if limit is not None:
        candidates = candidates[:limit]

    return InspectionQueueOut(
        generated_for=user.email,
        weight_trend=settings.weight_trend,
        trend_window_hours=settings.trend_window_hours,
        mine_count=len(candidates),
        candidates=[to_candidate_out(c) for c in candidates],
    )
