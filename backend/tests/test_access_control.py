"""PRD 4.2 guard: a Mine Head calling another mine's endpoints must get 403, not
filtered-but-served data.

This is the canonical proof that the restriction holds. It used to be demonstrated by a
card on the Mine Head dashboard; that card was a judge-facing prop, not a feature, and
when it was removed this file became the only place the claim is checked end to end.

Individual features assert their own scoping in their own suites. This file exists to be
runnable on its own, in front of someone who asks "prove it":

    pytest tests/test_access_control.py -v
"""

import pytest

from app.core.roles import Role
from app.services.access.scope import MineAccessDenied, MineScope, scope_for

# Every route that is scoped to a single mine. Kept as one list so a new mine-scoped
# endpoint that forgets its guard shows up here rather than in production.
MINE_SCOPED = [
    "/api/v1/mines/{other}",
    "/api/v1/sensors/{other}",
    "/api/v1/sensors/{other}/trend",
    "/api/v1/violations?mine_id={other}",
    "/api/v1/alerts?mine_id={other}",
    "/api/v1/audit?mine_id={other}",
    "/api/v1/sensors/breaches?mine_id={other}",
]

# Cross-mine views. These are authority-only regardless of which mine is asked for.
GOVERNMENT_ONLY = [
    "/api/v1/inspections",
    "/api/v1/sensors",
]


class TestMineHeadCannotReachAnotherMine:
    @pytest.mark.parametrize("path", MINE_SCOPED)
    def test_returns_403(self, api, other_head, accounts, path):
        """403, not an empty list.

        An empty result would render as a mine with no violations and no breaches,
        which is the most dangerous possible wrong answer in a compliance system.
        """
        r = api.get(path.format(other=accounts["mine"].id), headers=other_head)
        assert r.status_code == 403, f"{path} leaked to another mine's head"

    @pytest.mark.parametrize("path", GOVERNMENT_ONLY)
    def test_cross_mine_views_are_government_only(self, api, head, path):
        assert api.get(path, headers=head).status_code == 403

    def test_own_mine_is_still_reachable(self, api, head, accounts):
        """The guard must refuse other mines without locking an operator out of theirs."""
        mine_id = accounts["mine"].id
        for path in MINE_SCOPED:
            r = api.get(path.format(other=mine_id), headers=head)
            assert r.status_code == 200, f"{path} blocked a mine head from their own mine"

    def test_unscoped_listing_returns_only_their_own_mine(self, api, gov, head, accounts):
        """With no mine_id given, a Mine Head is narrowed rather than shown everything."""
        assert len(api.get("/api/v1/mines", headers=gov).json()) > 1
        own = api.get("/api/v1/mines", headers=head).json()
        assert [m["id"] for m in own] == [accounts["mine"].id]

    def test_writes_are_scoped_too(self, api, gov, other_head, accounts):
        """Reads are not the only surface: a Mine Head must not be able to post a
        detection or close a directive against someone else's mine."""
        directive = api.post("/api/v1/alerts/directives", headers=gov,
                             json={"mine_id": accounts["mine"].id}).json()

        resolve = api.post(f"/api/v1/alerts/{directive['id']}/resolve", headers=other_head,
                           data={"proof_text": "not mine to close"})
        assert resolve.status_code == 403

        with open(__file__, "rb") as fh:
            upload = api.post("/api/v1/vision/analyze", headers=other_head,
                              data={"mine_id": accounts["mine"].id},
                              files={"file": ("x.jpg", fh, "image/jpeg")})
        assert upload.status_code == 403


class TestUnauthenticated:
    @pytest.mark.parametrize("path", MINE_SCOPED + GOVERNMENT_ONLY)
    def test_no_token_is_401(self, api, accounts, path):
        r = api.get(path.format(other=accounts["mine"].id))
        assert r.status_code == 401

    def test_a_forged_token_is_rejected(self, api, accounts):
        r = api.get("/api/v1/mines", headers={"Authorization": "Bearer not-a-real-token"})
        assert r.status_code == 401


class TestScopeRuleItself:
    """The rule as a pure object, independent of HTTP."""

    def test_government_is_unrestricted(self):
        scope = scope_for(Role.GOVERNMENT, None)
        assert scope.is_unrestricted
        assert scope.allows(1) and scope.allows(999)

    def test_mine_head_allows_only_their_own(self):
        scope = scope_for(Role.MINE_HEAD, 5)
        assert not scope.is_unrestricted
        assert scope.allows(5)
        assert not scope.allows(6)

    def test_require_raises_rather_than_returning_empty(self):
        scope = MineScope(role=Role.MINE_HEAD, mine_id=5)
        assert scope.require(5) == 5
        with pytest.raises(MineAccessDenied):
            scope.require(6)

    def test_visible_ids_narrows_never_widens(self):
        scope = scope_for(Role.MINE_HEAD, 5)
        assert scope.visible_ids([1, 2, 5, 9]) == [5]
        assert scope.visible_ids([1, 2, 9]) == []

    def test_a_mine_head_without_a_mine_is_a_configuration_error(self):
        """Otherwise a broken account would silently see nothing and look compliant."""
        with pytest.raises(ValueError):
            scope_for(Role.MINE_HEAD, None)
