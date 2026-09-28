"""Contractor outlier (S4 positive): a contractor far outside its peers on missing statutory
documents or violations per worker.

Robust z-scores against all contractors: (x - median) / (1.4826 x MAD); when the MAD is zero (most
contractors at the same value) the mean absolute deviation stands in. Flagged when
  CONTRACTOR_MISSING_DOCS       z >= z_min and at least min_missing_docs documents missing, or
  CONTRACTOR_VIOLATION_RATE     z >= z_min on violations per active worker and at least
                                min_violations violations.
payload: {settings: {z, min_missing_docs, min_violations},
          contractors: [{contractor_id, mine_id, active_workers, violations, missing_docs}]}
`mine_id` is the contractor's main mine (the most active workers).
"""

from __future__ import annotations

from .common import flag, ordered, rnd

NAME = "contractor_outlier"


def _median(xs: list[float]) -> float:
    s = sorted(xs)
    n = len(s)
    return s[n // 2] if n % 2 else (s[n // 2 - 1] + s[n // 2]) / 2


def _zscores(xs: list[float]) -> list[float]:
    med = _median(xs)
    dev = [abs(x - med) for x in xs]
    scale = 1.4826 * _median(dev)
    if scale == 0:
        scale = 1.2533 * sum(dev) / len(dev)
    return [0.0 if scale == 0 else (x - med) / scale for x in xs]


def detect(payload: dict) -> list[dict]:
    cfg = payload["settings"]
    cs = payload["contractors"]
    if len(cs) < 3:
        return []
    docs = [float(c["missing_docs"]) for c in cs]
    vpw = [float(c["violations"]) / c["active_workers"] if int(c["active_workers"]) > 0 else 0.0 for c in cs]
    z_docs, z_vpw = _zscores(docs), _zscores(vpw)
    med_docs, med_vpw = _median(docs), _median(vpw)
    z_min = float(cfg["z"])
    flags = []
    for c, zd, zv, rate in zip(cs, z_docs, z_vpw, vpw):
        reasons = []
        if zd >= z_min and int(c["missing_docs"]) >= int(cfg["min_missing_docs"]):
            reasons.append({"code": "CONTRACTOR_MISSING_DOCS", "params": {
                "missing": int(c["missing_docs"]), "median": rnd(med_docs, 1), "z": rnd(zd, 1)}})
        if zv >= z_min and int(c["violations"]) >= int(cfg["min_violations"]):
            reasons.append({"code": "CONTRACTOR_VIOLATION_RATE", "params": {
                "per_worker": rnd(rate, 2), "median": rnd(med_vpw, 2), "z": rnd(zv, 1)}})
        if reasons and c.get("mine_id") is not None:
            flags.append(flag(NAME, int(c["mine_id"]), f"contractor:{int(c['contractor_id'])}", payload.get("from", ""),
                              payload.get("to", ""), rnd(max(zd, zv), 1), reasons, {"contractor_id": int(c["contractor_id"])}))
    return ordered(flags)
