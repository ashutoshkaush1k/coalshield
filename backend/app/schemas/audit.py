"""Audit trail entry schema."""

from pydantic import BaseModel

from app.utils.datetimes import UTCDateTime


class AuditLogOut(BaseModel):
    id: int
    mine_id: int | None
    actor: str
    action: str
    entity_type: str
    entity_id: int | None
    detail: str
    created_at: UTCDateTime

    model_config = {"from_attributes": True}
