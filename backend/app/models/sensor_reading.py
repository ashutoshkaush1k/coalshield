"""SensorReading ORM: mine_id, sensor_type (gas/dust/temperature), value, unit, recorded_at, breached flag."""

from datetime import datetime

from sqlalchemy import Boolean, DateTime, Float, ForeignKey, Index, String
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, utcnow


class SensorReading(Base):
    __tablename__ = "sensor_readings"

    id: Mapped[int] = mapped_column(primary_key=True)
    mine_id: Mapped[int] = mapped_column(ForeignKey("mines.id"), index=True)
    sensor_type: Mapped[str] = mapped_column(String(32), index=True)
    value: Mapped[float] = mapped_column(Float)
    unit: Mapped[str] = mapped_column(String(16))
    recorded_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    # Classified once at ingest by services/iot/thresholds, so scoring is a count and not a re-scan.
    breached: Mapped[bool] = mapped_column(Boolean, default=False, index=True)

    # Same accumulation problem as violations: a breach counted against the score forever
    # would mean no mine could ever recover from a bad shift. The field exists and scoring
    # honours it, but nothing sets it yet - see the note in docs/architecture.md on why a
    # single clean reading is not sufficient evidence to clear a breach history.
    resolved: Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    resolved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    __table_args__ = (Index("ix_readings_mine_breached", "mine_id", "breached", "resolved"),)
