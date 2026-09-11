"""Baseline drift detection (pre-flight guard for the demo).

All-time counting is a deliberate Round 3 scope decision, so demo runs are cumulative. These
tests cover the guard that makes that visible before a run rather than on stage.
"""

import json
from datetime import UTC, datetime, timedelta

import pytest

from app.db import seed as seed_module
from app.db.seed import BaselineReport, MineDrift, baseline_report
from app.models.sensor_reading import SensorReading
from app.models.violation import Violation


@pytest.fixture
def seeded_expectations(monkeypatch, tmp_path):
    """Point the baseline helpers at a tiny synthetic seed: mine expects 1 violation, 2 breaches.

    The seeded readings are stamped now, so they sit inside the scoring window the way
    make_mine's do and both sides of the comparison see the same two breaches.
    """

    def _install(mine_id: int):
        violations = tmp_path / "violations.json"
        violations.write_text(
            json.dumps([{"mine_id": mine_id, "violation_type": "no_helmet"}]), encoding="utf-8"
        )
        now = datetime.now(UTC).isoformat()
        readings = [
            {"mine_id": str(mine_id), "sensor_type": "gas", "value": "80.0", "recorded_at": now},
            {"mine_id": str(mine_id), "sensor_type": "gas", "value": "90.0", "recorded_at": now},
            {"mine_id": str(mine_id), "sensor_type": "gas", "value": "10.0", "recorded_at": now},
        ]
        monkeypatch.setattr(seed_module, "_read_json", lambda name: json.loads(
            violations.read_text(encoding="utf-8")
        ))
        monkeypatch.setattr(seed_module, "_read_readings", lambda path=None: readings)

    return _install


class TestBaselineReport:
    def test_matching_state_is_clean(self, db, make_mine, seeded_expectations):
        mine = make_mine(violations=1, breaches=2)
        seeded_expectations(mine.id)

        report = baseline_report(db)
        assert report.is_clean is True
        assert report.drifted == []
        assert report.bands_changed == []

    def test_extra_violations_are_detected(self, db, make_mine, seeded_expectations):
        mine = make_mine(violations=3, breaches=2)   # seed expects 1
        seeded_expectations(mine.id)

        report = baseline_report(db)
        assert report.is_clean is False
        drift = report.drifted[0]
        assert drift.extra_violations == 2
        assert drift.extra_breaches == 0
        assert drift.score_drop == 10.0              # 2 extra x weight_ppe 5

    def test_extra_breaches_are_detected(self, db, make_mine, seeded_expectations):
        mine = make_mine(violations=1, breaches=6)   # seed expects 2
        seeded_expectations(mine.id)

        drift = baseline_report(db).drifted[0]
        assert drift.extra_breaches == 4
        assert drift.score_drop == 12.0              # 4 extra x weight_env 3

    def test_band_change_is_flagged(self, db, make_mine, seeded_expectations):
        """The case that actually breaks a demo: the board no longer matches the script."""
        mine = make_mine(violations=1, breaches=2)
        seeded_expectations(mine.id)
        baseline = baseline_report(db).mines[0]
        assert baseline.expected_risk == "LOW"       # 100 - 5 - 6 = 89

        for _ in range(6):                            # push it under 80
            db.add(Violation(mine_id=mine.id, violation_type="no_helmet", confidence=0.9))
        db.flush()

        drift = baseline_report(db).mines[0]
        assert drift.band_changed is True
        assert drift.expected_risk == "LOW"
        assert drift.actual_risk == "MEDIUM"
        assert baseline_report(db).bands_changed == [drift]

    def test_breaches_that_have_aged_out_are_not_drift(self, db, make_mine, seeded_expectations):
        """A simulator run from a few minutes ago leaves breach rows behind, but they no longer
        cost anything - the board matches the baseline again, so the guard must say so."""
        mine = make_mine(violations=1, breaches=2)
        seeded_expectations(mine.id)
        for _ in range(5):
            db.add(SensorReading(mine_id=mine.id, sensor_type="gas", value=80.0, unit="ppm",
                                 breached=True,
                                 recorded_at=datetime.now(UTC) - timedelta(minutes=10)))
        db.flush()

        assert baseline_report(db).is_clean is True

    def test_report_covers_every_mine_not_just_drifted_ones(self, db, make_mine,
                                                            seeded_expectations):
        first = make_mine(violations=1, breaches=2)
        make_mine(violations=1, breaches=2)
        seeded_expectations(first.id)

        report = baseline_report(db)
        assert len(report.mines) == 2

    def test_clean_report_helpers(self):
        empty = BaselineReport(mines=[])
        assert empty.is_clean is True
        assert empty.drifted == []


class TestMineDrift:
    def _drift(self, **kw) -> MineDrift:
        base = dict(
            mine_id=1, code="T-01", name="Test",
            expected_violations=1, actual_violations=1,
            expected_breaches=2, actual_breaches=2,
            expected_score=89.0, actual_score=89.0,
            expected_risk="LOW", actual_risk="LOW",
        )
        return MineDrift(**{**base, **kw})

    def test_is_clean_requires_both_counts_to_match(self):
        assert self._drift().is_clean is True
        assert self._drift(actual_violations=2).is_clean is False
        assert self._drift(actual_breaches=3).is_clean is False

    def test_score_drop_is_positive_when_degraded(self):
        assert self._drift(actual_score=76.0).score_drop == 13.0
