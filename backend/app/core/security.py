"""Password hashing (PBKDF2-HMAC-SHA256) and JWT encode/decode. PRD 4.2: identity is proven server-side.

Uses stdlib hashlib rather than passlib/bcrypt: passlib 1.7.x imports the `crypt` module, which was
removed in Python 3.13, and bcrypt needs a native wheel. PBKDF2 is in the standard library, works on
every machine the team will demo from, and is appropriate for a prototype. Production would use bcrypt
or argon2 (PRD: production-grade auth is explicitly out of scope).
"""

import hashlib
import hmac
import secrets
from datetime import UTC, datetime, timedelta
from typing import Any

import jwt

from app.core.config import settings

_ALGORITHM = "sha256"
_ITERATIONS = 120_000


def hash_password(password: str) -> str:
    """Return `pbkdf2_sha256$<iterations>$<salt_hex>$<hash_hex>`."""
    salt = secrets.token_bytes(16)
    digest = hashlib.pbkdf2_hmac(_ALGORITHM, password.encode(), salt, _ITERATIONS)
    return f"pbkdf2_{_ALGORITHM}${_ITERATIONS}${salt.hex()}${digest.hex()}"


def verify_password(password: str, stored: str) -> bool:
    """Constant-time comparison against a stored hash; False on any malformed input."""
    try:
        _, iterations, salt_hex, digest_hex = stored.split("$")
        expected = hashlib.pbkdf2_hmac(
            _ALGORITHM, password.encode(), bytes.fromhex(salt_hex), int(iterations)
        )
    except (ValueError, AttributeError):
        return False
    return hmac.compare_digest(expected.hex(), digest_hex)


def create_access_token(subject: str, role: str, mine_id: int | None) -> str:
    """Mint a token carrying role and mine scope.

    Scope travels in the signed token, never in a client-supplied parameter — this is what makes
    the PRD 4.2 restriction impossible to bypass from the frontend.
    """
    now = datetime.now(UTC)
    payload = {
        "sub": subject,
        "role": role,
        "mine_id": mine_id,
        "iat": now,
        "exp": now + timedelta(minutes=settings.access_token_expire_minutes),
    }
    return jwt.encode(payload, settings.jwt_secret, algorithm=settings.jwt_algorithm)


def decode_access_token(token: str) -> dict[str, Any] | None:
    """Return the claims, or None if the token is invalid or expired."""
    try:
        return jwt.decode(token, settings.jwt_secret, algorithms=[settings.jwt_algorithm])
    except jwt.PyJWTError:
        return None
