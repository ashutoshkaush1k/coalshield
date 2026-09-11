"""Alert read/create schemas, covering system alerts and government directives."""

from datetime import datetime

from pydantic import BaseModel, Field


class ResolutionOut(BaseModel):
    """One proof-backed resolution attempt against a directive."""

    id: int
    description: str
    created_by: str
    created_at: datetime
    resolved_at: datetime | None
    proof_image_url: str | None = None

    model_config = {"from_attributes": True}


class AlertOut(BaseModel):
    id: int
    mine_id: int
    source: str
    severity: str
    message: str
    reference_id: int | None
    alert_type: str
    status: str
    raised_by: str | None
    acknowledged: bool
    created_at: datetime

    # Every resolution attempt, newest first. A reopened directive keeps its earlier
    # proof here rather than overwriting it.
    resolutions: list[ResolutionOut] = []

    model_config = {"from_attributes": True}


class DirectiveCreate(BaseModel):
    mine_id: int

    # Both optional. Omitted, they are generated from the mine's state at the moment of
    # the request, which is what the one-click "Flag for inspection" action sends.
    message: str | None = Field(default=None, max_length=255)
    severity: str | None = None

    # Link to the violation or sensor reading that prompted the flag, when one is in
    # focus. Null means a general, mine-level flag.
    reference_id: int | None = None


class ReopenRequest(BaseModel):
    reason: str = ""
