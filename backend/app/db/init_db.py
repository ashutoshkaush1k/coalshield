"""Create all tables on first run."""

from sqlalchemy.engine import Engine

import app.models  # noqa: F401  - registers every model on Base.metadata
from app.db.base import Base
from app.db.session import engine as default_engine


def init_db(engine: Engine | None = None) -> None:
    """Create any missing tables. Safe to call repeatedly."""
    Base.metadata.create_all(bind=engine or default_engine)


def drop_all(engine: Engine | None = None) -> None:
    """Drop every table. Used by --reset on the seeder and by test fixtures."""
    Base.metadata.drop_all(bind=engine or default_engine)
