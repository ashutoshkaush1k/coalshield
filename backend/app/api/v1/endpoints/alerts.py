"""Alerts feed, government directives, and the proof-backed resolution loop."""

from fastapi import APIRouter, File, Form, HTTPException, Query, UploadFile, status
from sqlalchemy import and_, case, select

from app.api.deps import CurrentUser, DbSession, RequireGovernment, Scope
from app.models.alert import ALERT_TYPE_DIRECTIVE, STATUS_OPEN, Alert
from app.models.corrective_action import CorrectiveAction
from app.schemas.alert import AlertOut, DirectiveCreate, ReopenRequest, ResolutionOut
from app.services.access.scope import MineAccessDenied
from app.services.alerts.directives import (
    DirectiveError,
    describe_mine_flag,
    actions_for_alerts,
    raise_directive,
    reopen_directive,
    resolve_directive,
)
from app.utils.files import UnsupportedMediaError, save_upload

router = APIRouter(prefix="/alerts", tags=["alerts"])


def _proof_url(action: CorrectiveAction) -> str | None:
    return f"/static/proof/{action.proof_image_path}" if action.proof_image_path else None


def _to_out(alert: Alert, actions: list[CorrectiveAction]) -> AlertOut:
    out = AlertOut.model_validate(alert)
    out.resolutions = [
        ResolutionOut(
            id=a.id, description=a.description, created_by=a.created_by,
            created_at=a.created_at, resolved_at=a.resolved_at, proof_image_url=_proof_url(a),
        )
        for a in actions
    ]
    return out


def _require(scope, mine_id: int) -> None:
    try:
        scope.require(mine_id)
    except MineAccessDenied as exc:
        raise HTTPException(status.HTTP_403_FORBIDDEN, str(exc)) from exc


def _load_scoped(db, scope, alert_id: int) -> Alert:
    alert = db.get(Alert, alert_id)
    if alert is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, f"Alert {alert_id} not found")
    _require(scope, alert.mine_id)
    return alert


@router.get("", response_model=list[AlertOut])
def list_alerts(
    db: DbSession,
    scope: Scope,
    mine_id: int | None = Query(default=None),
    unacknowledged_only: bool = Query(default=False),
    alert_type: str | None = Query(default=None),
    limit: int = Query(default=50, le=500),
) -> list[AlertOut]:
    """Alerts the caller may see, directives first, then newest first.

    Directives lead because a person is waiting on them; an automated breach alert is
    already reflected in the score.
    """
    if mine_id is not None:
        _require(scope, mine_id)

    stmt = select(Alert)
    if mine_id is not None:
        stmt = stmt.where(Alert.mine_id == mine_id)
    elif not scope.is_unrestricted:
        stmt = stmt.where(Alert.mine_id == scope.mine_id)
    if unacknowledged_only:
        stmt = stmt.where(Alert.acknowledged.is_(False))
    if alert_type:
        stmt = stmt.where(Alert.alert_type == alert_type.upper())

    # Explicit ordering rather than relying on how the type strings happen to sort:
    # open directives first, then resolved ones, then automated alerts.
    priority = case(
        (and_(Alert.alert_type == ALERT_TYPE_DIRECTIVE, Alert.status == STATUS_OPEN), 0),
        (Alert.alert_type == ALERT_TYPE_DIRECTIVE, 1),
        else_=2,
    )
    stmt = stmt.order_by(priority, Alert.created_at.desc(), Alert.id.desc()).limit(limit)

    alerts = list(db.scalars(stmt).all())
    grouped = actions_for_alerts(db, [a.id for a in alerts])
    return [_to_out(a, grouped.get(a.id, [])) for a in alerts]


@router.post("/{alert_id}/ack", response_model=AlertOut)
def acknowledge(alert_id: int, db: DbSession, scope: Scope) -> AlertOut:
    """Mark one alert as seen. Distinct from resolving it."""
    alert = _load_scoped(db, scope, alert_id)
    alert.acknowledged = True
    db.commit()
    db.refresh(alert)
    return _to_out(alert, actions_for_alerts(db, [alert.id]).get(alert.id, []))


@router.post("/directives", response_model=AlertOut, status_code=status.HTTP_201_CREATED)
def create_directive(
    payload: DirectiveCreate, db: DbSession, user: RequireGovernment
) -> AlertOut:
    """Raise a directive against one mine.

    Government-only by design: a directive is an authority instructing an operator, so a
    Mine Head raising one against themselves - or against anyone else - is meaningless.
    `RequireGovernment` rejects them with 403 before any write happens.
    """
    from app.models.mine import Mine

    mine = db.get(Mine, payload.mine_id)
    if mine is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, f"Mine {payload.mine_id} not found")

    # One-click flag: no message supplied, so describe the mine as it stands right now.
    generated_message, generated_severity = describe_mine_flag(
        db, mine, user.full_name or user.email
    )
    message = (payload.message or "").strip() or generated_message
    severity = payload.severity or generated_severity

    try:
        alert = raise_directive(
            db, payload.mine_id,
            message=message,
            raised_by=user.email,
            severity=severity,
            reference_id=payload.reference_id,
        )
    except DirectiveError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc

    db.commit()
    db.refresh(alert)
    return _to_out(alert, [])


@router.post("/{alert_id}/resolve", response_model=AlertOut)
async def resolve(
    alert_id: int,
    db: DbSession,
    scope: Scope,
    user: CurrentUser,
    proof_text: str = Form(...),
    file: UploadFile | None = File(default=None),
) -> AlertOut:
    """Close a directive with evidence of the corrective action taken.

    Multipart because the proof may carry an image. The image is stored through the same
    upload path the vision pipeline uses, but no detection is run on it - here it is
    evidence, not input.
    """
    alert = _load_scoped(db, scope, alert_id)

    proof_path: str | None = None
    if file is not None and file.filename:
        try:
            saved = save_upload(file.filename, await file.read())
            proof_path = saved.name
        except UnsupportedMediaError as exc:
            raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc

    try:
        resolve_directive(
            db, alert,
            proof_text=proof_text,
            resolved_by=user.email,
            proof_image_path=proof_path,
        )
    except DirectiveError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc

    db.commit()
    db.refresh(alert)
    return _to_out(alert, actions_for_alerts(db, [alert.id]).get(alert.id, []))


@router.post("/{alert_id}/reopen", response_model=AlertOut)
def reopen(
    alert_id: int, payload: ReopenRequest, db: DbSession, user: RequireGovernment
) -> AlertOut:
    """Send a directive back because the proof was not sufficient.

    Government-only: the authority that raised it is the one that judges whether it is
    closed. This is what makes the loop two-way rather than a one-way resolve.
    """
    alert = db.get(Alert, alert_id)
    if alert is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, f"Alert {alert_id} not found")

    try:
        reopen_directive(db, alert, reopened_by=user.email, reason=payload.reason)
    except DirectiveError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc

    db.commit()
    db.refresh(alert)
    return _to_out(alert, actions_for_alerts(db, [alert.id]).get(alert.id, []))
