"""Alert ORM. Covers both automated findings and government-raised directives. PRD 4.5.

One table, two kinds of alert, separated by `alert_type`:

  SYSTEM     raised by the vision or IoT pipeline when it finds something
  DIRECTIVE  raised by a Government user against a specific mine

`source` keeps its original meaning - which subsystem produced this - so existing
branching on VISION/SENSOR is untouched. Directives carry source GOVERNMENT.

Two lifecycle flags, deliberately distinct:
  acknowledged  someone has seen it
  status        OPEN or RESOLVED - someone has dealt with it, and it can be reopened
"""

from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, utcnow

ALERT_TYPE_SYSTEM = "SYSTEM"
ALERT_TYPE_DIRECTIVE = "DIRECTIVE"

STATUS_OPEN = "OPEN"
STATUS_RESOLVED = "RESOLVED"


class Alert(Base):
    __tablename__ = "alerts"

    id: Mapped[int] = mapped_column(primary_key=True)
    mine_id: Mapped[int] = mapped_column(ForeignKey("mines.id"), index=True)
    source: Mapped[str] = mapped_column(String(16))
    severity: Mapped[str] = mapped_column(String(16), default="MEDIUM")
    message: Mapped[str] = mapped_column(String(255))

    # The violation or sensor reading this alert is about, when there is one.
    reference_id: Mapped[int | None] = mapped_column(Integer, nullable=True)

    alert_type: Mapped[str] = mapped_column(String(16), default=ALERT_TYPE_SYSTEM, index=True)
    status: Mapped[str] = mapped_column(String(16), default=STATUS_OPEN, index=True)

    # Government user who raised a directive. Null for system alerts.
    raised_by: Mapped[str | None] = mapped_column(String(255), nullable=True)

    acknowledged: Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    @property
    def is_directive(self) -> bool:
        return self.alert_type == ALERT_TYPE_DIRECTIVE

    @property
    def is_open(self) -> bool:
        return self.status == STATUS_OPEN
