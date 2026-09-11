"""Sensor readings and gas/dust/temperature trend series, mine-scoped."""

from fastapi import APIRouter, HTTPException, Query, status
from sqlalchemy import select

from app.api.deps import DbSession, RequireGovernment, Scope
from app.models.mine import Mine
from app.models.sensor_reading import SensorReading
from app.schemas.breach_bucket import BreachBucketOut, BreachBucketsOut
from app.schemas.fleet_sensor import FleetSensorOut, MineSensorStandingOut, SensorStandingOut
from app.schemas.sensor import (
    FleetLiveFeedOut,
    FleetLiveReadingOut,
    LiveReadingOut,
    MineLiveFeedOut,
    SensorReadingOut,
    SensorSeries,
    SensorTrendOut,
)
from app.api.v1.endpoints.mines import visible_mines
from app.services.iot.fleet_status import BUCKET_HOURS, breach_buckets, fleet_sensor_standing
from app.services.iot.live_feed import Tick, fetch_ticks
from app.services.access.scope import MineAccessDenied
from app.services.iot.thresholds import SensorType
from app.utils.datetimes import to_epoch_ms

router = APIRouter(prefix="/sensors", tags=["sensors"])


def _require(scope, mine_id: int) -> int:
    try:
        return scope.require(mine_id)
    except MineAccessDenied as exc:
        raise HTTPException(status.HTTP_403_FORBIDDEN, str(exc)) from exc


def _feed_envelope(page) -> dict:
    return {
        "cursor": page.cursor,
        "reset": page.reset,
        "thresholds": {s.value: s.limit for s in SensorType},
        "units": {s.value: s.unit for s in SensorType},
    }


def _tick_fields(tick: Tick) -> dict:
    return {
        "timestamp": tick.timestamp,
        "timestamp_ms": to_epoch_ms(tick.timestamp),
        **{s.value: tick.value(s.value) for s in SensorType},
        "breached": tick.breached,
        "anomaly_score": tick.anomaly_score,
        "is_anomaly": tick.is_anomaly,
    }


@router.get("", response_model=FleetSensorOut)
def fleet_standing(
    db: DbSession,
    scope: Scope,
    user: RequireGovernment,
    state: str | None = Query(default=None, description="Narrow to one state"),
) -> FleetSensorOut:
    """Current sensor standing across every mine - who is breaching right now.

    Government-only, the same access pattern as /inspections: this is inherently a
    cross-mine comparison, so it has no meaning for a Mine Head and would leak other
    mines' conditions. RequireGovernment rejects them with 403 before any query runs.
    """
    mine_ids = [m.id for m in visible_mines(db, scope, state)] if state else None
    standings = fleet_sensor_standing(db, mine_ids)
    return FleetSensorOut(
        mine_count=len(standings),
        breaching_mines=sum(1 for m in standings if m.breaching_now),
        mines=[
            MineSensorStandingOut(
                mine_id=m.mine_id, code=m.code, name=m.name, location=m.location,
                worst_severity=m.worst_severity,
                breaching_now=len(m.breaching_now),
                total_open_breaches=m.total_open_breaches,
                sensors=[
                    SensorStandingOut(
                        sensor_type=s.sensor_type, unit=s.unit, threshold=s.threshold,
                        value=s.value, recorded_at=s.recorded_at, breached=s.breached,
                        margin=round(s.margin, 2), severity=s.severity,
                        status_label=s.status_label, open_breaches=s.open_breaches,
                    )
                    for s in m.sensors
                ],
            )
            for m in standings
        ],
    )


@router.get("/breaches", response_model=BreachBucketsOut)
def fleet_breach_buckets(
    db: DbSession,
    scope: Scope,
    state: str | None = Query(default=None, description="Narrow to one state"),
    mine_id: int | None = Query(default=None, description="A single mine"),
) -> BreachBucketsOut:
    """Breach frequency over time, by category, for whatever the caller may see.

    Scoped rather than Government-only: a Mine Head's Trends tab is the same question
    asked of one mine. Passing another mine's id is refused before any query runs.
    """
    if mine_id is not None:
        try:
            scope.require(mine_id)
        except MineAccessDenied as exc:
            raise HTTPException(status.HTTP_403_FORBIDDEN, str(exc)) from exc
        mines = [m for m in visible_mines(db, scope, state) if m.id == mine_id]
    else:
        mines = visible_mines(db, scope, state)

    ids = [m.id for m in mines]
    return BreachBucketsOut(
        bucket_hours=BUCKET_HOURS,
        mine_count=len(ids),
        buckets=[BreachBucketOut(**b) for b in breach_buckets(db, ids)],
    )


# Declared before /{mine_id}: FastAPI matches routes in order, and "live" would otherwise be
# taken for a mine id and rejected as a 422.
@router.get("/live", response_model=FleetLiveFeedOut)
def fleet_live_feed(
    db: DbSession,
    scope: Scope,
    user: RequireGovernment,
    after: int | None = Query(default=None, ge=0, description="`cursor` from the previous poll"),
    limit: int = Query(default=10, ge=1, le=100, description="Newest ticks per mine"),
    state: str | None = Query(default=None, description="Narrow to one state"),
) -> FleetLiveFeedOut:
    """New sensor ticks across every mine since the caller's cursor - append, don't redraw.

    Government-only for the same reason as the fleet standing: a cross-mine feed would
    show a Mine Head other mines' conditions.
    """
    mines = {m.id: m for m in visible_mines(db, scope, state)}
    page = fetch_ticks(db, list(mines), after=after, limit=limit)
    readings = [
        FleetLiveReadingOut(
            mine_id=mine_id, code=mines[mine_id].code, name=mines[mine_id].name,
            **_tick_fields(tick),
        )
        for mine_id, ticks in page.ticks.items()
        for tick in ticks
    ]
    readings.sort(key=lambda r: (r.timestamp_ms, r.mine_id))
    return FleetLiveFeedOut(**_feed_envelope(page), readings=readings)


@router.get("/{mine_id}/live", response_model=MineLiveFeedOut)
def mine_live_feed(
    mine_id: int,
    db: DbSession,
    scope: Scope,
    after: int | None = Query(default=None, ge=0, description="`cursor` from the previous poll"),
    limit: int = Query(default=40, ge=1, le=500, description="Newest ticks to return"),
) -> MineLiveFeedOut:
    """New sensor ticks for one mine since the caller's cursor - append, don't redraw.

    Without `after` this is the opening window (the newest `limit` ticks). With it, only
    ticks written since, usually none or one.
    """
    _require(scope, mine_id)
    if db.get(Mine, mine_id) is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, f"Mine {mine_id} not found")

    page = fetch_ticks(db, [mine_id], after=after, limit=limit)
    return MineLiveFeedOut(
        **_feed_envelope(page),
        mine_id=mine_id,
        readings=[LiveReadingOut(**_tick_fields(t)) for t in page.ticks.get(mine_id, [])],
    )


@router.get("/{mine_id}", response_model=list[SensorReadingOut])
def list_readings(
    mine_id: int,
    db: DbSession,
    scope: Scope,
    sensor_type: str | None = Query(default=None),
    breached_only: bool = Query(default=False),
    limit: int = Query(default=100, le=1000),
) -> list[SensorReadingOut]:
    """Recent readings for one mine, newest first."""
    _require(scope, mine_id)

    stmt = select(SensorReading).where(SensorReading.mine_id == mine_id)
    if sensor_type:
        stmt = stmt.where(SensorReading.sensor_type == SensorType(sensor_type).value)
    if breached_only:
        stmt = stmt.where(SensorReading.breached.is_(True))
    stmt = stmt.order_by(SensorReading.recorded_at.desc(), SensorReading.id.desc()).limit(limit)

    return [SensorReadingOut.model_validate(r) for r in db.scalars(stmt).all()]


@router.get("/{mine_id}/trend", response_model=SensorTrendOut)
def sensor_trend(
    mine_id: int,
    db: DbSession,
    scope: Scope,
    points: int = Query(default=40, le=500),
) -> SensorTrendOut:
    """One series per sensor type, oldest first, with the threshold for the limit line."""
    _require(scope, mine_id)

    series = []
    for sensor_type in SensorType:
        stmt = (
            select(SensorReading)
            .where(
                SensorReading.mine_id == mine_id,
                SensorReading.sensor_type == sensor_type.value,
            )
            .order_by(SensorReading.recorded_at.desc(), SensorReading.id.desc())
            .limit(points)
        )
        rows = list(db.scalars(stmt).all())[::-1]  # chart order: oldest to newest
        series.append(
            SensorSeries(
                sensor_type=sensor_type.value,
                unit=sensor_type.unit,
                threshold=sensor_type.limit,
                breach_count=sum(1 for r in rows if r.breached),
                points=[SensorReadingOut.model_validate(r) for r in rows],
            )
        )
    return SensorTrendOut(mine_id=mine_id, series=series)
