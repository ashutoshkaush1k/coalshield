"""Mine list and detail. Government sees all; Mine Head is filtered to its own mine_id by deps,
not by the UI."""

from fastapi import APIRouter, HTTPException, status
from sqlalchemy import func, select

from app.api.deps import DbSession, Scope
from app.models.alert import STATUS_RESOLVED, Alert
from app.models.mine import Mine
from app.models.sensor_reading import SensorReading
from app.schemas.compliance import ComplianceOut
from app.schemas.mine import MineDetail, MineSummary
from app.services.access.scope import MineAccessDenied
from app.services.compliance.scoring import ComplianceResult, score_mines

router = APIRouter(prefix="/mines", tags=["mines"])


def to_compliance_out(result: ComplianceResult) -> ComplianceOut:
    return ComplianceOut(
        score=result.score,
        risk_level=result.risk_level.value,
        risk_colour=result.risk_level.colour,
        violation_count=result.violation_count,
        breach_count=result.breach_count,
        violation_penalty=result.violation_penalty,
        environmental_penalty=result.environmental_penalty,
        weight_ppe=result.weights.weight_ppe,
        weight_env=result.weights.weight_env,
    )


def open_alert_counts(db, mine_ids: list[int]) -> dict[int, int]:
    """Outstanding alerts per mine, in one grouped query rather than one per row.

    Outstanding means unacknowledged AND not resolved. The two are separate flags -
    acknowledged is "seen", status is "dealt with" - and a directive closed with proof
    must drop off this count even though the two flags are set by different actions.
    """
    stmt = (
        select(Alert.mine_id, func.count())
        .where(
            Alert.mine_id.in_(mine_ids),
            Alert.acknowledged.is_(False),
            Alert.status != STATUS_RESOLVED,
        )
        .group_by(Alert.mine_id)
    )
    return dict(db.execute(stmt).all())


def visible_mines(db, scope, state: str | None = None) -> list[Mine]:
    """Every mine the caller may see, optionally narrowed to one state.

    Two different filters that must not be confused: the scope filter is access control
    and is never optional, the state filter is the user's current view. State is matched
    on the structured column, never by parsing the free-text location.
    """
    stmt = select(Mine).order_by(Mine.id)
    if not scope.is_unrestricted:
        stmt = stmt.where(Mine.id == scope.mine_id)
    if state:
        stmt = stmt.where(Mine.state == state)
    return list(db.scalars(stmt).all())


def available_states(db, scope) -> list[str]:
    """States that actually have monitored mines, for the filter dropdown.

    Derived from the data rather than a hardcoded list of Indian states, so the dropdown
    can never offer a state that would return an empty board.
    """
    stmt = select(Mine.state).where(Mine.state != "").distinct().order_by(Mine.state)
    if not scope.is_unrestricted:
        stmt = stmt.where(Mine.id == scope.mine_id)
    return [row[0] for row in db.execute(stmt).all()]


@router.get("", response_model=list[MineSummary])
def list_mines(db: DbSession, scope: Scope) -> list[MineSummary]:
    """All mines with live compliance scores (Government), or just the caller's own (Mine Head)."""
    mines = visible_mines(db, scope)
    ids = [m.id for m in mines]
    scores = score_mines(db, ids)
    alerts = open_alert_counts(db, ids)
    return [
        MineSummary(
            **_mine_fields(m),
            compliance=to_compliance_out(scores[m.id]),
            open_alerts=alerts.get(m.id, 0),
        )
        for m in mines
    ]


def _mine_fields(mine: Mine) -> dict:
    return {
        "id": mine.id,
        "code": mine.code,
        "name": mine.name,
        "location": mine.location,
        "district": mine.district,
        "state": mine.state,
        "region": mine.region,
        "operator": mine.operator,
    }


@router.get("/{mine_id}", response_model=MineDetail)
def get_mine(mine_id: int, db: DbSession, scope: Scope) -> MineDetail:
    """Drill-down for one mine. Out-of-scope requests are refused before the query runs."""
    try:
        scope.require(mine_id)
    except MineAccessDenied as exc:
        raise HTTPException(status.HTTP_403_FORBIDDEN, str(exc)) from exc

    mine = db.get(Mine, mine_id)
    if mine is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, f"Mine {mine_id} not found")

    result = score_mines(db, [mine_id])[mine_id]
    readings = db.scalar(
        select(func.count()).select_from(SensorReading).where(SensorReading.mine_id == mine_id)
    )
    return MineDetail(
        **_mine_fields(mine),
        compliance=to_compliance_out(result),
        open_alerts=open_alert_counts(db, [mine_id]).get(mine_id, 0),
        total_readings=readings or 0,
    )
