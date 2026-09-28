"""Grievance SLA-breach cluster (S6 positive; N2 decoy). Moved here from GrievanceService (Phase 5).

Per mine, the densest window of window_days (by submission time) of grievances that breached
their response time; flagged when it holds at least min_breaches. A burst of grievances handled
in time is not a cluster (N2). payload:
  {settings: {window_days, min_breaches}, breaches: [{id, mine_id, t}]}  (t: submitted, unix)
"""

from __future__ import annotations

from .common import day, flag, iso, ordered

NAME = "grievance_cluster"


def clusters(payload: dict) -> dict[int, dict]:
    cfg = payload["settings"]
    window = int(cfg["window_days"]) * 86400
    by_mine: dict[int, list[dict]] = {}
    for b in sorted(payload["breaches"], key=lambda b: (int(b["t"]), int(b["id"]))):
        by_mine.setdefault(int(b["mine_id"]), []).append(b)
    out = {}
    for mine_id, bs in by_mine.items():
        best: list[dict] = []
        for start in bs:
            inside = [b for b in bs if int(start["t"]) <= int(b["t"]) < int(start["t"]) + window]
            if len(inside) > len(best):
                best = inside
        if len(best) >= int(cfg["min_breaches"]):
            out[mine_id] = {"breaches": len(best), "from": day(int(best[0]["t"])), "to": day(int(best[-1]["t"])),
                            "grievance_ids": [int(b["id"]) for b in best], "window_days": int(cfg["window_days"]),
                            "t0": int(best[0]["t"]), "t1": int(best[-1]["t"])}
    return out


def detect(payload: dict) -> list[dict]:
    flags = []
    for mine_id, c in clusters(payload).items():
        flags.append(flag(NAME, mine_id, "sla", iso(c["t0"]), iso(c["t1"]), float(c["breaches"]),
                          [{"code": "GRIEVANCE_SLA_CLUSTER", "params": {"breaches": c["breaches"], "window_days": c["window_days"],
                                                                        "from": c["from"], "to": c["to"]}}],
                          {"grievance_ids": c["grievance_ids"]}))
    return ordered(flags)
