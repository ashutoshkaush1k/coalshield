"""Train the sensor anomaly model (IsolationForest) on the seeded sensor dataset.

One training row per tick: a mine's gas, dust and temperature at one moment, grouped the
same way GET /sensors/{id}/live serves them. Writes backend/ml/weights/sensor_anomaly.joblib,
which the API loads on first use. Without that file the API fits the same model in memory
from the same seed data, so this script matters when retuning, not for a first run.

Usage:
    python scripts/train_sensor_model.py                      # train, report, save
    python scripts/train_sensor_model.py --contamination 0.1  # flag more ticks
    python scripts/train_sensor_model.py --dry-run            # report only, write nothing

A running API keeps the model it loaded - restart it after retraining.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

BACKEND = Path(__file__).resolve().parents[1] / "backend"
sys.path.insert(0, str(BACKEND))

from app.core.config import SEED_DIR  # noqa: E402
from app.services.iot.anomaly import (  # noqa: E402
    DEFAULT_CONTAMINATION,
    DEFAULT_TREES,
    RANDOM_STATE,
    AnomalyScorer,
    feature_row,
    save,
    ticks_from_csv,
    train,
    weights_path,
)


def report(bundle: dict, ticks) -> None:
    """How the fitted model sees its own training data.

    There are no anomaly labels, so the threshold breaches stand in as a sanity check: a
    model that flags mostly clean ticks, or ignores the worst multi-sensor breaches, is
    learning noise.
    """
    scorer = AnomalyScorer(bundle)
    complete = [t for t in ticks if t.is_complete]
    scores = scorer.score_rows([feature_row(t) for t in complete])
    flagged = [(t, s) for t, s in zip(complete, scores) if scorer.is_anomaly(s)]
    ranked = sorted(scores)

    def pct(q: float) -> float:
        return ranked[min(len(ranked) - 1, int(q * len(ranked)))]

    breaching = [t for t in complete if t.breached]
    multi = [t for t in complete if len(t.breached) >= 2]
    flagged_ticks = [t for t, _ in flagged]

    print(f"Training ticks : {len(complete)} complete ({len(ticks) - len(complete)} partial skipped)")
    print(f"Model          : IsolationForest, {bundle['n_estimators']} trees, "
          f"contamination={bundle['contamination']}, random_state={RANDOM_STATE}")
    print(f"Threshold      : anomaly_score >= {scorer.threshold}")
    print(f"Score range    : min {ranked[0]}  p50 {pct(0.5)}  p90 {pct(0.9)}  "
          f"p99 {pct(0.99)}  max {ranked[-1]}")
    print()
    print(f"Flagged        : {len(flagged)} ticks ({len(flagged) / len(complete):.1%})")
    print(f"  with a threshold breach : {sum(1 for t in flagged_ticks if t.breached)} "
          f"of {len(flagged)}")
    print(f"  multi-sensor breaches caught : {sum(1 for t in multi if t in flagged_ticks)} "
          f"of {len(multi)}")
    print(f"  breaching ticks flagged : {sum(1 for t in breaching if t in flagged_ticks)} "
          f"of {len(breaching)} (the thresholds still catch every one)")
    print()
    print("Most isolated ticks:")
    print(f"  {'MINE':>4} {'GAS':>6} {'DUST':>6} {'TEMP':>6} {'SCORE':>6}  BREACHED")
    for tick, score in sorted(zip(complete, scores), key=lambda p: -p[1])[:8]:
        print(f"  {tick.mine_id:>4} {tick.value('gas'):>6} {tick.value('dust'):>6} "
              f"{tick.value('temperature'):>6} {score:>6}  {', '.join(tick.breached) or '-'}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Train the sensor anomaly model.")
    parser.add_argument("--csv", default=str(SEED_DIR / "sensor_readings.csv"),
                        help="training data (default: the seeded dataset)")
    parser.add_argument("--out", default=None, help=f"output path (default: {weights_path()})")
    parser.add_argument("--contamination", type=float, default=DEFAULT_CONTAMINATION,
                        help=f"expected share of anomalous ticks (default {DEFAULT_CONTAMINATION})")
    parser.add_argument("--trees", type=int, default=DEFAULT_TREES,
                        help=f"n_estimators (default {DEFAULT_TREES})")
    parser.add_argument("--dry-run", action="store_true", help="report only, do not save")
    args = parser.parse_args()

    try:
        ticks = ticks_from_csv(args.csv)
    except FileNotFoundError as exc:
        print(f"ERROR: {exc}")
        return 1

    csv_label = Path(args.csv).resolve()
    try:
        csv_label = csv_label.relative_to(BACKEND.parent)
    except ValueError:
        pass
    bundle = train(ticks, contamination=args.contamination, n_estimators=args.trees,
                   source=str(csv_label).replace("\\", "/"))
    report(bundle, ticks)

    if args.dry_run:
        print("\n(dry run - nothing written)")
        return 0
    target = save(bundle, args.out)
    print(f"\nSaved {target} ({target.stat().st_size / 1024:.0f} KB). "
          "Restart the API to pick it up.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
