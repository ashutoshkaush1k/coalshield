"""Violation ORM: PPE violations from the CV module - mine_id, type, confidence, frame_ref, detected_at."""

from datetime import datetime

from sqlalchemy import Boolean, DateTime, Float, ForeignKey, String
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, utcnow


class Violation(Base):
    __tablename__ = "violations"

    id: Mapped[int] = mapped_column(primary_key=True)
    mine_id: Mapped[int] = mapped_column(ForeignKey("mines.id"), index=True)
    violation_type: Mapped[str] = mapped_column(String(64))
    confidence: Mapped[float] = mapped_column(Float, default=0.0)
    source: Mapped[str] = mapped_column(String(32), default="VISION")
    frame_ref: Mapped[str] = mapped_column(String(255), default="")
    detected_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    # A violation is never deleted - the audit trail has to keep showing it happened.
    # Resolving it only stops it counting against the score, so a mine that fixes a
    # problem can recover instead of carrying it forever.
    resolved: Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    resolved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
