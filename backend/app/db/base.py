"""Declarative Base for every ORM model.

Model modules import Base from here. The reverse import - Base pulling in models - lives in
init_db.py, which keeps this module free of circular imports.
"""

from datetime import UTC, datetime

from sqlalchemy.orm import DeclarativeBase


class Base(DeclarativeBase):
    pass


def utcnow() -> datetime:
    """Timezone-aware default for created/recorded timestamps."""
    return datetime.now(UTC)
