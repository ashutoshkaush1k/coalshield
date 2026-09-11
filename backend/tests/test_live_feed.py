"""The simulator's live feed, as the dashboards see it.

The simulator and the API share nothing but the database, so the thing worth pinning is
that a tick is visible through every sensor endpoint a dashboard polls - the Government
fleet view and the Mine Head's own-mine view alike.
"""

import csv
from datetime import UTC, datetime, timedelta

import pytest

from app.models.sensor_reading import SensorReading
from app.services.iot.live_feed import group_into_ticks
from app.services.iot.simulator import SensorSimulator
from app.services.iot.thresholds import SensorType, is_breach

T0 = datetime(2026, 9, 10, 6, 0, tzinfo=UTC)


def add_tick(db, mine_id, at, gas=20.0, dust=5.0, temperature=30.0, offsets=(0, 0, 0)):
    """One reading per sensor. `offsets` (minutes) mimic the seeded history's skewed clocks."""
    rows = []
    for (sensor, value), minutes in zip(
        ((SensorType.GAS, gas), (SensorType.DUST, dust), (SensorType.TEMPERATURE, temperature)),
        offsets, strict=True,
    ):
        if value is None:
            continue
        row = SensorReading(mine_id=mine_id, sensor_type=sensor.value, value=value,
                            unit=sensor.unit, recorded_at=at + timedelta(minutes=minutes),
                            breached=is_breach(sensor, value))
        db.add(row)
        rows.append(row)
    db.flush()
    return rows


@pytest.fixture
def replay_csv(tmp_path):
    """Two ticks for one mine: tick 1 clean, tick 2 breaches gas."""

    def _build(mine_id: int):
        path = tmp_path / "readings.csv"
        rows = [
            (1, mine_id, "gas", 20.0, "ppm", "2026-09-01T00:00:00+00:00"),
            (2, mine_id, "gas", 80.0, "ppm", "2026-09-01T06:00:00+00:00"),
            (3, mine_id, "dust", 2.0, "mg/m3", "2026-09-01T00:00:00+00:00"),
            (4, mine_id, "dust", 3.0, "mg/m3", "2026-09-01T06:00:00+00:00"),
            (5, mine_id, "temperature", 30.0, "C", "2026-09-01T00:00:00+00:00"),
            (6, mine_id, "temperature", 31.0, "C", "2026-09-01T06:00:00+00:00"),
        ]
        with path.open("w", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh)
            w.writerow(["reading_id", "mine_id", "sensor_type", "value", "unit", "recorded_at"])
            w.writerows(rows)
        return path

    return _build


def fleet_values(api, gov, mine_id):
    mine = next(m for m in api.get("/api/v1/sensors", headers=gov).json()["mines"]
                if m["mine_id"] == mine_id)
    return {s["sensor_type"]: s["value"] for s in mine["sensors"]}


class TestSimulatorReachesEndpoints:
    def test_tick_lands_on_the_government_fleet_view(self, api, gov, accounts, db, replay_csv):
        mine = accounts["mine"]
        sim = SensorSimulator(db, csv_path=replay_csv(mine.id), mine_ids=[mine.id])

        sim.tick(commit=False)
        assert fleet_values(api, gov, mine.id) == {"gas": 20.0, "dust": 2.0, "temperature": 30.0}

        sim.tick(commit=False)
        assert fleet_values(api, gov, mine.id) == {"gas": 80.0, "dust": 3.0, "temperature": 31.0}
        body = api.get("/api/v1/sensors", headers=gov).json()
        assert body["breaching_mines"] == 1

    def test_tick_lands_on_the_mine_heads_own_view(self, api, head, accounts, db, replay_csv):
        mine = accounts["mine"]
        sim = SensorSimulator(db, csv_path=replay_csv(mine.id), mine_ids=[mine.id])
        sim.tick(commit=False)
        sim.tick(commit=False)

        newest = api.get(f"/api/v1/sensors/{mine.id}", headers=head, params={"limit": 3}).json()
        assert {r["sensor_type"]: r["value"] for r in newest} == {
            "gas": 80.0, "dust": 3.0, "temperature": 31.0}

        trend = api.get(f"/api/v1/sensors/{mine.id}/trend", headers=head).json()
        last = {s["sensor_type"]: s["points"][-1]["value"] for s in trend["series"]}
        assert last == {"gas": 80.0, "dust": 3.0, "temperature": 31.0}

    def test_tick_moves_both_dashboards(self, api, gov, head, accounts, db, replay_csv):
        mine = accounts["mine"]
        before_gov = api.get("/api/v1/dashboard", headers=gov).json()["stats"]["total_breaches"]
        before_head = api.get("/api/v1/dashboard", headers=head).json()["stats"]["total_breaches"]

        sim = SensorSimulator(db, csv_path=replay_csv(mine.id), mine_ids=[mine.id])
        sim.tick(commit=False)
        sim.tick(commit=False)              # the gas breach

        assert api.get("/api/v1/dashboard", headers=gov).json()["stats"]["total_breaches"] == before_gov + 1
        assert api.get("/api/v1/dashboard", headers=head).json()["stats"]["total_breaches"] == before_head + 1


class TestTickGrouping:
    def test_simulator_ticks_share_a_timestamp_and_split_on_repeat(self, db, make_mine):
        mine = make_mine()
        rows = add_tick(db, mine.id, T0) + add_tick(db, mine.id, T0 + timedelta(seconds=6))
        ticks = group_into_ticks(rows)
        assert len(ticks) == 2
        assert all(t.is_complete for t in ticks)

    def test_seeded_history_with_skewed_sensor_clocks_groups_per_slot(self, db, make_mine):
        """The seed file stamps each sensor up to 45 minutes apart within a 6-hour slot."""
        mine = make_mine()
        rows = (add_tick(db, mine.id, T0, offsets=(15, 21, 0))
                + add_tick(db, mine.id, T0 + timedelta(hours=6), offsets=(32, 8, 45)))
        ticks = group_into_ticks(rows)
        assert [len(t.readings) for t in ticks] == [3, 3]

    def test_a_missing_sensor_leaves_a_gap_not_a_misaligned_row(self, db, make_mine):
        mine = make_mine()
        rows = (add_tick(db, mine.id, T0, dust=None)
                + add_tick(db, mine.id, T0 + timedelta(hours=6)))
        first, second = group_into_ticks(rows)
        assert first.value("dust") is None and first.is_complete is False
        assert second.is_complete is True


def live(api, headers, mine_id, **params):
    r = api.get(f"/api/v1/sensors/{mine_id}/live", headers=headers, params=params)
    assert r.status_code == 200, r.text
    return r.json()


class TestMineLiveFeed:
    def test_opening_window_is_oldest_first_in_the_agreed_shape(self, api, head, accounts, db):
        mine = accounts["mine"]
        add_tick(db, mine.id, T0, gas=20.0)
        add_tick(db, mine.id, T0 + timedelta(hours=6), gas=80.0, dust=3.0, temperature=31.0)

        body = live(api, head, mine.id)
        assert body["mine_id"] == mine.id
        assert body["reset"] is False
        assert body["thresholds"] == {"gas": 50.0, "dust": 10.0, "temperature": 45.0}
        assert body["units"] == {"gas": "ppm", "dust": "mg/m3", "temperature": "C"}
        assert [r["gas"] for r in body["readings"]] == [20.0, 80.0]

        newest = body["readings"][-1]
        assert set(newest) == {"timestamp", "timestamp_ms", "gas", "dust", "temperature",
                               "breached", "anomaly_score", "is_anomaly"}
        assert newest["breached"] == ["gas"]
        assert newest["timestamp"].endswith("Z")
        assert newest["timestamp_ms"] == int((T0 + timedelta(hours=6)).timestamp() * 1000)

    def test_polling_with_the_cursor_returns_only_new_ticks(self, api, head, accounts, db):
        mine = accounts["mine"]
        add_tick(db, mine.id, T0)
        first = live(api, head, mine.id)

        idle = live(api, head, mine.id, after=first["cursor"])
        assert idle["readings"] == []
        assert idle["cursor"] == first["cursor"]

        add_tick(db, mine.id, T0 + timedelta(seconds=6), gas=61.0)
        fresh = live(api, head, mine.id, after=idle["cursor"])
        assert [r["gas"] for r in fresh["readings"]] == [61.0]
        assert fresh["cursor"] > idle["cursor"]

        assert live(api, head, mine.id, after=fresh["cursor"])["readings"] == []

    def test_simulator_ticks_arrive_one_poll_at_a_time(self, api, head, accounts, db, replay_csv):
        mine = accounts["mine"]
        cursor = live(api, head, mine.id)["cursor"]
        sim = SensorSimulator(db, csv_path=replay_csv(mine.id), mine_ids=[mine.id])

        seen = []
        for _ in range(2):
            sim.tick(commit=False)
            page = live(api, head, mine.id, after=cursor)
            cursor = page["cursor"]
            seen.extend(page["readings"])
        assert [(r["gas"], r["dust"], r["temperature"]) for r in seen] == [
            (20.0, 2.0, 30.0), (80.0, 3.0, 31.0)]
        assert seen[1]["breached"] == ["gas"]

    def test_other_mines_writes_move_the_cursor_but_add_nothing(self, api, head, accounts, db):
        mine, other = accounts["mine"], accounts["other_mine"]
        cursor = live(api, head, mine.id)["cursor"]
        add_tick(db, other.id, T0)
        page = live(api, head, mine.id, after=cursor)
        assert page["readings"] == []

    def test_limit_keeps_the_newest_ticks(self, api, head, accounts, db):
        mine = accounts["mine"]
        for i in range(5):
            add_tick(db, mine.id, T0 + timedelta(hours=6 * i), gas=float(10 + i))
        assert [r["gas"] for r in live(api, head, mine.id, limit=2)["readings"]] == [13.0, 14.0]

    def test_a_cursor_from_before_a_reseed_resets_the_client(self, api, head, accounts, db):
        mine = accounts["mine"]
        add_tick(db, mine.id, T0)
        page = live(api, head, mine.id, after=10_000)
        assert page["reset"] is True
        assert len(page["readings"]) == 1

    def test_mine_head_is_scoped_to_their_own_mine(self, api, head, accounts):
        other = accounts["other_mine"]
        assert api.get(f"/api/v1/sensors/{other.id}/live", headers=head).status_code == 403

    def test_government_can_read_any_mine(self, api, gov, accounts):
        assert api.get(f"/api/v1/sensors/{accounts['other_mine'].id}/live",
                       headers=gov).status_code == 200

    def test_unknown_mine_is_404_and_no_token_is_401(self, api, gov, accounts):
        assert api.get("/api/v1/sensors/9999/live", headers=gov).status_code == 404
        assert api.get(f"/api/v1/sensors/{accounts['mine'].id}/live").status_code == 401


class TestFleetLiveFeed:
    def test_mine_head_gets_403(self, api, head):
        assert api.get("/api/v1/sensors/live", headers=head).status_code == 403

    def test_readings_carry_the_mine_and_sort_by_time(self, api, gov, accounts, db):
        mine, other = accounts["mine"], accounts["other_mine"]
        add_tick(db, other.id, T0)
        add_tick(db, mine.id, T0 + timedelta(hours=6))

        body = api.get("/api/v1/sensors/live", headers=gov).json()
        assert [r["mine_id"] for r in body["readings"]] == [other.id, mine.id]
        assert body["readings"][0]["code"] == other.code
        assert body["readings"][0]["name"] == other.name
        assert "mine_id" not in body

    def test_polling_returns_only_new_ticks_across_the_fleet(self, api, gov, accounts, db):
        mine, other = accounts["mine"], accounts["other_mine"]
        add_tick(db, mine.id, T0)
        add_tick(db, other.id, T0)
        cursor = api.get("/api/v1/sensors/live", headers=gov).json()["cursor"]

        add_tick(db, other.id, T0 + timedelta(seconds=6), gas=77.0)
        page = api.get("/api/v1/sensors/live", headers=gov, params={"after": cursor}).json()
        assert [(r["mine_id"], r["gas"]) for r in page["readings"]] == [(other.id, 77.0)]

    def test_limit_is_per_mine(self, api, gov, accounts, db):
        for mine in (accounts["mine"], accounts["other_mine"]):
            for i in range(3):
                add_tick(db, mine.id, T0 + timedelta(hours=6 * i))
        body = api.get("/api/v1/sensors/live", headers=gov, params={"limit": 2}).json()
        assert len(body["readings"]) == 4

    def test_state_filter_narrows_the_feed(self, api, gov, accounts, db):
        mine, other = accounts["mine"], accounts["other_mine"]
        mine.state, other.state = "Odisha", "Jharkhand"
        add_tick(db, mine.id, T0)
        add_tick(db, other.id, T0)
        body = api.get("/api/v1/sensors/live", headers=gov, params={"state": "Odisha"}).json()
        assert {r["mine_id"] for r in body["readings"]} == {mine.id}


class TestTimestampsAreExplicitUtc:
    """SQLite returns naive datetimes; `new Date()` would read them as local time."""

    def test_existing_sensor_endpoints_now_send_z(self, api, gov, head, accounts, db):
        mine = accounts["mine"]
        add_tick(db, mine.id, T0)

        readings = api.get(f"/api/v1/sensors/{mine.id}", headers=head).json()
        trend = api.get(f"/api/v1/sensors/{mine.id}/trend", headers=head).json()
        fleet = api.get("/api/v1/sensors", headers=gov).json()
        fleet_mine = next(m for m in fleet["mines"] if m["mine_id"] == mine.id)

        stamps = ([r["recorded_at"] for r in readings]
                  + [p["recorded_at"] for s in trend["series"] for p in s["points"]]
                  + [s["recorded_at"] for s in fleet_mine["sensors"]])
        assert stamps and all(s.endswith("Z") for s in stamps)
        assert readings[0]["recorded_at"].startswith("2026-09-10T06:00:00")
