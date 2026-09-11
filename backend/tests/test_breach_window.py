"""Rolling breach window: sensor penalties age out, so scores fall AND recover on their own.

PPE violations are deliberately not windowed - they still need a clean re-inspection - so several
tests pin that the window touches only the environmental half of the formula. The window is the
shipped 0.01h (36s), pinned for every test by conftest.
"""

import csv
import json
from collections import Counter
from datetime import UTC, datetime, timedelta

import pytest

from app.core import config
from app.core.config import SEED_DIR
from app.models.audit_log import AuditLog
from app.models.compliance_score import ComplianceScore
from app.models.sensor_reading import SensorReading
from app.models.violation import Violation
from app.services.compliance.risk import RiskLevel
from app.services.compliance.scoring import (
    compute_compliance_score,
    record_score,
    score_mine,
    score_mines,
)
from app.services.iot.simulator import SensorSimulator

T0 = datetime(2026, 9, 12, 10, 0, tzinfo=UTC)
WINDOW = timedelta(seconds=36)


def breach(db, mine_id, at, resolved=False):
    db.add(SensorReading(mine_id=mine_id, sensor_type="gas", value=80.0, unit="ppm",
                         recorded_at=at, breached=True, resolved=resolved))
    db.flush()


def seconds(n: float) -> datetime:
    return T0 + timedelta(seconds=n)


class TestOnlyInWindowBreachesCount:
    def test_a_sensor_only_mine_is_penalised_while_its_breaches_are_recent(
        self, db, make_mine, weights
    ):
        mine = make_mine()
        breach(db, mine.id, seconds(0))
        breach(db, mine.id, seconds(6))

        result = score_mine(db, mine.id, weights, now=seconds(10))
        assert (result.violation_count, result.breach_count) == (0, 2)
        assert result.environmental_penalty == 6.0
        assert result.score == 94.0

    def test_breaches_outside_the_window_do_not_count(self, db, make_mine, weights):
        mine = make_mine()
        breach(db, mine.id, T0 - timedelta(minutes=5))       # long gone
        breach(db, mine.id, T0 - timedelta(seconds=37))      # aged out a second ago
        breach(db, mine.id, T0 - timedelta(seconds=35))      # still inside

        result = score_mine(db, mine.id, weights, now=T0)
        assert result.breach_count == 1
        assert result.score == 97.0

    def test_a_breach_exactly_at_the_window_edge_still_counts(self, db, make_mine, weights):
        mine = make_mine()
        breach(db, mine.id, T0 - WINDOW)
        assert score_mine(db, mine.id, weights, now=T0).breach_count == 1

    def test_ppe_violations_are_not_windowed(self, db, make_mine, weights):
        """A ten-day-old violation still counts: only a clean re-inspection clears those."""
        mine = make_mine()
        db.add(Violation(mine_id=mine.id, violation_type="no_helmet", confidence=0.9,
                         detected_at=T0 - timedelta(days=10)))
        db.flush()

        result = score_mine(db, mine.id, weights, now=T0)
        assert (result.violation_count, result.score) == (1, 95.0)

    def test_resolved_breaches_still_do_not_count(self, db, make_mine, weights):
        mine = make_mine()
        breach(db, mine.id, seconds(0), resolved=True)
        assert score_mine(db, mine.id, weights, now=seconds(1)).breach_count == 0

    def test_a_zero_window_counts_every_breach(self, db, make_mine, weights, monkeypatch):
        monkeypatch.setattr(config.settings, "breach_window_hours", 0)
        mine = make_mine()
        breach(db, mine.id, T0 - timedelta(days=30))

        result = score_mine(db, mine.id, weights, now=T0)
        assert result.breach_count == 1
        assert result.breach_window_hours is None

    def test_the_result_says_which_window_it_used(self, db, make_mine, weights):
        mine = make_mine()
        assert score_mine(db, mine.id, weights, now=T0).breach_window_hours == 0.01

    def test_the_batch_path_applies_the_same_window(self, db, make_mine, weights):
        first, second = make_mine(), make_mine()
        breach(db, first.id, T0 - timedelta(minutes=5))
        breach(db, second.id, T0 - timedelta(seconds=5))

        batch = score_mines(db, [first.id, second.id], weights, now=T0)
        assert batch[first.id].score == score_mine(db, first.id, weights, now=T0).score == 100.0
        assert batch[second.id].score == score_mine(db, second.id, weights, now=T0).score == 97.0


class TestScoreRecoversAsTimeAdvances:
    def test_the_same_records_score_higher_as_the_clock_moves(self, db, make_mine, weights):
        """Nothing is written, resolved or deleted between these reads - only time passes."""
        mine = make_mine()
        for t in (0, 12, 24):
            breach(db, mine.id, seconds(t))

        at = [score_mine(db, mine.id, weights, now=seconds(t)).score for t in (25, 37, 49, 61)]
        assert at == [91.0, 94.0, 97.0, 100.0]

    def test_recovery_stops_at_the_ppe_floor(self, db, make_mine, weights):
        mine = make_mine(violations=2)
        breach(db, mine.id, seconds(0))
        assert score_mine(db, mine.id, weights, now=seconds(1)).score == 87.0
        assert score_mine(db, mine.id, weights, now=seconds(40)).score == 90.0   # not 100

    def test_a_new_breach_pulls_a_recovering_mine_back_down(self, db, make_mine, weights):
        mine = make_mine()
        breach(db, mine.id, seconds(0))
        assert score_mine(db, mine.id, weights, now=seconds(40)).score == 100.0
        breach(db, mine.id, seconds(45))
        assert score_mine(db, mine.id, weights, now=seconds(46)).score == 97.0


class Clock:
    def __init__(self, start: datetime):
        self.now = start

    def __call__(self) -> datetime:
        return self.now

    def advance(self, secs: float) -> None:
        self.now += timedelta(seconds=secs)


@pytest.fixture
def replay(tmp_path):
    """A replay dataset with the given gas value per tick; dust and temperature stay clean."""

    def _build(mine_id: int, gas_per_tick: list[float]):
        path = tmp_path / "readings.csv"
        with path.open("w", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh)
            w.writerow(["reading_id", "mine_id", "sensor_type", "value", "unit", "recorded_at"])
            n = 0
            for i, gas in enumerate(gas_per_tick):
                for sensor, value, unit in (("gas", gas, "ppm"), ("dust", 2.0, "mg/m3"),
                                            ("temperature", 30.0, "C")):
                    n += 1
                    w.writerow([n, mine_id, sensor, value, unit, f"2026-09-01T{i:02d}:00:00+00:00"])
        return path

    return _build


def history(db, mine_id) -> list[float]:
    rows = db.query(ComplianceScore).filter_by(mine_id=mine_id).order_by(ComplianceScore.id)
    return [r.score for r in rows]


class TestSimulatorRescoresEveryTick:
    def test_a_tick_that_only_ages_out_a_breach_records_the_recovery(self, db, make_mine, replay):
        mine = make_mine()
        clock = Clock(T0)
        sim = SensorSimulator(db, csv_path=replay(mine.id, [80.0, 20.0]),
                              mine_ids=[mine.id], clock=clock)

        hit = sim.tick(commit=False).mines[0]
        assert hit.score_after.score == 97.0

        clock.advance(40)                                   # past the 36s window
        calm = sim.tick(commit=False).mines[0]
        assert calm.breaches == []
        assert calm.recovered is True
        assert calm.movement == 3.0
        assert calm.score_after.score == 100.0
        assert history(db, mine.id) == [97.0, 100.0]        # the recovery is on the trend line

    def test_band_changes_are_audited_in_both_directions(self, db, make_mine, replay):
        mine = make_mine(violations=4)                       # 80: exactly on the Low line
        clock = Clock(T0)
        sim = SensorSimulator(db, csv_path=replay(mine.id, [80.0, 20.0]),
                              mine_ids=[mine.id], clock=clock)

        assert sim.tick(commit=False).mines[0].score_after.risk_level is RiskLevel.MEDIUM   # 77
        clock.advance(40)
        assert sim.tick(commit=False).mines[0].score_after.risk_level is RiskLevel.LOW      # 80

        details = [a.detail for a in db.query(AuditLog)
                   .filter_by(mine_id=mine.id, action="RISK_LEVEL_CHANGED").order_by(AuditLog.id)]
        assert len(details) == 2
        assert details[0].startswith("LOW -> MEDIUM (80 -> 77)")
        assert details[1].startswith("MEDIUM -> LOW (77 -> 80)")
        assert "aged out" in details[1]

    def test_a_score_another_path_already_recorded_is_not_recorded_again(
        self, db, make_mine, replay
    ):
        """A PPE upload records its own point; the next clean tick must not duplicate it."""
        mine = make_mine(violations=1)
        record_score(db, mine.id, score_mine(db, mine.id))  # what the vision path writes: 95
        sim = SensorSimulator(db, csv_path=replay(mine.id, [20.0]), mine_ids=[mine.id],
                              clock=Clock(T0))

        assert sim.tick(commit=False).mines[0].moved is False
        assert history(db, mine.id) == [95.0]

    def test_looping_can_no_longer_grind_a_mine_to_zero(self, db, make_mine, replay):
        """A gas breach on every tick, forever. All-time scoring reached 10 after 30 ticks; the
        window caps the penalty at one window's worth of ticks (seven at exactly 6s apart,
        counting the one on the edge)."""
        mine = make_mine()
        clock = Clock(T0)
        sim = SensorSimulator(db, csv_path=replay(mine.id, [80.0, 80.0]), mine_ids=[mine.id],
                              clock=clock, loop=True)

        scores = []
        for _ in range(30):
            scores.append(sim.tick(commit=False).mines[0].score_after.score)
            clock.advance(6)
        assert scores[:3] == [97.0, 94.0, 91.0]
        assert set(scores[10:]) == {79.0}                    # flat: breaches age out as fast as they land


class TestTheApiServesWindowedScores:
    def test_mine_detail_and_dashboard_count_only_in_window_breaches(self, api, gov, accounts, db):
        mine = accounts["mine"]
        now = datetime.now(UTC)
        breach(db, mine.id, now - timedelta(minutes=10))     # aged out
        breach(db, mine.id, now - timedelta(seconds=5))      # counts

        compliance = api.get(f"/api/v1/mines/{mine.id}", headers=gov).json()["compliance"]
        assert compliance["breach_count"] == 1
        assert compliance["score"] == 97.0
        assert compliance["breach_window_hours"] == 0.01

        stats = api.get("/api/v1/dashboard", headers=gov).json()["stats"]
        assert stats["total_breaches"] == 1
        assert stats["breach_window_hours"] == 0.01


class TestTheCommittedSeedStillSpreadsTheBoard:
    """Guards the seed retune: under the window the opening board is set by violations alone,
    so they have to carry the three-band spread the cross-mine story depends on."""

    def test_every_seeded_breach_has_aged_out(self):
        with (SEED_DIR / "sensor_readings.csv").open(encoding="utf-8") as fh:
            newest = max(datetime.fromisoformat(r["recorded_at"]) for r in csv.DictReader(fh))
        assert newest < datetime.now(UTC) - timedelta(days=1)

    def test_all_three_bands_and_the_named_mines_hold_their_bands(self, weights):
        counts = Counter(v["mine_id"] for v in json.loads(
            (SEED_DIR / "violations.json").read_text(encoding="utf-8")))
        mine_ids = [m["id"] for m in json.loads((SEED_DIR / "mines.json").read_text(encoding="utf-8"))]
        board = {m: compute_compliance_score(counts[m], 0, weights) for m in mine_ids}

        assert {r.risk_level for r in board.values()} == set(RiskLevel)
        assert {m: board[m].risk_level for m in range(1, 6)} == {
            1: RiskLevel.LOW, 2: RiskLevel.LOW, 3: RiskLevel.MEDIUM,
            4: RiskLevel.MEDIUM, 5: RiskLevel.HIGH,
        }
        assert counts[1] == 0                  # Jharia: penalised by its sensors alone
        assert board[2].score == 80.0          # Singrauli: one live PPE detection tips it to Medium
