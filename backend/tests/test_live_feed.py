"""The simulator's live feed, as the dashboards see it.

The simulator and the API share nothing but the database, so the thing worth pinning is
that a tick is visible through every sensor endpoint a dashboard polls - the Government
fleet view and the Mine Head's own-mine view alike.
"""

import csv

import pytest

from app.services.iot.simulator import SensorSimulator


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
