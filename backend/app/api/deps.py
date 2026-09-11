"""Auth dependencies: get_current_user, require_role(...), and resolve_mine_scope() - the single
choke point enforcing PRD 4.2 server-side."""

from typing import Annotated

from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.roles import Role
from app.core.security import decode_access_token
from app.db.session import get_db
from app.models.user import User
from app.services.access.scope import MineScope, scope_for

_bearer = HTTPBearer(auto_error=False)

DbSession = Annotated[Session, Depends(get_db)]


def get_current_user(
    db: DbSession,
    credentials: Annotated[HTTPAuthorizationCredentials | None, Depends(_bearer)] = None,
) -> User:
    """Resolve the caller from the bearer token, or 401."""
    if credentials is None:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Not authenticated")

    claims = decode_access_token(credentials.credentials)
    if claims is None:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Invalid or expired token")

    user = db.scalar(select(User).where(User.email == claims.get("sub")))
    if user is None:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Account no longer exists")
    return user


CurrentUser = Annotated[User, Depends(get_current_user)]


def resolve_mine_scope(user: CurrentUser) -> MineScope:
    """Derive the caller's mine scope from the database record.

    Deliberately read from the user row rather than the token claims: if an account is re-mapped
    to a different mine, the change takes effect immediately instead of when the token expires.
    """
    return scope_for(user.role, user.mine_id)


Scope = Annotated[MineScope, Depends(resolve_mine_scope)]


def require_role(*allowed: Role):
    """Dependency factory for endpoints restricted to specific roles."""

    def _guard(user: CurrentUser) -> User:
        if user.role_enum not in allowed:
            raise HTTPException(
                status.HTTP_403_FORBIDDEN,
                f"This endpoint requires one of: {', '.join(r.value for r in allowed)}",
            )
        return user

    return _guard


RequireGovernment = Annotated[User, Depends(require_role(Role.GOVERNMENT))]
RequireMineHead = Annotated[User, Depends(require_role(Role.MINE_HEAD))]
