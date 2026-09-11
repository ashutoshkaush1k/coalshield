"""LoginRequest, TokenResponse, and the current-user payload carrying role + mine_id."""

from pydantic import BaseModel, EmailStr


class LoginRequest(BaseModel):
    email: EmailStr
    password: str


class CurrentUserOut(BaseModel):
    id: int
    email: str
    full_name: str
    role: str
    mine_id: int | None

    model_config = {"from_attributes": True}


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    user: CurrentUserOut
