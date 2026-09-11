"""Replays the seeded dataset on a timer so the demo behaves like a live feed.

One tick = every mine reports every sensor once, which is how real telemetry arrives: the gas,
dust and temperature probes on a site all report each cycle. With 12 readings per sensor per mine
in the seed, a full replay is 12 ticks.

Readings are stamped with the current time, not the CSV timestamp - they are genuinely new
readings from the same sensors, so trend charts and the audit trail stay honest.

IMPORTANT: compliance scoring counts every breach on record, so each replay pass permanently adds
to a mine's breach count. One pass is a good demo (scores fall, bands shift). Looping is available
but will drive every mine to zero over time - see `docs/architecture.md` on rolling-window scoring.
"""

from __future__ import annotations

import csv
from collections import defaultdict, deque
from dataclasses import dataclass, field
from datetime import UTC, datetime
from pathlib import Path

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.config import SEED_DIR
from app.models.alert import Alert
from app.models.mine import Mine
from app.models.sensor_reading import SensorReading
from app.services.alerts.engine import alert_for_breach
from app.services.audit.recorder import record
from app.services.compliance.scoring import ComplianceResult, record_score, score_mine
from app.services.iot.thresholds import SensorType, is_breach


@dataclass
class MineTick:
    """What one mine reported in one tick, and what it cost."""

    mine_id: int
    code: str
    readings: list[SensorReading] = field(default_factory=list)
    alerts: list[Alert] = field(default_factory=list)
    score_before: ComplianceResult | None = None
    score_after: ComplianceResult | None = None

    @property
    def breaches(self) -> list[SensorReading]:
        return [r for r in self.readings if r.breached]

    @property
    def score_delta(self) -> float:
        if self.score_before is None or self.score_after is None:
            return 0.0
        return round(self.score_after.score - self.score_before.score, 1)

    @property
    def risk_changed(self) -> bool:
        if self.score_before is None or self.score_after is None:
            return False
        return self.score_before.risk_level is not self.score_after.risk_level


@dataclass
class TickResult:
    index: int
    mines: list[MineTick] = field(default_factory=list)

    @property
    def total_readings(self) -> int:
        return sum(len(m.readings) for m in self.mines)

    @property
    def total_breaches(self) -> int:
        return sum(len(m.breaches) for m in self.mines)

    @property
    def bands_changed(self) -> list[MineTick]:
        return [m for m in self.mines if m.risk_changed]


class NoReplayDataError(ValueError):
    """Raised when the dataset holds no readings for any of the selected mines.

    Without this the simulator would report itself exhausted before the first tick and sit there
    doing nothing - the failure mode most likely to waste time on demo day.
    """


class SensorSimulator:
    """Feeds seeded readings into the database one tick at a time.

    Deliberately timer-free: `tick()` advances the stream by exactly one cycle and the caller owns
    the clock. That keeps the replay logic unit-testable without sleeping, and lets the same class
    back a CLI, a background task, or a "step" button in the UI.
    """

    def __init__(
        self,
        db: Session,
        csv_path: str | Path | None = None,
        loop: bool = False,
        mine_ids: list[int] | None = None,
    ):
        self.db = db
        self.csv_path = Path(csv_path or SEED_DIR / "sensor_readings.csv")
        self.loop = loop
        self.tick_index = 0

        self._mines = {
            m.id: m
            for m in db.scalars(select(Mine).order_by(Mine.id)).all()
            if mine_ids is None or m.id in mine_ids
        }
        self._queues: dict[tuple[int, str], deque[dict]] = {}
        self._source: dict[tuple[int, str], list[dict]] = defaultdict(list)
        self._load()

    def _load(self) -> None:
        if not self.csv_path.exists():
            raise FileNotFoundError(
                f"{self.csv_path} not found. Run: python scripts/generate_sensor_data.py"
            )
        with self.csv_path.open(encoding="utf-8") as fh:
            for row in csv.DictReader(fh):
                mine_id = int(row["mine_id"])
                if mine_id in self._mines:
                    self._source[(mine_id, row["sensor_type"])].append(row)
        if not self._source:
            raise NoReplayDataError(
                f"{self.csv_path} contains no readings for mines "
                f"{sorted(self._mines)}. Regenerate the dataset, or check the mine ids."
            )
        self._queues = {key: deque(rows) for key, rows in self._source.items()}

    @property
    def mines(self) -> list[Mine]:
        """Mines this simulator is feeding, in id order."""
        return list(self._mines.values())

    @property
    def exhausted(self) -> bool:
        return all(len(q) == 0 for q in self._queues.values())

    @property
    def total_ticks(self) -> int:
        """How many ticks a full pass takes: the longest per-sensor queue."""
        return max((len(rows) for rows in self._source.values()), default=0)

    @property
    def remaining_ticks(self) -> int:
        return max((len(q) for q in self._queues.values()), default=0)

    def _rewind(self) -> None:
        self._queues = {key: deque(rows) for key, rows in self._source.items()}

    def tick(self, commit: bool = True) -> TickResult:
        """Emit one reading per sensor per mine and apply the consequences.

        Mirrors the vision module exactly: persist the observation, raise alerts, write the audit
        entry, then recompute the score from scratch and append a history point.
        """
        if self.exhausted:
            if not self.loop:
                return TickResult(index=self.tick_index)
            self._rewind()

        self.tick_index += 1
        now = datetime.now(UTC)
        result = TickResult(index=self.tick_index)

        for mine_id, mine in self._mines.items():
            mine_tick = MineTick(mine_id=mine_id, code=mine.code)
            mine_tick.score_before = score_mine(self.db, mine_id)

            for sensor_type in SensorType:
                queue = self._queues.get((mine_id, sensor_type.value))
                if not queue:
                    continue
                row = queue.popleft()
                value = float(row["value"])
                # Re-classified against the live thresholds rather than trusting the CSV, so
                # retuning a threshold changes what the simulator reports without regenerating data.
                breached = is_breach(sensor_type, value)
                reading = SensorReading(
                    mine_id=mine_id,
                    sensor_type=sensor_type.value,
                    value=value,
                    unit=sensor_type.unit,
                    recorded_at=now,
                    breached=breached,
                )
                self.db.add(reading)
                self.db.flush()
                mine_tick.readings.append(reading)

                if breached:
                    mine_tick.alerts.append(alert_for_breach(self.db, reading))
                    record(
                        self.db,
                        "SENSOR_THRESHOLD_BREACHED",
                        mine_id=mine_id,
                        actor="IOT_SIMULATOR",
                        entity_type="SensorReading",
                        entity_id=reading.id,
                        detail=(
                            f"{sensor_type.value} {value}{sensor_type.unit} exceeded "
                            f"{sensor_type.limit}{sensor_type.unit}"
                        ),
                    )

            mine_tick.score_after = score_mine(self.db, mine_id)

            # Only record history when a breach actually landed - a clean tick should not add a
            # flat step to the trend chart, same rule the vision module follows.
            if mine_tick.breaches:
                record_score(self.db, mine_id, mine_tick.score_after)
                if mine_tick.risk_changed:
                    record(
                        self.db,
                        "RISK_LEVEL_CHANGED",
                        mine_id=mine_id,
                        actor="IOT_SIMULATOR",
                        entity_type="Mine",
                        entity_id=mine_id,
                        detail=(
                            f"{mine_tick.score_before.risk_level.value} -> "
                            f"{mine_tick.score_after.risk_level.value} "
                            f"({mine_tick.score_before.score} -> {mine_tick.score_after.score})"
                        ),
                    )

            result.mines.append(mine_tick)

        if commit:
            self.db.commit()
        return result
