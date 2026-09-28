"""Repeat violations (S1 positive): the same kind of violation at a mine, again and again.

Per mine and category, the window_days window with the most violations. Flagged when it holds at
least min_repeats and either
  REPEAT_IMPROBABLE     the category is over-represented among the mine's own violations in that
                        window: binomial tail at the category's fleet share below p_max (size-neutral
                        - a big, busy mine is not flagged for being big), or
  REPEAT_THEN_INCIDENT  an incident of the same hazard (settings.hazard_incident_types: DGMS cause
                        groups per category) falls inside the window or within window_days after it
                        - repeat violations as the precursor of an accident.
payload: {settings: {window_days, min_repeats, p_max, hazard_incident_types}, violations:
          [{id, mine_id, category, t}], incidents: [{id, mine_id, type, t}]}
"""

from __future__ import annotations

from .common import binom_sf, day, flag, iso, ordered, rnd, sig

NAME = "repeat_violations"


def detect(payload: dict) -> list[dict]:
    cfg = payload["settings"]
    window = int(cfg["window_days"]) * 86400
    min_rep = int(cfg["min_repeats"])
    p_max = float(cfg["p_max"])
    hazards = cfg.get("hazard_incident_types") or {}

    fleet: dict[str, int] = {}
    groups: dict[tuple[int, str], list[dict]] = {}
    by_mine: dict[int, list[int]] = {}
    for v in payload["violations"]:
        fleet[v["category"]] = fleet.get(v["category"], 0) + 1
        groups.setdefault((int(v["mine_id"]), v["category"]), []).append(v)
        by_mine.setdefault(int(v["mine_id"]), []).append(int(v["t"]))
    total = len(payload["violations"])
    incidents: dict[int, list[dict]] = {}
    for i in payload["incidents"]:
        incidents.setdefault(int(i["mine_id"]), []).append(i)

    flags = []
    for (mine_id, category), vs in groups.items():
        vs.sort(key=lambda v: (int(v["t"]), int(v["id"])))
        best, lo = (0, 0, 0), 0
        for hi in range(len(vs)):
            while int(vs[hi]["t"]) - int(vs[lo]["t"]) > window:
                lo += 1
            if hi - lo + 1 > best[0]:
                best = (hi - lo + 1, lo, hi)
        count, a, b = best
        if count < min_rep:
            continue
        start, end = int(vs[a]["t"]), int(vs[b]["t"])
        share = fleet[category] / total
        mine_total = sum(1 for t in by_mine[mine_id] if start <= t <= end)
        p = binom_sf(count, mine_total, share)
        reasons = []
        if p < p_max:
            reasons.append({"code": "REPEAT_IMPROBABLE", "params": {
                "category": category, "count": count, "of": mine_total, "days": int(window / 86400),
                "expected": rnd(mine_total * share, 1), "p": sig(p)}})
        types = hazards.get(category, [])
        linked = sorted((i for i in incidents.get(mine_id, [])
                         if i["type"] in types and start <= int(i["t"]) <= end + window), key=lambda i: (int(i["t"]), int(i["id"])))
        if linked:
            reasons.append({"code": "REPEAT_THEN_INCIDENT", "params": {
                "category": category, "count": count, "days": int((end - start) / 86400) + 1,
                "incident_id": int(linked[0]["id"]), "incident_date": day(int(linked[0]["t"]))}})
        if reasons:
            flags.append(flag(NAME, mine_id, category, iso(start), iso(end), float(count), reasons,
                              {"category": category, "violation_ids": [int(v["id"]) for v in vs[a:b + 1]],
                               "incident_ids": [int(i["id"]) for i in linked]}))
    return ordered(flags)
