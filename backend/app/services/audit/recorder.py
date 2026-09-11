"""Single writer for AuditLog entries; called by vision, IoT, and action flows."""

from __future__ import annotations

from sqlalchemy.orm import Session

from app.models.audit_log import AuditLog


def record(
    db: Session,
    action: str,
    *,
    mine_id: int | None = None,
    actor: str = "SYSTEM",
    entity_type: str = "",
    entity_id: int | None = None,
    detail: str = "",
) -> AuditLog:
    """Append one immutable audit entry.

    Everything that changes a compliance score goes through here, so the trail can answer "why did
    this mine drop 10 points at 14:32" without reconstructing it from the violations table.
    """
    entry = AuditLog(
        mine_id=mine_id,
        actor=actor,
        action=action,
        entity_type=entity_type,
        entity_id=entity_id,
        detail=detail,
    )
    db.add(entry)
    db.flush()
    return entry
