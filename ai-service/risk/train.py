"""Train the predictive model on real US mine-years (Phase 7).

    backend\\.venv\\Scripts\\python ai-service\\risk\\train.py

Data: data/reference/msha_rates.csv - MSHA's public mine-year records (2017-2025): workers,
inspections, violations by category (mapped to the Indian categories), accidents. Question: from
this year's figures, will the mine have a HIGH-INCIDENCE year next year - lost-time and fatal
accidents at HIGH_RATE or more per 100 workers? A rate, not "any accident": with "any accident" a
big mine is always at risk (exposure), and the model learns size instead of safety.

  rows      mine-years of surface and underground coal mines with 20 or more workers (facilities
            and one-person operations are not like our mines), paired with the same mine's next year
  split     by time, never by mine: fit on feature years 2017-2020, calibrate (Platt) on 2021,
            test on 2022-2024 (targets 2023-2025) - the model never sees the test years
  model     gradient boosting (scikit-learn HistGradientBoostingClassifier, CPU), shallow trees,
            monotonic: more violations or accidents can never lower the risk (size and underground
            workings unconstrained) - so the explanations read the way a safety officer expects
  baseline  "this year's lost-time rate predicts next year's" - the rule an inspector would use
  report    AUC, precision and recall at p >= 0.5, Brier score and a reliability table, for the
            model and the baseline, on the test years

Output: ai-service/risk/model.json - the trees, the calibration, the reference values for the
explanations and the model card. JSON, not a pickle: the ai-service and the PHP fallback
(api/services/RiskModelService.php) evaluate the same trees; this script checks that the JSON
reproduces scikit-learn's own predictions.
"""

from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import brier_score_loss, precision_score, recall_score, roc_auc_score

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
from risk.features import CATEGORIES, FEATURES, LABELS, VIOLATION_FEATURES, row_features  # noqa: E402
from risk.model import raw_score  # noqa: E402

ROOT = HERE.parents[1]
SOURCE = ROOT / "data" / "reference" / "msha_rates.csv"
OUT = HERE / "model.json"
FIT_YEARS, CAL_YEAR, TEST_YEARS = range(2017, 2021), 2021, range(2022, 2025)
HIGH_RATE = 3.0          # lost-time + fatal accidents per 100 workers in a year
BANDS = {"high": 0.5, "medium": 0.25}


def pairs(df: pd.DataFrame) -> pd.DataFrame:
    df = df[(df["employees_now"] >= 20) & (df["mine_type"].isin(["Surface", "Underground"]))].copy()
    df["lost"] = df["lost_time_accidents"] + df["fatal_accidents"]
    nxt = df[["mine_id", "year", "lost", "employees_now"]].rename(columns={"lost": "lost_next", "employees_now": "workers_next"})
    nxt["year"] -= 1
    joined = df.merge(nxt, on=["mine_id", "year"], how="inner")
    rows = []
    for r in joined.itertuples():
        f = row_features(r.employees_now, r.mine_type == "Underground", r.violations,
                         {c: getattr(r, f"v_{c}") for c in CATEGORIES}, r.accidents, r.lost)
        f.update(mine_id=r.mine_id, year=r.year, y=int(100 * r.lost_next / max(r.workers_next, 1) >= HIGH_RATE), lost=r.lost)
        rows.append(f)
    return pd.DataFrame(rows)


def reliability(y, p, bins=10) -> list[dict]:
    edges = np.linspace(0, 1, bins + 1)
    out = []
    for lo, hi in zip(edges[:-1], edges[1:]):
        m = (p >= lo) & ((p < hi) if hi < 1 else (p <= hi))
        if m.sum():
            out.append({"from": round(float(lo), 1), "to": round(float(hi), 1), "n": int(m.sum()),
                        "predicted": round(float(p[m].mean()), 3), "observed": round(float(y[m].mean()), 3)})
    return out


def metrics(y, score, prob=None, threshold=0.5) -> dict:
    pred = (prob if prob is not None else score) >= threshold
    out = {"auc": round(float(roc_auc_score(y, score)), 3),
           "precision": round(float(precision_score(y, pred, zero_division=0)), 3),
           "recall": round(float(recall_score(y, pred, zero_division=0)), 3),
           "positive_rate": round(float(pred.mean()), 3)}
    if prob is not None:
        out["brier"] = round(float(brier_score_loss(y, prob)), 4)
        rel = reliability(np.asarray(y), np.asarray(prob))
        out["ece"] = round(sum(b["n"] * abs(b["predicted"] - b["observed"]) for b in rel) / len(y), 4)
        out["reliability"] = rel
    return out


def export(model: HistGradientBoostingClassifier) -> list[dict]:
    """The trees as plain arrays; a leaf has l = -1. Leaf values already include the learning rate."""
    trees = []
    for (predictor,) in model._predictors:
        n = predictor.nodes
        leaf = n["is_leaf"].astype(bool)
        trees.append({"f": [int(x) for x in n["feature_idx"]], "t": [float(x) for x in n["num_threshold"]],
                      "l": [-1 if lf else int(x) for x, lf in zip(n["left"], leaf)], "r": [int(x) for x in n["right"]],
                      "v": [float(x) for x in n["value"]]})
    return trees


def main() -> None:
    raw = pd.read_csv(SOURCE, dtype={"mine_id": str})
    data = pairs(raw)
    fit, cal, test = (data[data.year.isin(FIT_YEARS)], data[data.year == CAL_YEAR], data[data.year.isin(TEST_YEARS)])
    X = lambda d: d[FEATURES].to_numpy(dtype=float)  # noqa: E731

    monotonic = [0 if f in ("log_workers", "underground") else 1 for f in FEATURES]
    gbm = HistGradientBoostingClassifier(max_iter=200, max_depth=3, learning_rate=0.05, monotonic_cst=monotonic,
                                         early_stopping=False, random_state=2026)
    gbm.fit(X(fit), fit.y)
    platt = LogisticRegression(C=1e6).fit(gbm.decision_function(X(cal)).reshape(-1, 1), cal.y)
    a, b = float(platt.coef_[0][0]), float(platt.intercept_[0])

    prior = float(fit.y.mean())
    doc = {"version": f"msha-hgb-{date.today().isoformat()}", "features": FEATURES, "labels": LABELS,
           "init": float(np.ravel(gbm._baseline_prediction)[0]), "learning_rate": 1.0, "trees": export(gbm),
           "platt": {"a": a, "b": b}, "bands": BANDS,
           "reference": {f: round(float(fit[f].median()), 6) for f in FEATURES},
           # The US distribution of each violation rate, for the transfer by rank (model.transfer).
           "quantiles": {f: [round(float(q), 6) for q in np.quantile(fit[f], np.linspace(0, 1, 101))] for f in VIOLATION_FEATURES}}

    # The JSON must reproduce scikit-learn exactly.
    raw_json = np.array([raw_score(doc, dict(zip(FEATURES, row))) for row in X(test)])
    diff = float(np.max(np.abs(raw_json - gbm.decision_function(X(test)))))
    assert diff < 1e-6, f"exported trees disagree with scikit-learn by {diff}"

    prob = 1 / (1 + np.exp(-(a * gbm.decision_function(X(test)) + b)))
    baseline_rule = (test.lost_time_per_100 >= HIGH_RATE).to_numpy()
    doc["card"] = {
        "trained_on": "MSHA mine-years (US), data/reference/msha_rates.csv: surface and underground coal mines, 20+ workers",
        "target": f"a high-incidence next year: lost-time and fatal accidents at {HIGH_RATE:g} or more per 100 workers",
        "split": {"fit": f"{FIT_YEARS[0]}-{FIT_YEARS[-1]}", "calibration": str(CAL_YEAR), "test": f"{TEST_YEARS[0]}-{TEST_YEARS[-1]}",
                  "note": "feature years; the target is the following year"},
        "rows": {"fit": int(len(fit)), "calibration": int(len(cal)), "test": int(len(test))},
        "base_rate": {"fit": round(prior, 3), "test": round(float(test.y.mean()), 3)},
        "model": {"type": "HistGradientBoostingClassifier", "max_iter": 200, "max_depth": 3, "learning_rate": 0.05,
                  "monotonic": "violations and accidents can only raise the risk", "calibration": "Platt (sigmoid) on the calibration year",
                  "exported_trees_max_diff": diff},
        "test": {"model": metrics(test.y, prob, prob),
                 "baseline": {"rule": f"this year's lost-time rate (score); {HIGH_RATE:g}+ per 100 workers this year -> predict it again",
                              **metrics(test.y, test.lost_time_per_100.to_numpy(), baseline_rule.astype(float))}},
        "transfer": "Trained on US regulator data (MSHA) and applied to Indian mines. Violation categories are mapped by "
                    "data/reference/crosswalk_msha_india.csv. Violation RATES depend on how hard inspectors look (US ~32 per 100 "
                    "workers a year, our records ~6), so each mine's violation rates are placed at the same percentile of the US "
                    "distribution as they hold in our fleet (a mine with none stays at none); accident rates are used as they are. "
                    "Indian mines differ in size, methods and reporting: a ranking signal, not a calibrated probability for India.",
    }
    OUT.write_text(json.dumps(doc, indent=1) + "\n", encoding="utf-8")
    t = doc["card"]["test"]
    print(f"rows fit/cal/test: {len(fit)}/{len(cal)}/{len(test)}; base rate test {test.y.mean():.3f}")
    print(f"model    AUC {t['model']['auc']}  precision {t['model']['precision']}  recall {t['model']['recall']}  "
          f"Brier {t['model']['brier']}  ECE {t['model']['ece']}")
    print(f"baseline AUC {t['baseline']['auc']}  precision {t['baseline']['precision']}  recall {t['baseline']['recall']}")
    print(f"wrote {OUT.relative_to(ROOT)} ({OUT.stat().st_size // 1024} KB), JSON vs scikit-learn max diff {diff:.2e}")


if __name__ == "__main__":
    main()
