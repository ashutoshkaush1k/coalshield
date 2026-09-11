"""Incremental sensor feed for live charts: each poll returns only what is new.

The history endpoints return a fixed window on every call, so a chart polling them has to
throw its series away and redraw. Here the client keeps a cursor and gets back only the
ticks written since, which it appends.

A "tick" is one mine's gas, dust and temperature at one moment - the row a chart plots.
Readings are stored one per sensor, so ticks are reassembled here rather than stored.
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass, field
from datetime import datetime, timedelta

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.sensor_reading import SensorReading
from app.services.iot.thresholds import SensorType
from app.utils.datetimes import as_utc

# Readings further apart than this are never the same tick. The simulator stamps a whole tick
# with one instant; the seeded history spreads a tick's sensors over up to 45 minutes, with
# 6 hours between ticks. An hour sits safely between the two.
TICK_SPREAD = timedelta(hours=1)

SENSOR_ORDER = [s.value for s in SensorType]


@dataclass
class Tick:
    """One mine's sensors at one moment. A sensor that did not report is simply absent."""

    mine_id: int
    readings: dict[str, SensorReading] = field(default_factory=dict)
    anomaly_score: float | None = None
    is_anomaly: bool | None = None

    @property
    def timestamp(self) -> datetime:
        return max(as_utc(r.recorded_at) for r in self.readings.values())

    @property
    def last_id(self) -> int:
        return max(r.id for r in self.readings.values())

    @property
    def is_complete(self) -> bool:
        return all(s in self.readings for s in SENSOR_ORDER)

    @property
    def breached(self) -> list[str]:
        """Taken from each reading's stored flag - the threshold rule is not re-applied here."""
        return [s for s in SENSOR_ORDER if s in self.readings and self.readings[s].breached]

    def value(self, sensor_type: str) -> float | None:
        reading = self.readings.get(sensor_type)
        return reading.value if reading else None


def group_into_ticks(readings: list[SensorReading]) -> list[Tick]:
    """Reassemble one mine's readings into ticks, oldest first.

    A new tick starts when a sensor repeats (the simulator's ticks share one timestamp, so
    this is what separates them) or when the readings drift too far apart in time (what
    separates the seeded history, where each sensor's clock differs slightly).
    """
    ticks: list[Tick] = []
    current: Tick | None = None
    started: datetime | None = None

    for reading in sorted(readings, key=lambda r: (as_utc(r.recorded_at), r.id)):
        at = as_utc(reading.recorded_at)
        if (
            current is None
            or reading.sensor_type in current.readings
            or at - started > TICK_SPREAD
        ):
            current = Tick(mine_id=reading.mine_id)
            ticks.append(current)
            started = at
        current.readings[reading.sensor_type] = reading
    return ticks


@dataclass
class FeedPage:
    cursor: int
    reset: bool
    ticks: dict[int, list[Tick]]  # mine_id -> ticks, oldest first


def latest_reading_id(db: Session) -> int:
    return db.scalar(select(func.max(SensorReading.id))) or 0


def fetch_ticks(
    db: Session, mine_ids: list[int], *, after: int | None, limit: int
) -> FeedPage:
    """The newest `limit` ticks per mine, restricted to readings after the cursor if given.

    The cursor is the highest reading id visible when the page was built, across all
    mines. Anything written later gets a higher id, so the next poll picks it up and nothing
    is sent twice. That holds because SQLite has a single writer: ids become visible in the
    order they were assigned. (Under Postgres, concurrent inserts can commit out of order
    and this would need a commit-ordered cursor instead.)

    A cursor ahead of the newest id means the database was rebuilt underneath the client
    (seed_db.py --reset restarts ids), so the page is served fresh with `reset` set.
    """
    snapshot = latest_reading_id(db)
    reset = after is not None and after > snapshot
    if reset:
        after = None

    if not mine_ids:
        return FeedPage(cursor=snapshot, reset=reset, ticks={})

    # Enough rows for limit+1 ticks per mine. The extra tick absorbs one that the row
    # cut-off may have split, and is dropped below.
    rows_per_mine = (limit + 1) * len(SENSOR_ORDER)
    filters = [SensorReading.mine_id.in_(mine_ids), SensorReading.id <= snapshot]
    if after is not None:
        filters.append(SensorReading.id > after)

    ranked = (
        select(
            SensorReading.id,
            func.row_number()
            .over(
                partition_by=SensorReading.mine_id,
                order_by=(SensorReading.recorded_at.desc(), SensorReading.id.desc()),
            )
            .label("rank"),
        )
        .where(*filters)
        .subquery()
    )
    rows = db.scalars(
        select(SensorReading)
        .join(ranked, SensorReading.id == ranked.c.id)
        .where(ranked.c.rank <= rows_per_mine)
    ).all()

    by_mine: dict[int, list[SensorReading]] = defaultdict(list)
    for row in rows:
        by_mine[row.mine_id].append(row)

    ticks = {mine_id: group_into_ticks(readings)[-limit:] for mine_id, readings in by_mine.items()}
    return FeedPage(cursor=snapshot, reset=reset, ticks=ticks)


def ticks_for_readings(db: Session, mine_id: int, readings: list[SensorReading]) -> dict[int, Tick]:
    """Reading id -> the tick it belongs to, for endpoints that return one row per sensor.

    Those endpoints filter and cut their rows (by sensor type, by count), so a reading's
    tick-mates may not be in the result. They are fetched back from either side of it,
    within the span a tick can cover.
    """
    if not readings:
        return {}
    stamps = [as_utc(r.recorded_at) for r in readings]
    context = db.scalars(
        select(SensorReading).where(
            SensorReading.mine_id == mine_id,
            SensorReading.recorded_at >= min(stamps) - TICK_SPREAD,
            SensorReading.recorded_at <= max(stamps) + TICK_SPREAD,
        )
    ).all()
    wanted = {r.id for r in readings}
    return {
        reading.id: tick
        for tick in group_into_ticks(list(context))
        for reading in tick.readings.values()
        if reading.id in wanted
    }
