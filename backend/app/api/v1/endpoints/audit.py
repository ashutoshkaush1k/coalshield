"""Audit trail: cross-mine for Government, own-mine for Mine Head."""

from fastapi import APIRouter, HTTPException, Query, status
from sqlalchemy import select

from app.api.deps import DbSession, Scope
from app.models.audit_log import AuditLog
from app.schemas.audit import AuditLogOut
from app.services.access.scope import MineAccessDenied

router = APIRouter(prefix="/audit", tags=["audit"])


@router.get("", response_model=list[AuditLogOut])
def list_audit(
    db: DbSession,
    scope: Scope,
    mine_id: int | None = Query(default=None),
    action: str | None = Query(default=None),
    limit: int = Query(default=100, le=1000),
) -> list[AuditLogOut]:
    """Immutable event log, newest first (PRD 4 should-have 6)."""
    if mine_id is not None:
        try:
            scope.require(mine_id)
        except MineAccessDenied as exc:
            raise HTTPException(status.HTTP_403_FORBIDDEN, str(exc)) from exc

    stmt = select(AuditLog)
    if mine_id is not None:
        stmt = stmt.where(AuditLog.mine_id == mine_id)
    elif not scope.is_unrestricted:
        # Entries with no mine are system-wide; a Mine Head sees only its own site's history.
        stmt = stmt.where(AuditLog.mine_id == scope.mine_id)
    if action:
        stmt = stmt.where(AuditLog.action == action)

    stmt = stmt.order_by(AuditLog.created_at.desc(), AuditLog.id.desc()).limit(limit)
    return [AuditLogOut.model_validate(a) for a in db.scalars(stmt).all()]
