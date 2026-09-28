"""Production anomaly (S2 positive; N1 and N3 decoys). Moved here from ProductionService (Phase 4).

Per mine and day, on the day's total over its shifts (rules.yaml product.production_anomaly):
  OVER_TARGET          output above the day's target by more than over_target_pct
  ROLLING_MEAN_SPIKE   output >= ratio x the mean of the mine's producing days in the previous
  ROLLING_MEAN_DROP    rolling_days days (or <= 1/ratio x), z standard deviations or more away -
                       and so is the output-to-target ratio, so a rise the target explains (a
                       revised target, a new month's plan) is not flagged. Needs min_baseline_days.

payload: {settings, from, to, days: [{mine_id, date, target, actual}]}  (days from `from` - rolling_days)
"""

from __future__ import annotations

import math
from datetime import date, timedelta

from .common import finite, flag, ordered, rnd

NAME = "production_anomaly"


def _deviation(value: float, base: list[float]) -> tuple[float, float, float]:
    mean = sum(base) / len(base)
    sd = math.sqrt(sum((v - mean) ** 2 for v in base) / len(base))
    ratio = value / mean if mean > 0 else math.inf
    if sd > 0:
        z = (value - mean) / sd
    else:
        z = 0.0 if value == mean else math.copysign(math.inf, value - mean)
    return ratio, z, mean


def _pct(actual: float, target: float):
    return rnd(100 * actual / target, 1) if target > 0 else None


def detect(payload: dict) -> list[dict]:
    cfg = payload["settings"]
    over = float(cfg["over_target_pct"]) / 100
    window = int(cfg["rolling_days"])
    min_base = int(cfg["min_baseline_days"])
    ratio_min = float(cfg["ratio"])
    z_min = float(cfg["z"])
    start, end = payload["from"], payload["to"]

    by_mine: dict[int, dict[str, dict]] = {}
    for d in payload["days"]:
        by_mine.setdefault(int(d["mine_id"]), {})[d["date"]] = {"target": float(d["target"]), "actual": float(d["actual"])}

    flags = []
    for mine_id, days in by_mine.items():
        dates = sorted(days)
        for i, day_ in enumerate(dates):
            if day_ < start or day_ > end or days[day_]["target"] <= 0:
                continue
            actual, target = days[day_]["actual"], days[day_]["target"]
            reasons, direction = [], None
            if actual > target * (1 + over):
                direction = "spike"
                reasons.append({"code": "OVER_TARGET", "params": {
                    "actual_t": rnd(actual, 1), "target_t": rnd(target, 1), "achievement_pct": _pct(actual, target),
                    "threshold_pct": int(cfg["over_target_pct"])}})
            window_start = (date.fromisoformat(day_) - timedelta(days=window)).isoformat()
            base = []
            j = i - 1
            while j >= 0 and dates[j] >= window_start:
                b = days[dates[j]]
                if b["actual"] > 0 and b["target"] > 0:
                    base.append(b)
                j -= 1
            ratio = None
            if len(base) >= min_base:
                r1, z1, mean = _deviation(actual, [b["actual"] for b in base])
                r2, z2, _ = _deviation(actual / target, [b["actual"] / b["target"] for b in base])
                params = {"actual_t": rnd(actual, 1), "rolling_mean_t": rnd(mean, 1), "ratio": finite(r1, 2),
                          "z": finite(z1, 1), "days": len(base)}
                if r1 >= ratio_min and z1 >= z_min and r2 >= ratio_min and z2 >= z_min:
                    direction = "spike"
                    reasons.append({"code": "ROLLING_MEAN_SPIKE", "params": params})
                    ratio = r1
                elif r1 <= 1 / ratio_min and z1 <= -z_min and r2 <= 1 / ratio_min and z2 <= -z_min:
                    direction = direction or "drop"
                    reasons.append({"code": "ROLLING_MEAN_DROP", "params": params})
                    ratio = r1
            if reasons:
                score = finite(ratio, 2) if ratio is not None else rnd(actual / target, 2)
                flags.append(flag(NAME, mine_id, day_, f"{day_}T00:00:00Z", f"{day_}T23:59:59Z", score, reasons,
                                  {"direction": direction, "date": day_}))
    return ordered(flags)
