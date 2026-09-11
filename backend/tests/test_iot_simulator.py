"""Sensor breach classification and the replay simulator (PRD 4.2)."""

import csv

import pytest

from app.models.alert import Alert
from app.models.audit_log import AuditLog
from app.models.compliance_score import ComplianceScore
from app.models.sensor_reading import SensorReading
from app.services.compliance.risk import RiskLevel
from app.services.iot.simulator import NoReplayDataError, SensorSimulator
from app.services.iot.thresholds import SensorType, breach_margin, is_breach


class TestThresholds:
    def test_units_and_limits(self):
        assert SensorType.GAS.unit == "ppm"
        assert SensorType.DUST.unit == "mg/m3"
        assert SensorType.TEMPERATURE.unit == "C"

    @pytest.mark.parametrize(
        ("sensor", "value", "expected"),
        [
            (SensorType.GAS, 49.9, False),
            (SensorType.GAS, 50.0, False),   # exactly at the limit is compliant
            (SensorType.GAS, 50.1, True),
            (SensorType.DUST, 10.0, False),
            (SensorType.DUST, 10.1, True),
            (SensorType.TEMPERATURE, 45.0, False),
            (SensorType.TEMPERATURE, 45.1, True),
        ],
    )
    def test_breach_boundaries(self, sensor, value, expected):
        assert is_breach(sensor, value) is expected

    def test_margin_is_zero_when_compliant(self):
        assert breach_margin(SensorType.GAS, 40.0) == 0.0
        assert breach_margin(SensorType.GAS, 60.0) == 10.0

    def test_accepts_string_sensor_type(self):
        assert is_breach("gas", 80.0) is True


@pytest.fixture
def csv_path(tmp_path):
    """A tiny two-tick dataset: tick 1 clean, tick 2 breaches gas on the mine."""

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


class TestSimulator:
    """One tick = every mine reports every sensor once."""

    def test_tick_emits_one_reading_per_sensor(self, db, make_mine, csv_path):
        mine = make_mine()
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))
        tick = sim.tick(commit=False)

        assert tick.total_readings == 3
        assert {r.sensor_type for r in tick.mines[0].readings} == {"gas", "dust", "temperature"}
        assert tick.total_breaches == 0

    def test_full_pass_length_and_exhaustion(self, db, make_mine, csv_path):
        mine = make_mine()
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))
        assert sim.total_ticks == 2

        sim.tick(commit=False)
        assert sim.exhausted is False
        sim.tick(commit=False)
        assert sim.exhausted is True

        # A tick past the end is a no-op, not a crash or a phantom reading.
        spent = sim.tick(commit=False)
        assert spent.total_readings == 0

    def test_loop_rewinds_instead_of_stopping(self, db, make_mine, csv_path):
        mine = make_mine()
        sim = SensorSimulator(db, csv_path=csv_path(mine.id), loop=True)
        for _ in range(2):
            sim.tick(commit=False)
        assert sim.exhausted is True
        assert sim.tick(commit=False).total_readings == 3   # rewound

    def test_breach_lowers_score_raises_alert_and_audits(self, db, make_mine, csv_path):
        mine = make_mine(violations=0, breaches=0)
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))

        clean = sim.tick(commit=False)
        assert clean.total_breaches == 0
        assert clean.mines[0].score_delta == 0.0

        breaching = sim.tick(commit=False)
        mine_tick = breaching.mines[0]
        assert breaching.total_breaches == 1
        assert mine_tick.breaches[0].sensor_type == "gas"
        assert mine_tick.score_delta == -3.0          # 1 breach x weight_env 3
        assert len(mine_tick.alerts) == 1
        assert mine_tick.alerts[0].source == "SENSOR"

        assert db.query(Alert).filter_by(mine_id=mine.id).count() == 1
        assert db.query(AuditLog).filter_by(action="SENSOR_THRESHOLD_BREACHED").count() == 1

    def test_readings_are_stamped_now_not_with_csv_time(self, db, make_mine, csv_path):
        """Replayed readings are new observations, so the trend chart must not jump to 2026-09-01."""
        from datetime import UTC, datetime

        mine = make_mine()
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))
        before = datetime.now(UTC)
        reading = sim.tick(commit=False).mines[0].readings[0]
        stored = reading.recorded_at
        if stored.tzinfo is None:
            stored = stored.replace(tzinfo=UTC)
        assert stored >= before.replace(microsecond=0)

    def test_clean_tick_writes_no_history_point(self, db, make_mine, csv_path):
        """A flat step would clutter the compliance trend chart for no information."""
        mine = make_mine()
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))
        sim.tick(commit=False)
        assert db.query(ComplianceScore).filter_by(mine_id=mine.id).count() == 0
        sim.tick(commit=False)
        assert db.query(ComplianceScore).filter_by(mine_id=mine.id).count() == 1

    def test_breach_can_move_the_risk_band(self, db, make_mine, csv_path):
        """A mine at 81 dropping to 78 - the IoT equivalent of the vision demo moment."""
        mine = make_mine(violations=0, breaches=6, clean_readings=2)  # 100 - 18 = 82, LOW
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))
        sim.tick(commit=False)
        tick = sim.tick(commit=False)
        mine_tick = tick.mines[0]

        assert mine_tick.score_before.risk_level is RiskLevel.LOW
        assert mine_tick.score_after.score == 79.0
        assert mine_tick.score_after.risk_level is RiskLevel.MEDIUM
        assert mine_tick.risk_changed is True
        assert tick.bands_changed == [mine_tick]
        assert db.query(AuditLog).filter_by(action="RISK_LEVEL_CHANGED").count() == 1

    def test_thresholds_are_reapplied_not_taken_from_the_csv(self, db, make_mine, csv_path,
                                                            monkeypatch):
        """Retuning a threshold changes what the simulator reports, with no data regeneration."""
        from app.core import config

        mine = make_mine()
        monkeypatch.setattr(config.settings, "threshold_gas_ppm", 10.0)  # 20.0 now breaches
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))
        assert sim.tick(commit=False).total_breaches == 1

    def test_missing_dataset_is_a_clear_error(self, db, make_mine, tmp_path):
        make_mine()
        with pytest.raises(FileNotFoundError, match="generate_sensor_data"):
            SensorSimulator(db, csv_path=tmp_path / "nope.csv")

    def test_only_selected_mines_are_fed(self, db, make_mine, csv_path):
        first, second = make_mine(), make_mine()
        # The CSV holds rows for `first`; restricting to it must not pull in the other mine.
        sim = SensorSimulator(db, csv_path=csv_path(first.id), mine_ids=[first.id])
        tick = sim.tick(commit=False)
        assert [m.mine_id for m in tick.mines] == [first.id]
        assert tick.total_readings == 3

    def test_dataset_with_no_rows_for_the_selected_mines_is_an_error(self, db, make_mine,
                                                                     csv_path):
        """Silently ticking nothing forever is the worst possible demo-day failure."""
        first, second = make_mine(), make_mine()
        with pytest.raises(NoReplayDataError, match="no readings for mines"):
            SensorSimulator(db, csv_path=csv_path(first.id), mine_ids=[second.id])


def _load_cli():
    """scripts/run_simulator.py as a module - it is a script, not part of the app package."""
    import importlib.util
    from pathlib import Path

    path = Path(__file__).resolve().parents[2] / "scripts" / "run_simulator.py"
    spec = importlib.util.spec_from_file_location("run_simulator_cli", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def cli():
    return _load_cli()


class TestSimulatorCli:
    """How long `scripts/run_simulator.py` runs. `--loop` on its own used to stop after one
    pass (12 ticks), because the tick budget fell back to a full pass whenever --ticks was
    not given - so a looping demo feed quietly went dead after about a minute."""

    @staticmethod
    def ctrl_c_after(n):
        """A stand-in for time.sleep that presses Ctrl+C on the nth wait."""
        waits = []

        def sleep(_):
            waits.append(1)
            if len(waits) == n:
                raise KeyboardInterrupt
        return sleep

    def test_tick_limit(self, cli):
        assert cli.tick_limit(None, loop=False, full_pass=12) == 12    # one pass
        assert cli.tick_limit(None, loop=True, full_pass=12) is None   # until Ctrl+C
        assert cli.tick_limit(5, loop=True, full_pass=12) == 5         # --ticks wins

    def test_loop_keeps_cycling_past_one_pass(self, cli, db, make_mine, csv_path):
        mine = make_mine()
        sim = SensorSimulator(db, csv_path=csv_path(mine.id), loop=True)   # 2 ticks per pass
        limit = cli.tick_limit(None, loop=True, full_pass=sim.total_ticks)

        ran = cli.replay(sim, limit, 0, commit=False, on_tick=lambda _: None,
                         sleep=self.ctrl_c_after(5))
        assert ran == 5
        assert ran > sim.total_ticks

    def test_a_single_pass_still_stops_when_the_data_does(self, cli, db, make_mine, csv_path):
        mine = make_mine()
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))
        limit = cli.tick_limit(None, loop=False, full_pass=sim.total_ticks)

        assert cli.replay(sim, limit, 0, commit=False, on_tick=lambda _: None,
                          sleep=lambda _: None) == 2

    def test_explicit_ticks_without_loop_cannot_outrun_the_data(self, cli, db, make_mine,
                                                                 csv_path):
        mine = make_mine()
        sim = SensorSimulator(db, csv_path=csv_path(mine.id))

        assert cli.replay(sim, cli.tick_limit(10, loop=False, full_pass=sim.total_ticks), 0,
                          commit=False, on_tick=lambda _: None, sleep=lambda _: None) == 2
