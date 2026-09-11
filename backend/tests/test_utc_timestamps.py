"""Stored times must reach the browser as the instant they were, whatever the server's zone.

SQLite returns every datetime naive even though it was written as UTC. Two things went
wrong with that: a naive `.timestamp()` is read as the server's LOCAL time (so the Trends
windows shifted 5h30m on an IST machine), and an offset-less string is read by `new Date()`
as the BROWSER's local time (so alert, directive and audit times displayed 5h30m early).
"""

import os
import time
from datetime import UTC, datetime

import pytest

from app.models.alert import Alert
from app.models.audit_log import AuditLog
from app.models.sensor_reading import SensorReading
from app.models.violation import Violation
from app.services.iot.fleet_status import breach_buckets

# 17:22 UTC is 22:52 IST - the kind of evening demo time where a 5h30m error is obvious.
T = datetime(2026, 9, 11, 17, 22, tzinfo=UTC)


def ms(*args) -> int:
    return int(datetime(*args, tzinfo=UTC).timestamp() * 1000)


@pytest.fixture
def non_utc_server_zone():
    """Run under a server zone other than UTC, the only place the naive-timestamp bug shows.

    POSIX can switch zones in-process. Windows has no time.tzset, so there the test relies on
    the machine's own zone - IST on the demo laptops, which is exactly where it broke.
    """
    if not hasattr(time, "tzset"):
        yield
        return
    previous = os.environ.get("TZ")
    os.environ["TZ"] = "Asia/Kolkata"
    time.tzset()
    try:
        yield
    finally:
        if previous is None:
            os.environ.pop("TZ", None)
        else:
            os.environ["TZ"] = previous
        time.tzset()


def add_breach(db, mine_id, at):
    db.add(SensorReading(mine_id=mine_id, sensor_type="gas", value=80.0, unit="ppm",
                         recorded_at=at, breached=True))
    db.flush()


class TestTrendsWindowsAreUtcAligned:
    def test_a_reading_lands_in_the_window_it_happened_in(self, db, make_mine, non_utc_server_zone):
        """17:03 UTC belongs to the 12:00-18:00 UTC window. Read as IST it became 11:33 UTC and
        was filed under 06:00, merging with the 11:59 reading into one wrong bar."""
        mine = make_mine()
        add_breach(db, mine.id, datetime(2026, 9, 11, 11, 59, tzinfo=UTC))
        add_breach(db, mine.id, datetime(2026, 9, 11, 17, 3, tzinfo=UTC))

        by_start = {b["start_ms"]: b["breaches"] for b in breach_buckets(db, [mine.id])}
        assert by_start == {ms(2026, 9, 11, 6): 1, ms(2026, 9, 11, 12): 1}

    def test_the_trends_endpoint_serves_the_same_windows(self, api, gov, accounts, db,
                                                          non_utc_server_zone):
        mine = accounts["mine"]
        add_breach(db, mine.id, datetime(2026, 9, 11, 17, 3, tzinfo=UTC))

        buckets = api.get("/api/v1/sensors/breaches", headers=gov,
                          params={"mine_id": mine.id}).json()["buckets"]
        assert [b["start_ms"] for b in buckets] == [ms(2026, 9, 11, 12)]


class TestEventTimestampsAreExplicitUtc:
    """Every event time a dashboard renders comes back ending in Z, at the right instant."""

    def test_alerts(self, api, head, accounts, db):
        db.add(Alert(mine_id=accounts["mine"].id, source="SENSOR", severity="HIGH",
                     message="Gas threshold breached", created_at=T))
        db.flush()

        alert = api.get("/api/v1/alerts", headers=head).json()[0]
        assert alert["created_at"] == "2026-09-11T17:22:00Z"

    def test_directives_and_their_resolutions(self, api, gov, head, accounts):
        mine_id = accounts["mine"].id
        raised = api.post("/api/v1/alerts/directives", headers=gov, json={"mine_id": mine_id}).json()
        assert raised["created_at"].endswith("Z")

        resolved = api.post(f"/api/v1/alerts/{raised['id']}/resolve", headers=head,
                            data={"proof_text": "Fan replaced"}).json()
        proof = resolved["resolutions"][0]
        assert proof["created_at"].endswith("Z")
        assert proof["resolved_at"].endswith("Z")

    def test_audit_trail(self, api, gov, accounts, db):
        db.add(AuditLog(mine_id=accounts["mine"].id, actor="IOT_SIMULATOR",
                        action="SENSOR_THRESHOLD_BREACHED", created_at=T))
        db.flush()

        entry = api.get("/api/v1/audit", headers=gov,
                        params={"mine_id": accounts["mine"].id}).json()[0]
        assert entry["created_at"] == "2026-09-11T17:22:00Z"

    def test_violations(self, api, head, accounts, db):
        db.add(Violation(mine_id=accounts["mine"].id, violation_type="no_helmet",
                         confidence=0.9, frame_ref="f.jpg", detected_at=T))
        db.flush()

        violation = api.get("/api/v1/violations", headers=head).json()[0]
        assert violation["detected_at"] == "2026-09-11T17:22:00Z"
        assert violation["resolved_at"] is None
