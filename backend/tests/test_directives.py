"""Government directives and the proof-backed resolution loop.

The access rules matter more than the happy path here: a directive is an authority
instructing an operator, so only Government raises and reopens, and a Mine Head must
never see or touch another mine's directives.
"""

import io

from app.models.alert import ALERT_TYPE_DIRECTIVE, STATUS_OPEN, STATUS_RESOLVED


def raise_one(api, gov, mine_id, message="Fix the ventilation on the east face", severity="HIGH"):
    return api.post(
        "/api/v1/alerts/directives",
        headers=gov,
        json={"mine_id": mine_id, "message": message, "severity": severity},
    )


def png_bytes() -> bytes:
    return b"\x89PNG\r\n\x1a\n" + b"0" * 64


class TestRaising:
    def test_government_can_raise_a_directive(self, api, gov, accounts):
        mine = accounts["mine"]
        r = raise_one(api, gov, mine.id)
        assert r.status_code == 201

        body = r.json()
        assert body["alert_type"] == ALERT_TYPE_DIRECTIVE
        assert body["status"] == STATUS_OPEN
        assert body["source"] == "GOVERNMENT"
        assert body["raised_by"] == "gov@test.gov"
        assert body["severity"] == "HIGH"
        assert body["resolutions"] == []

    def test_mine_head_cannot_raise_a_directive(self, api, head, accounts):
        r = api.post(
            "/api/v1/alerts/directives",
            headers=head,
            json={"mine_id": accounts["mine"].id, "message": "Please ignore my violations"},
        )
        assert r.status_code == 403

    def test_unauthenticated_cannot_raise_a_directive(self, api, accounts):
        r = api.post("/api/v1/alerts/directives",
                     json={"mine_id": accounts["mine"].id, "message": "x"})
        assert r.status_code == 401

    def test_a_blank_message_falls_back_to_the_generated_one(self, api, gov, accounts):
        """Blank used to be rejected. Now it is the one-click path: the Government UI
        sends no message at all, so an empty one means "describe the mine for me"
        rather than a mistake to refuse."""
        r = raise_one(api, gov, accounts["mine"].id, message="   ")
        assert r.status_code == 201
        assert "Flagged by" in r.json()["message"]

    def test_directive_against_a_missing_mine_is_404(self, api, gov):
        r = raise_one(api, gov, 9999)
        assert r.status_code == 404

    def test_reference_id_links_to_the_finding(self, api, gov, accounts):
        r = api.post("/api/v1/alerts/directives", headers=gov, json={
            "mine_id": accounts["mine"].id, "message": "About violation 7", "reference_id": 7})
        assert r.json()["reference_id"] == 7


class TestVisibilityAndScope:
    def test_mine_head_sees_directives_for_their_own_mine(self, api, gov, head, accounts):
        raise_one(api, gov, accounts["mine"].id)
        alerts = api.get("/api/v1/alerts", headers=head).json()
        directives = [a for a in alerts if a["alert_type"] == ALERT_TYPE_DIRECTIVE]
        assert len(directives) == 1
        assert directives[0]["raised_by"] == "gov@test.gov"

    def test_mine_head_cannot_see_another_mines_directives(self, api, gov, other_head, accounts):
        raise_one(api, gov, accounts["mine"].id)
        alerts = api.get("/api/v1/alerts", headers=other_head).json()
        assert alerts == []

    def test_mine_head_cannot_request_another_mines_alerts(self, api, gov, other_head, accounts):
        raise_one(api, gov, accounts["mine"].id)
        r = api.get("/api/v1/alerts", headers=other_head,
                    params={"mine_id": accounts["mine"].id})
        assert r.status_code == 403

    def test_directives_sort_above_system_alerts(self, api, gov, head, accounts, db):
        """A person is waiting on a directive; a breach alert is already in the score."""
        from app.models.alert import Alert

        db.add(Alert(mine_id=accounts["mine"].id, source="SENSOR", severity="HIGH",
                     message="Gas threshold breached"))
        db.flush()
        raise_one(api, gov, accounts["mine"].id)

        alerts = api.get("/api/v1/alerts", headers=head).json()
        assert alerts[0]["alert_type"] == ALERT_TYPE_DIRECTIVE


class TestResolveWithProof:
    def test_mine_head_resolves_with_text_proof(self, api, gov, head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]

        r = api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head,
                     data={"proof_text": "Ventilation fan replaced and airflow re-tested."})
        assert r.status_code == 200

        body = r.json()
        assert body["status"] == STATUS_RESOLVED
        assert len(body["resolutions"]) == 1
        assert body["resolutions"][0]["created_by"] == "head1@test.in"
        assert "Ventilation fan replaced" in body["resolutions"][0]["description"]
        assert body["resolutions"][0]["resolved_at"] is not None

    def test_resolving_with_an_image_stores_a_viewable_path(self, api, gov, head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]

        r = api.post(
            f"/api/v1/alerts/{alert_id}/resolve", headers=head,
            data={"proof_text": "Guard rail reinstated, photo attached."},
            files={"file": ("proof.png", io.BytesIO(png_bytes()), "image/png")},
        )
        assert r.status_code == 200
        url = r.json()["resolutions"][0]["proof_image_url"]
        assert url and url.startswith("/static/proof/")

    def test_proof_text_is_required(self, api, gov, head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        r = api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head,
                     data={"proof_text": "   "})
        assert r.status_code == 422
        assert api.get("/api/v1/alerts", headers=head).json()[0]["status"] == STATUS_OPEN

    def test_unsupported_proof_file_is_rejected(self, api, gov, head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        r = api.post(
            f"/api/v1/alerts/{alert_id}/resolve", headers=head,
            data={"proof_text": "See attached"},
            files={"file": ("notes.txt", io.BytesIO(b"hello"), "text/plain")},
        )
        assert r.status_code == 422

    def test_mine_head_cannot_resolve_another_mines_directive(self, api, gov, other_head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        r = api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=other_head,
                     data={"proof_text": "not mine to close"})
        assert r.status_code == 403

    def test_resolving_twice_is_rejected(self, api, gov, head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head, data={"proof_text": "done"})
        again = api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head,
                         data={"proof_text": "done again"})
        assert again.status_code == 422

    def test_resolved_directive_drops_off_the_outstanding_alert_count(
        self, api, db, gov, head, accounts
    ):
        """`acknowledged` and `status` are separate flags, set by different actions.
        The grid count has to honour both or a closed directive keeps showing as
        outstanding on the Government board."""
        from app.api.v1.endpoints.mines import open_alert_counts

        mine_id = accounts["mine"].id
        alert_id = raise_one(api, gov, mine_id).json()["id"]
        assert open_alert_counts(db, [mine_id]).get(mine_id, 0) == 1

        api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head, data={"proof_text": "fixed"})
        assert open_alert_counts(db, [mine_id]).get(mine_id, 0) == 0


class TestGovernmentReview:
    def test_government_sees_the_proof(self, api, gov, head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head,
                 data={"proof_text": "Scrubber serviced, readings back in range."},
                 files={"file": ("proof.jpg", io.BytesIO(png_bytes()), "image/jpeg")})

        seen = [a for a in api.get("/api/v1/alerts", headers=gov).json() if a["id"] == alert_id][0]
        assert seen["status"] == STATUS_RESOLVED
        assert "Scrubber serviced" in seen["resolutions"][0]["description"]
        assert seen["resolutions"][0]["proof_image_url"].startswith("/static/proof/")

    def test_government_can_reopen(self, api, gov, head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head, data={"proof_text": "done"})

        r = api.post(f"/api/v1/alerts/{alert_id}/reopen", headers=gov,
                     json={"reason": "Photograph does not show the affected face"})
        assert r.status_code == 200
        assert r.json()["status"] == STATUS_OPEN

    def test_mine_head_cannot_reopen(self, api, gov, head, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head, data={"proof_text": "done"})
        r = api.post(f"/api/v1/alerts/{alert_id}/reopen", headers=head, json={"reason": "no"})
        assert r.status_code == 403

    def test_reopening_an_open_directive_is_rejected(self, api, gov, accounts):
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        r = api.post(f"/api/v1/alerts/{alert_id}/reopen", headers=gov, json={"reason": "x"})
        assert r.status_code == 422

    def test_reopen_then_resolve_keeps_both_attempts(self, api, gov, head, accounts):
        """The reason proof lives in corrective_actions: a second attempt must not
        overwrite the first, because Government needs to compare them."""
        alert_id = raise_one(api, gov, accounts["mine"].id).json()["id"]
        api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head,
                 data={"proof_text": "First attempt, swept the area"})
        api.post(f"/api/v1/alerts/{alert_id}/reopen", headers=gov, json={"reason": "not enough"})
        r = api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head,
                     data={"proof_text": "Second attempt, replaced the extractor"})

        descriptions = [x["description"] for x in r.json()["resolutions"]]
        assert len(descriptions) == 2
        assert any("First attempt" in d for d in descriptions)
        assert any("Second attempt" in d for d in descriptions)


class TestOneClickFlag:
    """The Government side sends only a mine id; the server writes the record.

    Composed server-side rather than in the browser because the drill-down polls on an
    interval - a message the page assembled could describe a score several seconds old.
    """

    def flag(self, api, gov, mine_id, **extra):
        return api.post("/api/v1/alerts/directives", headers=gov,
                        json={"mine_id": mine_id, **extra})

    def test_a_bare_mine_id_is_enough(self, api, gov, accounts):
        r = self.flag(api, gov, accounts["mine"].id)
        assert r.status_code == 201

        body = r.json()
        assert body["alert_type"] == ALERT_TYPE_DIRECTIVE
        assert body["status"] == STATUS_OPEN
        assert body["source"] == "GOVERNMENT"
        assert body["raised_by"] == "gov@test.gov"
        assert body["message"]

    def test_message_describes_the_mine_at_click_time(self, api, gov, db, make_mine):
        mine = make_mine(violations=6, breaches=8)          # 100 - 30 - 24 = 46
        db.flush()

        message = self.flag(api, gov, mine.id).json()["message"]
        assert "Flagged by Gov" in message
        assert "score 46" in message
        assert "High Risk" in message
        assert "6 violations" in message
        assert "8 breaches" in message

    def test_severity_follows_the_risk_band(self, api, gov, db, make_mine):
        high = make_mine(violations=6, breaches=8)           # 46 -> HIGH
        medium = make_mine(violations=3, breaches=5)         # 70 -> MEDIUM
        low = make_mine(violations=0, breaches=0)            # 100 -> LOW
        db.flush()

        assert self.flag(api, gov, high.id).json()["severity"] == "HIGH"
        assert self.flag(api, gov, medium.id).json()["severity"] == "MEDIUM"
        assert self.flag(api, gov, low.id).json()["severity"] == "LOW"

    def test_a_clean_mine_reads_sensibly(self, api, gov, db, make_mine):
        """No findings is still a meaningful record, not a sentence with a hole in it."""
        mine = make_mine(violations=0, breaches=0)
        db.flush()
        message = self.flag(api, gov, mine.id).json()["message"]
        assert "score 100" in message
        assert "no open findings" in message

    def test_reference_id_is_null_for_a_mine_level_flag(self, api, gov, accounts):
        assert self.flag(api, gov, accounts["mine"].id).json()["reference_id"] is None

    def test_reference_id_is_kept_when_something_is_in_focus(self, api, gov, accounts):
        assert self.flag(api, gov, accounts["mine"].id, reference_id=42).json()["reference_id"] == 42

    def test_an_explicit_message_still_wins(self, api, gov, accounts):
        """The typed path is not removed from the API, only from the UI."""
        body = self.flag(api, gov, accounts["mine"].id,
                         message="Ventilation on the east face", severity="HIGH").json()
        assert body["message"] == "Ventilation on the east face"
        assert body["severity"] == "HIGH"

    def test_mine_head_still_cannot_flag(self, api, head, accounts):
        r = api.post("/api/v1/alerts/directives", headers=head,
                     json={"mine_id": accounts["mine"].id})
        assert r.status_code == 403

    def test_flagging_an_unknown_mine_is_404(self, api, gov):
        assert self.flag(api, gov, 9999).status_code == 404

    def test_the_resolution_loop_is_unchanged(self, api, gov, head, accounts):
        """Only creation was simplified - resolve with proof and reopen still apply."""
        alert_id = self.flag(api, gov, accounts["mine"].id).json()["id"]

        resolved = api.post(f"/api/v1/alerts/{alert_id}/resolve", headers=head,
                            data={"proof_text": "Ventilation restored"})
        assert resolved.status_code == 200
        assert resolved.json()["status"] == STATUS_RESOLVED

        reopened = api.post(f"/api/v1/alerts/{alert_id}/reopen", headers=gov,
                            json={"reason": "not sufficient"})
        assert reopened.json()["status"] == STATUS_OPEN

    def test_mine_head_sees_it_tagged_as_a_directive(self, api, gov, head, accounts):
        self.flag(api, gov, accounts["mine"].id)
        feed = api.get("/api/v1/alerts", headers=head).json()
        directives = [a for a in feed if a["alert_type"] == ALERT_TYPE_DIRECTIVE]
        assert len(directives) == 1
        assert directives[0]["source"] == "GOVERNMENT"
        assert "Flagged by" in directives[0]["message"]
