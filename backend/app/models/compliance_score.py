"""ComplianceScore ORM: point-in-time score + risk level per mine, giving the historical trend."""

from datetime import datetime

from sqlalchemy import DateTime, Float, ForeignKey, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, utcnow


class ComplianceScore(Base):
    __tablename__ = "compliance_scores"

    id: Mapped[int] = mapped_column(primary_key=True)
    mine_id: Mapped[int] = mapped_column(ForeignKey("mines.id"), index=True)
    score: Mapped[float] = mapped_column(Float)
    risk_level: Mapped[str] = mapped_column(String(16))
    violation_count: Mapped[int] = mapped_column(Integer, default=0)
    breach_count: Mapped[int] = mapped_column(Integer, default=0)

    # The weights in force when this row was written, so a historical score stays explainable
    # even after weight_ppe / weight_env are retuned (PRD 8.1).
    weight_ppe: Mapped[float] = mapped_column(Float)
    weight_env: Mapped[float] = mapped_column(Float)

    computed_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
