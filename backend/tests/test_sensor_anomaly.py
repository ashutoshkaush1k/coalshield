"""Sensor anomaly model: training, scoring, and the additive fields on the sensor endpoints.

The contract that matters most is what the model must NOT do: it never changes a breach,
an alert or a score. Those still come from the thresholds alone.
"""

import sys
from datetime import UTC, datetime, timedelta

import pytest

from app.core import config
from app.services.iot import anomaly
from tests.test_live_feed import add_tick

T0 = datetime(2026, 9, 10, 6, 0, tzinfo=UTC)
TYPICAL = dict(gas=25.0, dust=5.0, temperature=33.0)
EXTREME = dict(gas=85.0, dust=20.0, temperature=56.0)


@pytest.fixture(scope="module", autouse=True)
def default_model(tmp_path_factory):
    """Pin the model to the defaults, trained in memory from the committed seed data.

    Points the weights path at a file that does not exist, so a locally retrained model
    with different settings cannot change what these tests see.
    """
    with pytest.MonkeyPatch.context() as mp:
        mp.setattr(config.settings, "sensor_anomaly_model_path",
                   str(tmp_path_factory.mktemp("weights") / "absent.joblib"))
        anomaly.reset_scorer()
        yield
    anomaly.reset_scorer()


class TestTraining:
    def test_seed_data_trains_on_every_tick(self):
        bundle = anomaly.train(anomaly.ticks_from_csv())
        assert bundle["features"] == ["gas", "dust", "temperature"]
        assert bundle["n_training_ticks"] == 888          # 74 mines x 12 ticks
        assert 0.5 < bundle["threshold"] < 1.0

    def test_training_is_reproducible(self):
        ticks = anomaly.ticks_from_csv()
        first, second = anomaly.train(ticks), anomaly.train(ticks)
        rows = [anomaly.feature_row(t) for t in ticks[:50]]
        assert (anomaly.AnomalyScorer(first).score_rows(rows)
                == anomaly.AnomalyScorer(second).score_rows(rows))

    def test_save_and_load_round_trip(self, tmp_path):
        bundle = anomaly.train(anomaly.ticks_from_csv())
        path = anomaly.save(bundle, tmp_path / "m.joblib")
        loaded = anomaly.AnomalyScorer.load(path)
        assert loaded.threshold == anomaly.AnomalyScorer(bundle).threshold

    def test_a_model_with_other_features_is_refused(self, tmp_path):
        bundle = anomaly.train(anomaly.ticks_from_csv())
        bundle["features"] = ["dust", "gas", "temperature"]
        path = anomaly.save(bundle, tmp_path / "m.joblib")
        with pytest.raises(ValueError, match="features"):
            anomaly.AnomalyScorer.load(path)


class TestScoring:
    def score(self, **values):
        scorer = anomaly.get_scorer()
        return scorer.score_rows([[values["gas"], values["dust"], values["temperature"]]])[0]

    def test_an_extreme_multi_sensor_tick_is_flagged(self):
        scorer = anomaly.get_scorer()
        assert scorer.is_anomaly(self.score(**EXTREME)) is True

    def test_a_typical_tick_is_not(self):
        scorer = anomaly.get_scorer()
        assert scorer.is_anomaly(self.score(**TYPICAL)) is False

    def test_the_score_is_graded_not_just_a_flag(self):
        """Every sensor just under its limit breaches nothing but is still unusual."""
        near = self.score(gas=49.9, dust=9.9, temperature=44.9)
        assert self.score(**TYPICAL) < near < self.score(**EXTREME)

    def test_an_incomplete_tick_is_not_guessed_at(self):
        assert anomaly.get_scorer().score_rows([None]) == [None]

    def test_missing_weights_fall_back_to_training_in_memory(self):
        assert anomaly.get_scorer().bundle["source"].startswith("seed csv")

    def test_unreadable_weights_fall_back_too(self, tmp_path, monkeypatch):
        bad = tmp_path / "corrupt.joblib"
        bad.write_bytes(b"not a model")
        monkeypatch.setattr(config.settings, "sensor_anomaly_model_path", str(bad))
        anomaly.reset_scorer()
        try:
            assert anomaly.get_scorer() is not None
        finally:
            anomaly.reset_scorer()


def live(api, headers, mine_id):
    return api.get(f"/api/v1/sensors/{mine_id}/live", headers=headers).json()


class TestEndpointsCarryTheScore:
    def test_live_feed_scores_each_tick(self, api, head, accounts, db):
        mine = accounts["mine"]
        add_tick(db, mine.id, T0, **TYPICAL)
        add_tick(db, mine.id, T0 + timedelta(hours=6), **EXTREME)
        add_tick(db, mine.id, T0 + timedelta(hours=12), dust=None)   # partial

        body = live(api, head, mine.id)
        typical, extreme, partial = body["readings"]
        assert body["anomaly_threshold"] == anomaly.get_scorer().threshold
        assert typical["is_anomaly"] is False and 0 < typical["anomaly_score"] < 1
        assert extreme["is_anomaly"] is True
        assert extreme["anomaly_score"] >= body["anomaly_threshold"]
        assert partial["anomaly_score"] is None and partial["is_anomaly"] is None

    def test_fleet_live_feed_scores_too(self, api, gov, accounts, db):
        add_tick(db, accounts["mine"].id, T0, **EXTREME)
        body = api.get("/api/v1/sensors/live", headers=gov).json()
        assert body["anomaly_threshold"] is not None
        assert body["readings"][0]["is_anomaly"] is True

    def test_fleet_standing_scores_the_latest_tick(self, api, gov, accounts, db):
        mine, other = accounts["mine"], accounts["other_mine"]
        add_tick(db, mine.id, T0, **EXTREME)
        add_tick(db, other.id, T0, **TYPICAL)

        body = api.get("/api/v1/sensors", headers=gov).json()
        by_id = {m["mine_id"]: m for m in body["mines"]}
        assert by_id[mine.id]["is_anomaly"] is True
        assert by_id[other.id]["is_anomaly"] is False
        assert body["anomalous_mines"] == 1

    def test_per_sensor_rows_share_their_ticks_score(self, api, head, accounts, db):
        mine = accounts["mine"]
        add_tick(db, mine.id, T0, **TYPICAL)
        add_tick(db, mine.id, T0 + timedelta(hours=6), **EXTREME, offsets=(0, 20, 40))

        rows = api.get(f"/api/v1/sensors/{mine.id}", headers=head, params={"limit": 3}).json()
        assert len({r["anomaly_score"] for r in rows}) == 1        # one tick, one score
        assert rows[0]["anomaly_score"] >= anomaly.get_scorer().threshold

        # Filtered to a single sensor, the row still carries the whole tick's score.
        gas_only = api.get(f"/api/v1/sensors/{mine.id}", headers=head,
                           params={"sensor_type": "gas", "limit": 1}).json()
        assert gas_only[0]["anomaly_score"] == rows[0]["anomaly_score"]

        trend = api.get(f"/api/v1/sensors/{mine.id}/trend", headers=head).json()
        latest = {s["sensor_type"]: s["points"][-1]["anomaly_score"] for s in trend["series"]}
        assert set(latest.values()) == {rows[0]["anomaly_score"]}


class TestItIsAdditiveOnly:
    def test_breaches_still_come_from_the_thresholds(self, api, head, accounts, db):
        """A flagged tick breaches exactly what the thresholds say, and a clean one nothing."""
        mine = accounts["mine"]
        add_tick(db, mine.id, T0, **TYPICAL)
        add_tick(db, mine.id, T0 + timedelta(hours=6), **EXTREME)
        typical, extreme = live(api, head, mine.id)["readings"]
        assert typical["breached"] == []
        assert extreme["breached"] == ["gas", "dust", "temperature"]

    def test_the_fleet_order_ignores_the_model(self, api, gov, accounts, db):
        """Worst-first is still by threshold severity, not anomaly score."""
        mine, other = accounts["mine"], accounts["other_mine"]
        add_tick(db, mine.id, T0, gas=52.0, dust=5.0, temperature=33.0)   # mild breach, not anomalous
        add_tick(db, other.id, T0, gas=49.9, dust=9.9, temperature=44.9)  # no breach, elevated score

        mines = api.get("/api/v1/sensors", headers=gov).json()["mines"]
        assert mines[0]["mine_id"] == mine.id
        assert mines[0]["breaching_now"] == 1 and mines[1]["breaching_now"] == 0

    def test_without_scikit_learn_the_fields_are_null_and_nothing_breaks(
        self, api, gov, head, accounts, db, monkeypatch
    ):
        monkeypatch.setitem(sys.modules, "sklearn", None)     # import sklearn -> ImportError
        anomaly.reset_scorer()
        try:
            mine = accounts["mine"]
            add_tick(db, mine.id, T0, **EXTREME)
            body = live(api, head, mine.id)
            assert body["anomaly_threshold"] is None
            assert body["readings"][0]["anomaly_score"] is None
            assert body["readings"][0]["breached"] == ["gas", "dust", "temperature"]

            fleet = api.get("/api/v1/sensors", headers=gov).json()
            assert fleet["anomalous_mines"] == 0
            assert all(m["anomaly_score"] is None for m in fleet["mines"])
            assert api.get(f"/api/v1/sensors/{mine.id}", headers=head).status_code == 200
        finally:
            anomaly.reset_scorer()
