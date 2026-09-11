"""Audit trail entry schema."""

from datetime import datetime

from pydantic import BaseModel


class AuditLogOut(BaseModel):
    id: int
    mine_id: int | None
    actor: str
    action: str
    entity_type: str
    entity_id: int | None
    detail: str
    created_at: datetime

    model_config = {"from_attributes": True}
