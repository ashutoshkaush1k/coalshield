"""Violation log queries, mine-scoped."""

from fastapi import APIRouter, HTTPException, Query, status
from sqlalchemy import select

from app.api.deps import DbSession, Scope
from app.models.violation import Violation
from app.schemas.violation import ViolationOut
from app.services.access.scope import MineAccessDenied

router = APIRouter(prefix="/violations", tags=["violations"])


@router.get("", response_model=list[ViolationOut])
def list_violations(
    db: DbSession,
    scope: Scope,
    mine_id: int | None = Query(default=None),
    limit: int = Query(default=50, le=500),
) -> list[ViolationOut]:
    """PPE violations, newest first, filtered to what the caller may see."""
    if mine_id is not None:
        try:
            scope.require(mine_id)
        except MineAccessDenied as exc:
            raise HTTPException(status.HTTP_403_FORBIDDEN, str(exc)) from exc

    stmt = select(Violation)
    if mine_id is not None:
        stmt = stmt.where(Violation.mine_id == mine_id)
    elif not scope.is_unrestricted:
        stmt = stmt.where(Violation.mine_id == scope.mine_id)

    stmt = stmt.order_by(Violation.detected_at.desc(), Violation.id.desc()).limit(limit)
    return [ViolationOut.model_validate(v) for v in db.scalars(stmt).all()]
