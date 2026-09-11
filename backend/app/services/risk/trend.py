"""Trend-based predictive risk flag: rising violation frequency raises risk. PRD should-have 7.

Compares a trailing window against the window immediately before it. A mine with the same score as
another but a rising event rate is the one worth inspecting first - the score says where a mine is,
the trend says where it is heading.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from enum import StrEnum

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.models.sensor_reading import SensorReading
from app.models.violation import Violation


class TrendDirection(StrEnum):
    RISING = "RISING"
    STEADY = "STEADY"
    FALLING = "FALLING"

    @property
    def symbol(self) -> str:
        return {"RISING": "^", "STEADY": "-", "FALLING": "v"}[self.value]


@dataclass(frozen=True)
class TrendSignal:
    """Event counts for one mine across two adjacent windows of equal length."""

    mine_id: int
    window_hours: int
    recent_violations: int
    recent_breaches: int
    previous_violations: int
    previous_breaches: int

    @property
    def recent_events(self) -> int:
        return self.recent_violations + self.recent_breaches

    @property
    def previous_events(self) -> int:
        return self.previous_violations + self.previous_breaches

    @property
    def delta(self) -> int:
        """Positive means deteriorating."""
        return self.recent_events - self.previous_events

    @property
    def direction(self) -> TrendDirection:
        if self.delta > 0:
            return TrendDirection.RISING
        if self.delta < 0:
            return TrendDirection.FALLING
        return TrendDirection.STEADY

    @property
    def is_rising(self) -> bool:
        return self.direction is TrendDirection.RISING

    @property
    def pressure(self) -> int:
        """Only deterioration adds urgency.

        An improving mine is not made *more* urgent than a flat one - it should fall down the
        queue, not be rewarded with a negative score that overtakes a genuinely worse mine.
        """
        return max(0, self.delta)


def _counts(db: Session, model, timestamp_column, mine_ids, start, end, extra=None):
    stmt = (
        select(model.mine_id, func.count())
        .where(model.mine_id.in_(mine_ids), timestamp_column >= start, timestamp_column < end)
        .group_by(model.mine_id)
    )
    if extra is not None:
        stmt = stmt.where(extra)
    return dict(db.execute(stmt).all())


def trend_for_mines(
    db: Session,
    mine_ids: list[int],
    window_hours: int | None = None,
    now: datetime | None = None,
) -> dict[int, TrendSignal]:
    """Build a trend signal per mine using four grouped queries, not four per mine.

    `now` is injectable so tests can pin the clock instead of sleeping.
    """
    if not mine_ids:
        return {}

    window_hours = window_hours or settings.trend_window_hours
    now = now or datetime.now(UTC)
    recent_start = now - timedelta(hours=window_hours)
    previous_start = now - timedelta(hours=window_hours * 2)

    breached = SensorReading.breached.is_(True)
    recent_v = _counts(db, Violation, Violation.detected_at, mine_ids, recent_start, now)
    prev_v = _counts(db, Violation, Violation.detected_at, mine_ids, previous_start, recent_start)
    recent_b = _counts(db, SensorReading, SensorReading.recorded_at, mine_ids,
                       recent_start, now, breached)
    prev_b = _counts(db, SensorReading, SensorReading.recorded_at, mine_ids,
                     previous_start, recent_start, breached)

    return {
        mine_id: TrendSignal(
            mine_id=mine_id,
            window_hours=window_hours,
            recent_violations=recent_v.get(mine_id, 0),
            recent_breaches=recent_b.get(mine_id, 0),
            previous_violations=prev_v.get(mine_id, 0),
            previous_breaches=prev_b.get(mine_id, 0),
        )
        for mine_id in mine_ids
    }
