"""Sensor anomaly scoring: an IsolationForest over each tick's gas, dust and temperature.

Additive to the thresholds, never a replacement. A threshold answers "is one sensor over
its limit"; this answers "is this combination of readings unusual for a mine", which can
flag a tick where every sensor sits just under its limit at once. Nothing here touches the
`breached` flag, scoring, or alerting.

Trained on the seeded dataset by scripts/train_sensor_model.py. Ticks are built with the
same grouping the live feed serves, so a trained row and a served row mean the same thing.

`anomaly_score` is the IsolationForest score from the original paper, 0..1: around 0.5 or
below is ordinary, approaching 1 is highly isolated. `threshold` is the cut-off the model
was fitted with (its `contamination`), so `is_anomaly` agrees with `model.predict`.
"""

from __future__ import annotations

import csv
import logging
import threading
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from app.core.config import BACKEND_DIR, SEED_DIR, settings
from app.models.sensor_reading import SensorReading
from app.services.iot.live_feed import SENSOR_ORDER, Tick, group_into_ticks
from app.services.iot.thresholds import SensorType, is_breach

log = logging.getLogger(__name__)

FEATURES = list(SENSOR_ORDER)  # gas, dust, temperature - column order of the model input
RANDOM_STATE = 26024           # same seed as the dataset, so retraining is reproducible
DEFAULT_CONTAMINATION = 0.05   # flag roughly the most isolated 1 tick in 20
DEFAULT_TREES = 200


def weights_path(path: str | Path | None = None) -> Path:
    candidate = Path(path or settings.sensor_anomaly_model_path)
    return candidate if candidate.is_absolute() else BACKEND_DIR / candidate


# --- Training data -----------------------------------------------------------------------


def ticks_from_csv(csv_path: str | Path | None = None) -> list[Tick]:
    """The seeded dataset as ticks. Readings are built in memory, never added to a session."""
    path = Path(csv_path or SEED_DIR / "sensor_readings.csv")
    if not path.exists():
        raise FileNotFoundError(f"{path} not found. Run: python scripts/generate_sensor_data.py")

    by_mine: dict[int, list[SensorReading]] = {}
    with path.open(encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            sensor = SensorType(row["sensor_type"])
            value = float(row["value"])
            reading = SensorReading(
                id=int(row["reading_id"]), mine_id=int(row["mine_id"]),
                sensor_type=sensor.value, value=value, unit=row["unit"],
                recorded_at=datetime.fromisoformat(row["recorded_at"]),
                breached=is_breach(sensor, value),
            )
            by_mine.setdefault(reading.mine_id, []).append(reading)

    return [tick for readings in by_mine.values() for tick in group_into_ticks(readings)]


def feature_row(tick: Tick) -> list[float] | None:
    """Model input for one tick, or None when a sensor is missing - no imputation."""
    if not tick.is_complete:
        return None
    return [tick.value(s) for s in FEATURES]


def train(
    ticks: list[Tick],
    *,
    contamination: float = DEFAULT_CONTAMINATION,
    n_estimators: int = DEFAULT_TREES,
    random_state: int = RANDOM_STATE,
    source: str = "",
) -> dict[str, Any]:
    """Fit on every complete tick. Returns the bundle that gets saved to disk."""
    import numpy as np
    import sklearn
    from sklearn.ensemble import IsolationForest

    rows = [r for r in (feature_row(t) for t in ticks) if r is not None]
    if len(rows) < 20:
        raise ValueError(f"Only {len(rows)} complete ticks - not enough to fit a model")

    model = IsolationForest(
        n_estimators=n_estimators, contamination=contamination, random_state=random_state
    ).fit(np.asarray(rows, dtype=float))

    return {
        "model": model,
        "features": FEATURES,
        # score_samples is the negated paper score; offset_ is where predict() flips.
        "threshold": float(-model.offset_),
        "contamination": contamination,
        "n_estimators": n_estimators,
        "n_training_ticks": len(rows),
        "trained_at": datetime.now(UTC).isoformat(),
        "source": source,
        "sklearn_version": sklearn.__version__,
    }


# --- Scoring -----------------------------------------------------------------------------


@dataclass
class AnomalyScorer:
    bundle: dict[str, Any]

    @property
    def threshold(self) -> float:
        return round(self.bundle["threshold"], 3)

    def score_rows(self, rows: list[list[float] | None]) -> list[float | None]:
        """Scores for a batch of feature rows in one model call; None passes through."""
        import numpy as np

        present = [r for r in rows if r is not None]
        if not present:
            return [None] * len(rows)
        raw = iter(-self.bundle["model"].score_samples(np.asarray(present, dtype=float)))
        return [round(float(next(raw)), 3) if r is not None else None for r in rows]

    def is_anomaly(self, score: float | None) -> bool | None:
        # Compared on the rounded values the client sees, so the two can never disagree.
        return None if score is None else score >= self.threshold

    def score_ticks(self, ticks: list[Tick]) -> None:
        """Fill anomaly_score / is_anomaly on each tick, in place."""
        for tick, score in zip(ticks, self.score_rows([feature_row(t) for t in ticks]), strict=True):
            tick.anomaly_score = score
            tick.is_anomaly = self.is_anomaly(score)

    @classmethod
    def load(cls, path: Path) -> AnomalyScorer:
        import joblib

        bundle = joblib.load(path)
        if bundle.get("features") != FEATURES:
            raise ValueError(f"model features {bundle.get('features')} != {FEATURES}")
        return cls(bundle)


def save(bundle: dict[str, Any], path: str | Path | None = None) -> Path:
    import joblib

    target = weights_path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(bundle, target)
    return target


_lock = threading.Lock()
_scorer: AnomalyScorer | None = None
_resolved = False


def _load_or_train() -> AnomalyScorer | None:
    try:
        import sklearn  # noqa: F401
    except ImportError:
        log.warning("scikit-learn is not installed - sensor anomaly scores will be null. "
                    "Run: pip install -r requirements.txt")
        return None

    path = weights_path()
    if path.exists():
        try:
            scorer = AnomalyScorer.load(path)
            log.info("Sensor anomaly model loaded from %s", path)
            return scorer
        except Exception as exc:  # stale pickle, sklearn version drift, wrong features
            log.warning("Could not load %s (%s); retraining from the seed data", path, exc)

    # Fresh clone with no weights: fit in memory from the committed seed data. Same data,
    # same seed, so the scores match a model trained by the script.
    try:
        bundle = train(ticks_from_csv(), source="seed csv (in-memory fallback)")
    except (FileNotFoundError, ValueError) as exc:
        log.warning("Sensor anomaly model unavailable: %s", exc)
        return None
    log.info("No sensor anomaly weights at %s - trained in memory from the seed data", path)
    return AnomalyScorer(bundle)


def get_scorer() -> AnomalyScorer | None:
    """The process-wide scorer, loaded once. None when the model cannot be had at all."""
    global _scorer, _resolved
    if not _resolved:
        with _lock:
            if not _resolved:
                _scorer = _load_or_train()
                _resolved = True
    return _scorer


def reset_scorer() -> None:
    """Forget the cached model, so the next call reloads it (tests, or after retraining)."""
    global _scorer, _resolved
    with _lock:
        _scorer, _resolved = None, False


def score_ticks(ticks: list[Tick]) -> float | None:
    """Score ticks in place if the model is available. Returns the threshold for the envelope."""
    scorer = get_scorer()
    if scorer is None or not ticks:
        return scorer.threshold if scorer else None
    scorer.score_ticks(ticks)
    return scorer.threshold


def score_values(rows: list[dict[str, float | None]]) -> list[tuple[float | None, bool | None]]:
    """(score, is_anomaly) for plain {sensor: value} rows - callers holding latest values."""
    scorer = get_scorer()
    if scorer is None:
        return [(None, None)] * len(rows)
    features = [[row.get(f) for f in FEATURES] for row in rows]
    scores = scorer.score_rows([f if None not in f else None for f in features])
    return [(s, scorer.is_anomaly(s)) for s in scores]
