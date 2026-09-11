"""Score = 100 - (violation_penalty + environmental_penalty). PRD 6.1.

The pure calculation lives in `compute_compliance_score` and knows nothing about the database, so
it can be tested directly and explained on a whiteboard. Everything below it just counts rows and
feeds that function.

Scores are always recomputed from current counts, never incremented. A running total would drift
and could not be re-derived after a weight change (PRD 8.1 leaves the weights open).
"""

from dataclasses import dataclass

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.compliance_score import ComplianceScore
from app.models.sensor_reading import SensorReading
from app.models.violation import Violation
from app.services.compliance.risk import RiskLevel, risk_from_score
from app.services.compliance.weights import ScoringWeights

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
) -> ComplianceResult:
    """Pure implementation of the PRD 6.1 formula.

        Compliance Score (0-100) = 100 - (Violation Penalty + Environmental Penalty)
        Violation Penalty        = PPE violations detected x weight_ppe
        Environmental Penalty    = sensor readings beyond threshold x weight_env

    Args:
        violation_count: PPE violations detected for the mine.
        breach_count: sensor readings that exceeded their threshold.
        weights: overrides the env-configured weights; used by tests and what-if tuning.

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
    )


# --- Database wiring -------------------------------------------------------------------------


def _violation_counts(db: Session, mine_ids: list[int] | None = None) -> dict[int, int]:
    """Open violations only.

    Resolved violations stay in the table - the audit trail must keep showing they
    happened - but they stop counting against the score, which is what lets a mine
    recover after it fixes the problem.
    """
    stmt = (
        select(Violation.mine_id, func.count())
        .where(Violation.resolved.is_(False))
        .group_by(Violation.mine_id)
    )
    if mine_ids is not None:
        stmt = stmt.where(Violation.mine_id.in_(mine_ids))
    return dict(db.execute(stmt).all())


def _breach_counts(db: Session, mine_ids: list[int] | None = None) -> dict[int, int]:
    """Open breaches only, on the same principle as violations.

    Nothing resolves a breach yet, so this behaves exactly as before today. The filter
    is here so the two penalties cannot drift apart the moment a resolution path for
    sensor readings is added.
    """
    stmt = (
        select(SensorReading.mine_id, func.count())
        .where(SensorReading.breached.is_(True), SensorReading.resolved.is_(False))
        .group_by(SensorReading.mine_id)
    )
    if mine_ids is not None:
        stmt = stmt.where(SensorReading.mine_id.in_(mine_ids))
    return dict(db.execute(stmt).all())


def score_mine(
    db: Session, mine_id: int, weights: ScoringWeights | None = None
) -> ComplianceResult:
    """Recompute one mine's score from its current violation and breach records."""
    return compute_compliance_score(
        violation_count=_violation_counts(db, [mine_id]).get(mine_id, 0),
        breach_count=_breach_counts(db, [mine_id]).get(mine_id, 0),
        weights=weights,
    )


def score_mines(
    db: Session, mine_ids: list[int], weights: ScoringWeights | None = None
) -> dict[int, ComplianceResult]:
    """Score many mines with two grouped queries instead of two per mine.

    The Government overview grid renders every mine at once, so this is the hot path.
    """
    violations = _violation_counts(db, mine_ids)
    breaches = _breach_counts(db, mine_ids)
    return {
        mine_id: compute_compliance_score(
            violation_count=violations.get(mine_id, 0),
            breach_count=breaches.get(mine_id, 0),
            weights=weights,
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
