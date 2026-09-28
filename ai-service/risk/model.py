"""The predictive model at run time: the exported trees (model.json, written by train.py) evaluated
in plain Python - the same arithmetic as api/services/RiskModelService.php, so both engines give the
same probability and the same explanation.

predict(mines) -> {model_version, predictions: [{mine_id, probability, band, factors}]}
Before predicting, transfer(): each violation rate is replaced by the US value at the same
percentile the mine holds in our fleet (a mine with none stays at none); accident rates pass as
they are. The factors are the features that raise the risk most: each feature in turn set to its
typical value (the training median) and the probability recomputed - the drop is that feature's
share - reported with the mine's own figure and, for violation rates, its percentile in our fleet.
"""

from __future__ import annotations

import json
import math
from functools import lru_cache
from pathlib import Path

from detectors.common import rnd  # PHP's rounding, so both engines give the same numbers

MODEL = Path(__file__).resolve().parent / "model.json"


@lru_cache(maxsize=1)
def load() -> dict:
    return json.loads(MODEL.read_text(encoding="utf-8"))


def version() -> str | None:
    return load()["version"] if MODEL.exists() else None


def raw_score(doc: dict, x: dict) -> float:
    values = [float(x[f]) for f in doc["features"]]
    total = doc["init"]
    for tree in doc["trees"]:
        node = 0
        while tree["l"][node] != -1:
            node = tree["l"][node] if values[tree["f"][node]] <= tree["t"][node] else tree["r"][node]
        total += doc["learning_rate"] * tree["v"][node]
    return total


def probability(doc: dict, x: dict) -> float:
    z = doc["platt"]["a"] * raw_score(doc, x) + doc["platt"]["b"]
    return 1.0 / (1.0 + math.exp(-z))


def percentile(values: list[float], x: float) -> float:
    """Mid-rank percentile of x among values, 0..1."""
    below = sum(1 for v in values if v < x)
    equal = sum(1 for v in values if v == x)
    return (below + 0.5 * equal) / len(values)


def quantile(qs: list[float], q: float) -> float:
    """Linear interpolation in the 101 stored quantiles (0, 1, ..., 100 %)."""
    pos = min(max(q, 0.0), 1.0) * (len(qs) - 1)
    i = int(math.floor(pos))
    if i >= len(qs) - 1:
        return qs[-1]
    return qs[i] + (qs[i + 1] - qs[i]) * (pos - i)


def transfer(doc: dict, mines: list[dict]) -> list[dict]:
    """Each mine's features for the model, and its violation-rate percentiles in our fleet."""
    out = []
    fleet = {f: [float(m["features"][f]) for m in mines] for f in doc["quantiles"]}
    for m in mines:
        x = {f: float(v) for f, v in m["features"].items()}
        pct = {}
        for f, qs in doc["quantiles"].items():
            pct[f] = percentile(fleet[f], x[f])
            x[f] = 0.0 if x[f] == 0 else quantile(qs, pct[f])
        out.append({"mine_id": int(m["mine_id"]), "raw": m["features"], "x": x, "pct": pct})
    return out


def band(doc: dict, p: float) -> str:
    return "high" if p >= doc["bands"]["high"] else "medium" if p >= doc["bands"]["medium"] else "low"


def explain(doc: dict, t: dict, top: int = 3) -> list[dict]:
    x = t["x"]
    p = probability(doc, x)
    out = []
    for f in doc["features"]:
        ref = dict(x)
        ref[f] = doc["reference"][f]
        delta = p - probability(doc, ref)
        if delta > 0.005:
            pct = t["pct"].get(f)
            out.append({"feature": f, "value": rnd(float(t["raw"][f]), 3),
                        "fleet_percentile": None if pct is None else int(rnd(100 * pct)),
                        "typical": None if pct is not None else rnd(float(doc["reference"][f]), 3),
                        "points": rnd(100 * delta, 1)})
    out.sort(key=lambda o: (-o["points"], o["feature"]))
    return out[:top]


def predict(mines: list[dict]) -> dict:
    doc = load()
    preds = []
    for t in transfer(doc, mines):
        p = probability(doc, t["x"])
        preds.append({"mine_id": t["mine_id"], "probability": rnd(p, 4), "band": band(doc, p), "factors": explain(doc, t)})
    return {"model_version": doc["version"], "engine": "ai-service", "predictions": preds}


def card() -> dict:
    doc = load()
    return {"version": doc["version"], "features": doc["features"], "labels": doc["labels"], "bands": doc["bands"], **doc["card"]}
