"""POST /auth/login for both login types; returns a token carrying role + mine_id."""

from fastapi import APIRouter, HTTPException, status
from sqlalchemy import select

from app.api.deps import CurrentUser, DbSession
from app.core.security import create_access_token, verify_password
from app.models.user import User
from app.schemas.auth import CurrentUserOut, LoginRequest, TokenResponse

router = APIRouter(prefix="/auth", tags=["auth"])


@router.post("/login", response_model=TokenResponse)
def login(payload: LoginRequest, db: DbSession) -> TokenResponse:
    """Authenticate a Government or Mine Head account.

    One endpoint for both roles: the account decides the scope, not the login form. A separate
    government login route would be a second place to get access control wrong.
    """
    user = db.scalar(select(User).where(User.email == payload.email))

    # Same error and roughly the same work for an unknown email as for a bad password, so the
    # response does not reveal which accounts exist.
    if user is None or not verify_password(payload.password, user.password_hash):
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Incorrect email or password")

    return TokenResponse(
        access_token=create_access_token(user.email, user.role, user.mine_id),
        user=CurrentUserOut.model_validate(user),
    )


@router.get("/me", response_model=CurrentUserOut)
def me(user: CurrentUser) -> CurrentUserOut:
    """Current identity and scope; the frontend uses this to pick which dashboard to render."""
    return CurrentUserOut.model_validate(user)
