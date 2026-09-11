"""Auto-ranked inspection queue: which mine should an inspector visit first? PRD 4.1.

Urgency is deliberately a single transparent number:

    urgency = (100 - compliance_score) + trend_pressure * weight_trend

The first term is severity, and because risk bands are score ranges it orders HIGH before MEDIUM
before LOW for free - no separate band weighting needed, and no way for the two to disagree.

The second term is what makes this more than "sort by score": a mine whose violation and breach
rate is rising can overtake a slightly worse mine that is stable, which is the whole argument for
sending an inspector there first.
"""

from __future__ import annotations

from dataclasses import dataclass

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.models.mine import Mine
from app.services.compliance.scoring import ComplianceResult, score_mines
from app.services.risk.trend import TrendSignal, trend_for_mines

MAX_SCORE = 100.0


@dataclass(frozen=True)
class InspectionCandidate:
    """One row of the inspection queue, with the reasoning that put it there."""

    mine_id: int
    code: str
    name: str
    location: str
    region: str
    compliance: ComplianceResult
    trend: TrendSignal
    urgency: float
    reasons: list[str]
    rank: int = 0

    @property
    def severity(self) -> float:
        return round(MAX_SCORE - self.compliance.score, 1)


def _reasons(compliance: ComplianceResult, trend: TrendSignal) -> list[str]:
    """Plain-language justification. An inspector should not have to read the formula."""
    out = [f"{compliance.risk_level.label} - compliance score {compliance.score:.0f}"]

    if trend.is_rising:
        out.append(
            f"Deteriorating: {trend.recent_events} events in the last {trend.window_hours}h "
            f"vs {trend.previous_events} in the {trend.window_hours}h before"
        )
    elif trend.direction.value == "FALLING":
        out.append(
            f"Improving: {trend.recent_events} events in the last {trend.window_hours}h "
            f"vs {trend.previous_events} before"
        )

    if trend.recent_violations:
        out.append(f"{trend.recent_violations} PPE violation(s) in the last {trend.window_hours}h")
    if trend.recent_breaches:
        out.append(f"{trend.recent_breaches} threshold breach(es) in the last {trend.window_hours}h")
    if not trend.recent_events:
        out.append(f"No new events in the last {trend.window_hours}h")
    return out


def compute_urgency(
    compliance: ComplianceResult, trend: TrendSignal, weight_trend: float | None = None
) -> float:
    """Pure urgency calculation. Higher is more urgent."""
    weight_trend = settings.weight_trend if weight_trend is None else weight_trend
    severity = MAX_SCORE - compliance.score
    return round(severity + trend.pressure * weight_trend, 1)


def rank_candidates(candidates: list[InspectionCandidate]) -> list[InspectionCandidate]:
    """Order by urgency, with every tie broken deterministically.

    Tie-break chain, most to least significant:
      1. urgency        - the headline number
      2. severity       - at equal urgency, the mine in the worse state now goes first, because a
                          low score is certain while a trend is an inference
      3. recent events  - then the mine with more current activity
      4. mine_id        - so two identical mines never swap places between requests

    Without step 4 the queue would reorder on every refresh and an inspector could not trust it.
    """
    ordered = sorted(
        candidates,
        key=lambda c: (-c.urgency, -c.severity, -c.trend.recent_events, c.mine_id),
    )
    return [
        InspectionCandidate(**{**vars(c), "rank": position})
        for position, c in enumerate(ordered, start=1)
    ]


def build_inspection_queue(
    db: Session, mine_ids: list[int] | None = None, weight_trend: float | None = None
) -> list[InspectionCandidate]:
    """Score every visible mine, attach its trend, and rank the result."""
    stmt = select(Mine).order_by(Mine.id)
    if mine_ids is not None:
        stmt = stmt.where(Mine.id.in_(mine_ids))
    mines = list(db.scalars(stmt).all())
    if not mines:
        return []

    ids = [m.id for m in mines]
    scores = score_mines(db, ids)
    trends = trend_for_mines(db, ids)

    candidates = [
        InspectionCandidate(
            mine_id=m.id,
            code=m.code,
            name=m.name,
            location=m.location,
            region=m.region,
            compliance=scores[m.id],
            trend=trends[m.id],
            urgency=compute_urgency(scores[m.id], trends[m.id], weight_trend),
            reasons=_reasons(scores[m.id], trends[m.id]),
        )
        for m in mines
    ]
    return rank_candidates(candidates)
