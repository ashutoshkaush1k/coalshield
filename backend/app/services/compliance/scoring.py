"""Score = 100 - (violation_penalty + environmental_penalty). PRD 6.1.

The pure calculation lives in `compute_compliance_score` and knows nothing about the database, so
it can be tested directly and explained on a whiteboard. Everything below it just counts rows and
feeds that function.

Scores are always recomputed from current counts, never incremented. A running total would drift
and could not be re-derived after a weight change (PRD 8.1 leaves the weights open).

The two penalties recover differently, on purpose. A PPE violation is a finding about how people
were working, so it counts until a clean re-inspection resolves it (services/compliance/
resolution.py). A sensor breach is a condition, and conditions pass: it counts only while it is
inside the rolling window (BREACH_WINDOW_HOURS). A mine whose air has been clean for the whole
window has nothing environmental left against it, so its score climbs back on its own - nobody
has to resolve anything, and nothing is deleted.
"""

from dataclasses import dataclass
from datetime import UTC, datetime, timedelta

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.models.compliance_score import ComplianceScore
from app.models.sensor_reading import SensorReading
from app.models.violation import Violation
from app.services.compliance.risk import RiskLevel, risk_from_score
from app.services.compliance.weights import ScoringWeights
from app.utils.datetimes import as_utc

MAX_SCORE = 100.0
MIN_SCORE = 0.0


@dataclass(frozen=True)
class ComplianceResult:
    """A score plus every input that produced it, so any number on the dashboard can be justified."""

    score: float
    risk_level: RiskLevel
    violation_count: int
    breach_count: int
    violation_penalty: float
    environmental_penalty: float
    raw_score: float
    weights: ScoringWeights
    # Hours of breach history `breach_count` covers. None means every breach on record.
    breach_window_hours: float | None = None

    @property
    def total_penalty(self) -> float:
        return self.violation_penalty + self.environmental_penalty

    @property
    def was_floored(self) -> bool:
        """True when penalties exceeded 100 and the score was clamped to zero.

        Worth surfacing: two mines can both show 0 while one is far worse than the other.
        """
        return self.raw_score < MIN_SCORE


def compute_compliance_score(
    violation_count: int,
    breach_count: int,
    weights: ScoringWeights | None = None,
    breach_window_hours: float | None = None,
) -> ComplianceResult:
    """Pure implementation of the PRD 6.1 formula.

        Compliance Score (0-100) = 100 - (Violation Penalty + Environmental Penalty)
        Violation Penalty        = PPE violations detected x weight_ppe
        Environmental Penalty    = sensor readings beyond threshold x weight_env

    Args:
        violation_count: PPE violations detected for the mine.
        breach_count: sensor readings that exceeded their threshold - the in-window ones, when
            the caller applies a window. This function only does the arithmetic.
        weights: overrides the env-configured weights; used by tests and what-if tuning.
        breach_window_hours: recorded on the result so the number can be explained. It does not
            change the arithmetic.

    Raises:
        ValueError: on negative counts, which would silently inflate a score above 100.
    """
    if violation_count < 0 or breach_count < 0:
        raise ValueError("Counts must be non-negative")

    weights = weights or ScoringWeights.from_settings()

    violation_penalty = violation_count * weights.weight_ppe
    environmental_penalty = breach_count * weights.weight_env

    raw_score = MAX_SCORE - (violation_penalty + environmental_penalty)
    score = round(min(MAX_SCORE, max(MIN_SCORE, raw_score)), 1)

    # Band from the rounded score so the displayed number and the colour never disagree.
    return ComplianceResult(
        score=score,
        risk_level=risk_from_score(score),
        violation_count=violation_count,
        breach_count=breach_count,
        violation_penalty=violation_penalty,
        environmental_penalty=environmental_penalty,
        raw_score=round(raw_score, 1),
        weights=weights,
        breach_window_hours=breach_window_hours,
    )


# --- Rolling breach window -------------------------------------------------------------------


def configured_breach_window() -> float | None:
    """BREACH_WINDOW_HOURS, or None when it is 0 and every breach on record counts."""
    hours = settings.breach_window_hours
    return hours if hours and hours > 0 else None


def breach_window_start(now: datetime | None = None) -> datetime | None:
    """The oldest moment a breach still counts from; None when every breach counts.

    Measured against the wall clock, because that is what readings are stamped with. It is also
    why a score recovers between simulator ticks and after the feed stops: nothing has to happen
    for an old breach to age out.
    """
    hours = configured_breach_window()
    if hours is None:
        return None
    return as_utc(now or datetime.now(UTC)) - timedelta(hours=hours)


# --- Database wiring -------------------------------------------------------------------------


def _violation_counts(db: Session, mine_ids: list[int] | None = None) -> dict[int, int]:
    """Open violations only.

    Resolved violations stay in the table - the audit trail must keep showing they
    happened - but they stop counting against the score, which is what lets a mine
    recover after it fixes the problem. Deliberately not windowed: see the module docstring.
    """
    stmt = (
        select(Violation.mine_id, func.count())
        .where(Violation.resolved.is_(False))
        .group_by(Violation.mine_id)
    )
    if mine_ids is not None:
        stmt = stmt.where(Violation.mine_id.in_(mine_ids))
    return dict(db.execute(stmt).all())


def _breach_counts(
    db: Session, mine_ids: list[int] | None = None, since: datetime | None = None
) -> dict[int, int]:
    """Open breaches recorded at or after `since` - the rolling window.

    A breach older than the window has aged out: it stays in the table, the trend charts and the
    audit trail, and simply stops costing the mine anything. The `resolved` filter stays too, so
    an explicit sign-off path can be added later without the two penalties drifting apart.
    """
    stmt = (
        select(SensorReading.mine_id, func.count())
        .where(SensorReading.breached.is_(True), SensorReading.resolved.is_(False))
        .group_by(SensorReading.mine_id)
    )
    if mine_ids is not None:
        stmt = stmt.where(SensorReading.mine_id.in_(mine_ids))
    if since is not None:
        stmt = stmt.where(SensorReading.recorded_at >= since)
    return dict(db.execute(stmt).all())


def score_mine(
    db: Session,
    mine_id: int,
    weights: ScoringWeights | None = None,
    now: datetime | None = None,
) -> ComplianceResult:
    """Recompute one mine's score from its open violations and in-window breaches.

    `now` is injectable so tests can move the clock instead of sleeping.
    """
    return score_mines(db, [mine_id], weights, now)[mine_id]


def score_mines(
    db: Session,
    mine_ids: list[int],
    weights: ScoringWeights | None = None,
    now: datetime | None = None,
) -> dict[int, ComplianceResult]:
    """Score many mines with two grouped queries instead of two per mine.

    The Government overview grid renders every mine at once, so this is the hot path.
    """
    violations = _violation_counts(db, mine_ids)
    breaches = _breach_counts(db, mine_ids, since=breach_window_start(now))
    window = configured_breach_window()
    return {
        mine_id: compute_compliance_score(
            violation_count=violations.get(mine_id, 0),
            breach_count=breaches.get(mine_id, 0),
            weights=weights,
            breach_window_hours=window,
        )
        for mine_id in mine_ids
    }


def record_score(db: Session, mine_id: int, result: ComplianceResult) -> ComplianceScore:
    """Append the result to the score history that drives the compliance trend chart.

    Appended, never updated, so the trend line survives retuning.
    """
    row = ComplianceScore(
        mine_id=mine_id,
        score=result.score,
        risk_level=result.risk_level.value,
        violation_count=result.violation_count,
        breach_count=result.breach_count,
        weight_ppe=result.weights.weight_ppe,
        weight_env=result.weights.weight_env,
    )
    db.add(row)
    db.flush()
    return row


def latest_recorded_scores(db: Session, mine_ids: list[int]) -> dict[int, ComplianceScore]:
    """The newest history point per mine, in one query.

    The simulator compares against this rather than its own last tick, so a score another path
    already recorded - a PPE upload, a resolution - is not recorded a second time.
    """
    if not mine_ids:
        return {}
    newest = (
        select(func.max(ComplianceScore.id))
        .where(ComplianceScore.mine_id.in_(mine_ids))
        .group_by(ComplianceScore.mine_id)
    )
    rows = db.scalars(select(ComplianceScore).where(ComplianceScore.id.in_(newest))).all()
    return {row.mine_id: row for row in rows}
