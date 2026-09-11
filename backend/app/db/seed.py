"""Seed demo state: 3-5 mines, one Government user, one Mine Head per mine, 150-200 sensor readings.

Reads the files produced by scripts/generate_sensor_data.py. Generation and loading are kept apart
on purpose: the data set can be regenerated and reviewed in git without touching the database, and
the database can be rebuilt without reshuffling the data.
"""

from __future__ import annotations

import csv
import json
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core.config import SEED_DIR
from app.core.security import hash_password
from app.models.audit_log import AuditLog
from app.models.mine import Mine
from app.models.sensor_reading import SensorReading
from app.models.user import User
from app.models.violation import Violation
from app.services.alerts.engine import alert_for_breach, alert_for_violation
from app.services.compliance.scoring import breach_window_start, record_score, score_mine
from app.services.iot.thresholds import SensorType, is_breach
from app.utils.datetimes import as_utc


class SeedDataMissingError(FileNotFoundError):
    """Raised when the seed files have not been generated yet."""


def _read_json(name: str) -> list[dict]:
    path = SEED_DIR / name
    if not path.exists():
        raise SeedDataMissingError(
            f"{path} not found. Run: python scripts/generate_sensor_data.py"
        )
    return json.loads(path.read_text(encoding="utf-8"))


def _read_readings(path: Path | None = None) -> list[dict]:
    path = path or SEED_DIR / "sensor_readings.csv"
    if not path.exists():
        raise SeedDataMissingError(
            f"{path} not found. Run: python scripts/generate_sensor_data.py"
        )
    with path.open(encoding="utf-8") as fh:
        rows = list(csv.DictReader(fh))
    if not rows:
        raise SeedDataMissingError(f"{path} has a header but no rows.")
    return rows


def is_seeded(db: Session) -> bool:
    return (db.scalar(select(func.count()).select_from(Mine)) or 0) > 0


def seed_database(db: Session) -> dict[str, int]:
    """Insert mines, users, readings and violations, then compute each mine's opening score.

    Assumes empty tables - scripts/seed_db.py handles resetting.
    """
    summary: dict[str, int] = {}

    mines = _read_json("mines.json")
    db.add_all(Mine(**m) for m in mines)
    db.flush()
    summary["mines"] = len(mines)

    users = _read_json("users.json")
    db.add_all(
        User(
            email=u["email"],
            password_hash=hash_password(u["password"]),
            full_name=u["full_name"],
            role=u["role"],
            mine_id=u["mine_id"],
        )
        for u in users
    )
    summary["users"] = len(users)

    readings = _read_readings()
    breach_count = 0
    for row in readings:
        sensor_type = SensorType(row["sensor_type"])
        value = float(row["value"])
        breached = is_breach(sensor_type, value)
        breach_count += breached
        db.add(
            SensorReading(
                mine_id=int(row["mine_id"]),
                sensor_type=sensor_type.value,
                value=value,
                unit=row["unit"],
                recorded_at=datetime.fromisoformat(row["recorded_at"]),
                breached=breached,
            )
        )
    summary["sensor_readings"] = len(readings)
    summary["breaches"] = breach_count

    violations = _read_json("violations.json")
    db.add_all(
        Violation(
            mine_id=v["mine_id"],
            violation_type=v["violation_type"],
            confidence=v["confidence"],
            source=v["source"],
            frame_ref=v["frame_ref"],
            detected_at=datetime.fromisoformat(v["detected_at"]),
        )
        for v in violations
    )
    summary["violations"] = len(violations)
    db.flush()

    # Historical events would have raised alerts at the time. Without this the grid shows
    # "0 active alerts" next to a red mine, which reads as a broken dashboard.
    # Back-dated to the event that caused them. Alerts stamped "now" for a violation from three
    # days ago make the audit trail read as though everything happened at seed time.
    alert_count = 0
    for reading in db.scalars(select(SensorReading).where(SensorReading.breached.is_(True))).all():
        alert_for_breach(db, reading).created_at = reading.recorded_at
        alert_count += 1
    for violation in db.scalars(select(Violation)).all():
        alert_for_violation(db, violation).created_at = violation.detected_at
        alert_count += 1
    db.flush()
    summary["alerts"] = alert_count

    # Opening score per mine, so the trend chart has a first point and the dashboard is never
    # blank before the first live detection.
    for mine in db.scalars(select(Mine)).all():
        result = score_mine(db, mine.id)
        record_score(db, mine.id, result)
        db.add(
            AuditLog(
                mine_id=mine.id,
                actor="SEED",
                action="COMPLIANCE_SCORED",
                entity_type="Mine",
                entity_id=mine.id,
                detail=(
                    f"Opening score {result.score} ({result.risk_level.value}) from "
                    f"{result.violation_count} open violations and {result.breach_count} "
                    f"breaches inside the scoring window."
                ),
            )
        )
    summary["scores"] = len(mines)

    db.commit()
    return summary


# --- Baseline drift ---------------------------------------------------------------------------
#
# Open PPE violations count until a clean re-inspection resolves them, so a database that has had
# the vision demo applied starts lower than the tuned baseline until it is re-seeded. Breaches only
# count inside the rolling window (BREACH_WINDOW_HOURS), so a simulator run drifts the board only
# while its breaches are still recent - they age out on their own, and nothing needs re-seeding.
#
# These helpers compare live scores against the seed files, with the same window applied to both
# sides, so that drift is something a script can detect and say out loud rather than something
# noticed on stage.


@dataclass(frozen=True)
class MineDrift:
    """One mine's live counts against what the seed files describe."""

    mine_id: int
    code: str
    name: str
    expected_violations: int
    actual_violations: int
    expected_breaches: int
    actual_breaches: int
    expected_score: float
    actual_score: float
    expected_risk: str
    actual_risk: str

    @property
    def is_clean(self) -> bool:
        return (
            self.actual_violations == self.expected_violations
            and self.actual_breaches == self.expected_breaches
        )

    @property
    def extra_violations(self) -> int:
        return self.actual_violations - self.expected_violations

    @property
    def extra_breaches(self) -> int:
        return self.actual_breaches - self.expected_breaches

    @property
    def score_drop(self) -> float:
        return round(self.expected_score - self.actual_score, 1)

    @property
    def band_changed(self) -> bool:
        return self.expected_risk != self.actual_risk


@dataclass(frozen=True)
class BaselineReport:
    mines: list[MineDrift]

    @property
    def is_clean(self) -> bool:
        return all(m.is_clean for m in self.mines)

    @property
    def drifted(self) -> list[MineDrift]:
        return [m for m in self.mines if not m.is_clean]

    @property
    def bands_changed(self) -> list[MineDrift]:
        return [m for m in self.mines if m.band_changed]


def expected_counts(since: datetime | None = None) -> tuple[dict[int, int], dict[int, int]]:
    """(violations, breaches) per mine as described by the seed files.

    Breaches are re-derived with the live thresholds rather than read from a stored flag, so a
    retuned threshold shifts the baseline instead of making every mine look drifted. With `since`
    only seeded breaches inside the scoring window count - normally none, as the seed is days old.
    """
    violations: dict[int, int] = {}
    for row in _read_json("violations.json"):
        violations[row["mine_id"]] = violations.get(row["mine_id"], 0) + 1

    breaches: dict[int, int] = {}
    for row in _read_readings():
        mine_id = int(row["mine_id"])
        breaches.setdefault(mine_id, 0)
        if since is not None and as_utc(datetime.fromisoformat(row["recorded_at"])) < since:
            continue
        if is_breach(SensorType(row["sensor_type"]), float(row["value"])):
            breaches[mine_id] += 1
    return violations, breaches


def baseline_report(db: Session, now: datetime | None = None) -> BaselineReport:
    """Compare live scores against a freshly-seeded database, both under the same window."""
    from app.services.compliance.scoring import compute_compliance_score

    expected_violations, expected_breaches = expected_counts(since=breach_window_start(now))
    rows: list[MineDrift] = []

    for mine in db.scalars(select(Mine).order_by(Mine.id)).all():
        actual = score_mine(db, mine.id, now=now)
        expected = compute_compliance_score(
            violation_count=expected_violations.get(mine.id, 0),
            breach_count=expected_breaches.get(mine.id, 0),
        )
        rows.append(
            MineDrift(
                mine_id=mine.id,
                code=mine.code,
                name=mine.name,
                expected_violations=expected.violation_count,
                actual_violations=actual.violation_count,
                expected_breaches=expected.breach_count,
                actual_breaches=actual.breach_count,
                expected_score=expected.score,
                actual_score=actual.score,
                expected_risk=expected.risk_level.value,
                actual_risk=actual.risk_level.value,
            )
        )
    return BaselineReport(mines=rows)
