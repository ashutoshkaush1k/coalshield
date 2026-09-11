"""Applies the mine_id filter to every query. Pure, unit-testable, mirrors deps.resolve_mine_scope.

PRD 4.2 requires access control at the query level, not the UI. Keeping the rule here as a plain
object means it can be asserted directly in tests, and there is exactly one definition of what a
role is allowed to see.
"""

from dataclasses import dataclass
from typing import Iterable

from app.core.roles import Role


class MineAccessDenied(PermissionError):
    """Raised when a caller asks for a mine outside its scope."""

    def __init__(self, mine_id: int) -> None:
        super().__init__(f"Access to mine {mine_id} is not permitted for this account")
        self.mine_id = mine_id


@dataclass(frozen=True)
class MineScope:
    """The set of mines a caller may read.

    Government: unrestricted. Mine Head: exactly one mine.
    """

    role: Role
    mine_id: int | None

    @property
    def is_unrestricted(self) -> bool:
        return self.role.is_unrestricted

    def allows(self, mine_id: int) -> bool:
        return self.is_unrestricted or mine_id == self.mine_id

    def require(self, mine_id: int) -> int:
        """Return the mine id, or raise if it is out of scope.

        Raises rather than returning an empty result: an empty violation list would read as a
        clean mine, which is the most dangerous possible wrong answer in a compliance system.
        """
        if not self.allows(mine_id):
            raise MineAccessDenied(mine_id)
        return mine_id

    def visible_ids(self, all_ids: Iterable[int]) -> list[int]:
        """Narrow a set of candidate mine ids down to what this caller may see."""
        if self.is_unrestricted:
            return list(all_ids)
        return [i for i in all_ids if i == self.mine_id]


def scope_for(role: Role | str, mine_id: int | None) -> MineScope:
    """Build a scope from a role and the mine mapping stored on the user."""
    role = Role(role)
    if role is Role.MINE_HEAD and mine_id is None:
        # A Mine Head with no mine would otherwise fall through to seeing nothing, which hides a
        # broken account behind an empty dashboard.
        raise ValueError("A MINE_HEAD account must be mapped to a mine_id")
    return MineScope(role=role, mine_id=None if role.is_unrestricted else mine_id)
