"""User ORM: email, password_hash, role, nullable mine_id (NULL = Government, set = Mine Head)."""

from sqlalchemy import ForeignKey, String
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.roles import Role
from app.db.base import Base


class User(Base):
    __tablename__ = "users"

    id: Mapped[int] = mapped_column(primary_key=True)
    email: Mapped[str] = mapped_column(String(255), unique=True, index=True)
    password_hash: Mapped[str] = mapped_column(String(255))
    full_name: Mapped[str] = mapped_column(String(120), default="")
    role: Mapped[str] = mapped_column(String(32))

    # NULL means unrestricted (Government). A Mine Head maps to exactly one mine. PRD 4.2.
    mine_id: Mapped[int | None] = mapped_column(ForeignKey("mines.id"), nullable=True)

    mine: Mapped["Mine | None"] = relationship(back_populates="users")

    @property
    def role_enum(self) -> Role:
        return Role(self.role)
