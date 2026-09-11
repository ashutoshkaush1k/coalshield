"""Mine ORM: mine_id, name, location, region, operator - the unit of access scoping."""

from sqlalchemy import String
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base


class Mine(Base):
    __tablename__ = "mines"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(32), unique=True, index=True)
    name: Mapped[str] = mapped_column(String(120))
    # Free-text label kept for display ("Angul, Odisha"). Filtering never parses it:
    # state and district below are the structured fields, so a query cannot break on a
    # mine whose location string is punctuated differently.
    location: Mapped[str] = mapped_column(String(120))
    district: Mapped[str] = mapped_column(String(80), default="")
    state: Mapped[str] = mapped_column(String(80), default="", index=True)
    region: Mapped[str] = mapped_column(String(80))
    operator: Mapped[str] = mapped_column(String(120), default="")

    users: Mapped[list["User"]] = relationship(back_populates="mine")
