"""State filtering across the Government views.

Two filters operate on the same queries and must not be confused: access scope, which is
never optional, and the state selection, which is the user's current view. These cover
both, and the boundary where they meet.
"""

import pytest

from app.api.v1.endpoints.mines import available_states, visible_mines
from app.models.mine import Mine
from app.services.access.scope import MineScope
from app.core.roles import Role


@pytest.fixture
def states(db, make_mine):
    """Mines across three states, with differing risk so ordering is meaningful."""
    rows = [
        ("Odisha", "Angul", 6, 8),        # worst
        ("Odisha", "Jharsuguda", 1, 2),
        ("Jharkhand", "Dhanbad", 3, 4),
        ("Jharkhand", "Bokaro", 0, 0),    # best
        ("Telangana", "Ramagundam", 2, 2),
    ]
    made = []
    for state, district, violations, breaches in rows:
        mine = make_mine(violations=violations, breaches=breaches)
        mine.state = state
        mine.district = district
        mine.location = f"{district}, {state}"
        made.append(mine)
    db.flush()
    return made


class TestStructuredFields:
    def test_state_is_a_column_not_parsed_from_location(self, db, states):
        """Filtering must not depend on how a location string happens to be punctuated."""
        mine = db.query(Mine).filter_by(district="Angul").one()
        assert mine.state == "Odisha"
        assert mine.district == "Angul"
        assert "state" in {c.name for c in Mine.__table__.columns}

    def test_available_states_comes_from_the_data(self, db, states):
        gov = MineScope(role=Role.GOVERNMENT, mine_id=None)
        assert available_states(db, gov) == ["Jharkhand", "Odisha", "Telangana"]

    def test_available_states_excludes_blanks(self, db, states, make_mine):
        make_mine()  # no state set
        gov = MineScope(role=Role.GOVERNMENT, mine_id=None)
        assert "" not in available_states(db, gov)


class TestVisibleMines:
    def test_state_narrows_the_set(self, db, states):
        gov = MineScope(role=Role.GOVERNMENT, mine_id=None)
        assert len(visible_mines(db, gov)) == 5
        assert len(visible_mines(db, gov, "Odisha")) == 2
        assert {m.state for m in visible_mines(db, gov, "Odisha")} == {"Odisha"}

    def test_unknown_state_returns_nothing(self, db, states):
        gov = MineScope(role=Role.GOVERNMENT, mine_id=None)
        assert visible_mines(db, gov, "Kerala") == []

    def test_access_scope_still_wins(self, db, states):
        """A Mine Head filtering by a state they are not in gets nothing, never
        another mine - the state filter narrows, it can never widen."""
        odisha_mine = next(m for m in states if m.district == "Angul")
        head = MineScope(role=Role.MINE_HEAD, mine_id=odisha_mine.id)

        assert [m.id for m in visible_mines(db, head)] == [odisha_mine.id]
        assert [m.id for m in visible_mines(db, head, "Odisha")] == [odisha_mine.id]
        assert visible_mines(db, head, "Jharkhand") == []


class TestDashboardScoping:
    def _dashboard(self, api, gov, **params):
        return api.get("/api/v1/dashboard", headers=gov, params=params).json()

    def test_national_view_caps_the_board_but_not_the_stats(self, api, gov, accounts, db,
                                                            make_mine):
        """The headline numbers cover every mine in scope; the board shows the worst few."""
        for _ in range(8):
            m = make_mine(violations=1, breaches=1)
            m.state = "Odisha"
        db.flush()

        body = self._dashboard(api, gov)
        assert body["is_truncated"] is True
        assert body["showing"] == 5
        assert body["stats"]["mine_count"] == 10       # 2 from accounts + 8 added
        assert body["scope_label"] == "National"

    def test_board_is_worst_first(self, api, gov, accounts, db, make_mine):
        worst = make_mine(violations=9, breaches=9)
        worst.state = "Odisha"
        db.flush()
        body = self._dashboard(api, gov)
        assert body["mines"][0]["id"] == worst.id

    def test_selecting_a_state_shows_all_of_it_and_rescopes_the_stats(self, api, gov, db,
                                                                      make_mine):
        for _ in range(3):
            m = make_mine(violations=0, breaches=0)
            m.state = "Telangana"
        db.flush()

        body = self._dashboard(api, gov, state="Telangana")
        assert body["is_truncated"] is False
        assert body["showing"] == 3
        assert body["stats"]["mine_count"] == 3
        assert body["scope_label"] == "Telangana"
        assert {m["state"] for m in body["mines"]} == {"Telangana"}

    def test_state_stats_differ_from_national(self, api, gov, db, make_mine):
        clean = make_mine(violations=0, breaches=0)
        clean.state = "Telangana"
        dirty = make_mine(violations=8, breaches=8)
        dirty.state = "Odisha"
        db.flush()

        national = self._dashboard(api, gov)["stats"]["average_score"]
        telangana = self._dashboard(api, gov, state="Telangana")["stats"]["average_score"]
        assert telangana > national

    def test_states_list_is_offered_to_government(self, api, gov, db, make_mine):
        m = make_mine()
        m.state = "Odisha"
        db.flush()
        assert "Odisha" in self._dashboard(api, gov)["states"]

    def test_mine_head_is_unaffected(self, api, head, accounts, db):
        """Their scope is one mine, so the state filter cannot change what they see."""
        accounts["mine"].state = "Odisha"
        db.flush()
        body = api.get("/api/v1/dashboard", headers=head).json()
        assert body["stats"]["mine_count"] == 1
        assert body["is_truncated"] is False
        assert body["states"] == []
        assert body["inspection_queue"] == []


class TestOtherTabsRespectState:
    def test_inspections_filter_by_state(self, api, gov, db, make_mine):
        a = make_mine(violations=2, breaches=2); a.state = "Odisha"
        b = make_mine(violations=2, breaches=2); b.state = "Jharkhand"
        db.flush()

        national = api.get("/api/v1/inspections", headers=gov).json()
        scoped = api.get("/api/v1/inspections", headers=gov, params={"state": "Odisha"}).json()
        assert scoped["mine_count"] < national["mine_count"]
        assert all(c["mine_id"] == a.id for c in scoped["candidates"])

    def test_fleet_sensors_filter_by_state(self, api, gov, db, make_mine):
        a = make_mine(); a.state = "Odisha"
        b = make_mine(); b.state = "Jharkhand"
        db.flush()

        scoped = api.get("/api/v1/sensors", headers=gov, params={"state": "Odisha"}).json()
        assert [m["mine_id"] for m in scoped["mines"]] == [a.id]

    def test_breach_buckets_are_scoped_and_refuse_other_mines(self, api, gov, head, accounts, db):
        accounts["mine"].state = "Odisha"
        db.flush()

        assert api.get("/api/v1/sensors/breaches", headers=gov).status_code == 200
        own = api.get("/api/v1/sensors/breaches", headers=head,
                      params={"mine_id": accounts["mine"].id})
        assert own.status_code == 200
        other = api.get("/api/v1/sensors/breaches", headers=head,
                        params={"mine_id": accounts["other_mine"].id})
        assert other.status_code == 403
