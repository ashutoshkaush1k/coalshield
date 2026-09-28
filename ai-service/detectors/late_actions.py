"""Late corrective actions (S7 positive): a mine that routinely closes its corrective actions late.

Among the actions resolved in the window, a mine is flagged when at least min_late were closed
after their due time and its late count is improbable at the fleet's late rate (binomial tail
below p_max) - a pattern, not a bad week. payload:
  {settings: {min_late, p_max}, actions: [{id, mine_id, due, resolved}]}  (unix seconds)
"""

from __future__ import annotations

from .common import binom_sf, flag, iso, ordered, rnd, sig

NAME = "late_actions"


def detect(payload: dict) -> list[dict]:
    cfg = payload["settings"]
    actions = payload["actions"]
    if not actions:
        return []
    late_all = sum(1 for a in actions if int(a["resolved"]) > int(a["due"]))
    rate = late_all / len(actions)
    by_mine: dict[int, list[dict]] = {}
    for a in actions:
        by_mine.setdefault(int(a["mine_id"]), []).append(a)
    flags = []
    for mine_id, acts in by_mine.items():
        late = sorted((a for a in acts if int(a["resolved"]) > int(a["due"])), key=lambda a: int(a["resolved"]))
        k, n = len(late), len(acts)
        if k < int(cfg["min_late"]):
            continue
        p = binom_sf(k, n, rate)
        if p < float(cfg["p_max"]):
            flags.append(flag(NAME, mine_id, "closures", iso(int(late[0]["resolved"])), iso(int(late[-1]["resolved"])), rnd(k / n, 2),
                              [{"code": "LATE_CLOSURES", "params": {"late": k, "resolved": n, "share_pct": rnd(100 * k / n, 1),
                                                                    "fleet_pct": rnd(100 * rate, 1), "p": sig(p)}}],
                              {"corrective_action_ids": sorted(int(a["id"]) for a in late)}))
    return ordered(flags)
