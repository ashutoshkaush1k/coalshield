"""Current sensor standing across every mine - the Government risk view.

Different question from the history endpoints. `/sensors/{id}/trend` answers "what has
this mine been doing"; this answers "who is breaching right now", which needs the latest
reading per sensor per mine rather than a window of them.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.mine import Mine
from app.models.sensor_reading import SensorReading
from app.services.iot.thresholds import SensorType, breach_margin
from app.utils.datetimes import as_utc

SEVERITY_ORDER = {"HIGH": 0, "MEDIUM": 1, "LOW": 2, "OK": 3}


@dataclass
class SensorStanding:
    """One sensor's latest reading at one mine, and how far out of range it is."""

    sensor_type: str
    unit: str
    threshold: float
    value: float | None
    recorded_at: object | None
    breached: bool
    margin: float
    open_breaches: int

    @property
    def margin_ratio(self) -> float:
        return self.margin / self.threshold if self.threshold else 0.0

    @property
    def severity(self) -> str:
        """How bad the current reading is, not how many times it has gone wrong."""
        if not self.breached:
            return "OK"
        if self.margin_ratio >= 0.30:
            return "HIGH"
        if self.margin_ratio >= 0.10:
            return "MEDIUM"
        return "LOW"

    @property
    def status_label(self) -> str:
        """Plain language for the operator-facing view."""
        if self.value is None:
            return "No readings yet"
        if self.breached:
            return "Breached"
        # Within 10% of the limit is close enough that an operator should be watching it.
        if self.threshold and self.value >= self.threshold * 0.9:
            return "Approaching limit"
        return "Within safe range"


@dataclass
class MineSensorStanding:
    mine_id: int
    code: str
    name: str
    location: str
    sensors: list[SensorStanding] = field(default_factory=list)

    @property
    def breaching_now(self) -> list[SensorStanding]:
        return [s for s in self.sensors if s.breached]

    @property
    def worst_severity(self) -> str:
        return min((s.severity for s in self.sensors), key=lambda s: SEVERITY_ORDER[s], default="OK")

    @property
    def total_open_breaches(self) -> int:
        return sum(s.open_breaches for s in self.sensors)


def _latest_reading_ids(db: Session, mine_ids: list[int]) -> list[int]:
    """Ids of the newest reading per (mine, sensor_type).

    Grouped subquery on max(id) rather than max(recorded_at): the simulator writes a whole
    tick with one timestamp, so recorded_at alone cannot pick a single winner per sensor.
    """
    stmt = (
        select(func.max(SensorReading.id))
        .where(SensorReading.mine_id.in_(mine_ids))
        .group_by(SensorReading.mine_id, SensorReading.sensor_type)
    )
    return [row[0] for row in db.execute(stmt).all() if row[0] is not None]


def _open_breach_counts(db: Session, mine_ids: list[int]) -> dict[tuple[int, str], int]:
    """Unresolved breaches per mine per sensor type - the categorisation behind the charts."""
    stmt = (
        select(SensorReading.mine_id, SensorReading.sensor_type, func.count())
        .where(
            SensorReading.mine_id.in_(mine_ids),
            SensorReading.breached.is_(True),
            SensorReading.resolved.is_(False),
        )
        .group_by(SensorReading.mine_id, SensorReading.sensor_type)
    )
    return {(mine_id, sensor): count for mine_id, sensor, count in db.execute(stmt).all()}


def fleet_sensor_standing(db: Session, mine_ids: list[int] | None = None) -> list[MineSensorStanding]:
    """Latest reading per sensor for every visible mine, worst mine first."""
    stmt = select(Mine).order_by(Mine.id)
    if mine_ids is not None:
        stmt = stmt.where(Mine.id.in_(mine_ids))
    mines = list(db.scalars(stmt).all())
    if not mines:
        return []

    ids = [m.id for m in mines]
    latest_ids = _latest_reading_ids(db, ids)
    readings = (
        db.scalars(select(SensorReading).where(SensorReading.id.in_(latest_ids))).all()
        if latest_ids
        else []
    )
    by_key = {(r.mine_id, r.sensor_type): r for r in readings}
    breach_counts = _open_breach_counts(db, ids)

    standings: list[MineSensorStanding] = []
    for mine in mines:
        sensors: list[SensorStanding] = []
        for sensor_type in SensorType:
            reading = by_key.get((mine.id, sensor_type.value))
            value = reading.value if reading else None
            sensors.append(
                SensorStanding(
                    sensor_type=sensor_type.value,
                    unit=sensor_type.unit,
                    threshold=sensor_type.limit,
                    value=value,
                    recorded_at=reading.recorded_at if reading else None,
                    breached=bool(reading and reading.breached),
                    margin=breach_margin(sensor_type, value) if value is not None else 0.0,
                    open_breaches=breach_counts.get((mine.id, sensor_type.value), 0),
                )
            )
        standings.append(
            MineSensorStanding(
                mine_id=mine.id, code=mine.code, name=mine.name,
                location=mine.location, sensors=sensors,
            )
        )

    # Worst first: mines breaching now, then by how many sensors are out, then by history.
    standings.sort(
        key=lambda m: (
            SEVERITY_ORDER[m.worst_severity],
            -len(m.breaching_now),
            -m.total_open_breaches,
            m.mine_id,
        )
    )
    return standings


BUCKET_HOURS = 6
BREACH_CATEGORIES = [s.value for s in SensorType]


def breach_buckets(
    db: Session, mine_ids: list[int] | None = None, bucket_hours: int = BUCKET_HOURS
) -> list[dict]:
    """Breach counts over time, split by sensor category, for a set of mines.

    Moved server-side because the frontend previously assembled this by calling the
    per-mine trend endpoint once per mine. That is fine for five mines and untenable for
    a national dataset - one query here replaces N HTTP round trips.
    """
    stmt = select(SensorReading.sensor_type, SensorReading.recorded_at).where(
        SensorReading.breached.is_(True), SensorReading.resolved.is_(False)
    )
    if mine_ids is not None:
        if not mine_ids:
            return []
        stmt = stmt.where(SensorReading.mine_id.in_(mine_ids))

    rows = db.execute(stmt).all()
    if not rows:
        return []

    size = bucket_hours * 3600
    blank = {c: 0 for c in BREACH_CATEGORIES}

    def key_of(recorded_at) -> int:
        # as_utc first: SQLite hands the time back naive, and a naive .timestamp() is read
        # as the server's local time - on an IST machine every window landed 5h30m early.
        return int(as_utc(recorded_at).timestamp() // size) * size

    stamps = [key_of(r[1]) for r in rows]
    first, last = min(stamps), max(stamps)

    buckets: dict[int, dict] = {t: dict(blank) for t in range(first, last + size, size)}
    for sensor_type, recorded_at in rows:
        bucket = buckets.setdefault(key_of(recorded_at), dict(blank))
        bucket[sensor_type] = bucket.get(sensor_type, 0) + 1

    return [
        {
            "start_ms": start * 1000,
            **counts,
            "breaches": sum(counts.values()),
        }
        for start, counts in sorted(buckets.items())
    ]
