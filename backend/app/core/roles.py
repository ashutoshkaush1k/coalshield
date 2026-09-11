"""Role enum: GOVERNMENT (all mines) and MINE_HEAD (own mine_id only). PRD Section 3."""

from enum import StrEnum


class Role(StrEnum):
    GOVERNMENT = "GOVERNMENT"
    MINE_HEAD = "MINE_HEAD"

    @property
    def is_unrestricted(self) -> bool:
        """Government has no mine_id restriction; every other role is scoped."""
        return self is Role.GOVERNMENT
