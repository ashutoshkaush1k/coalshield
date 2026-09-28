"""Night-shift concentration (S5 positive; N3 decoy): a mine whose camera-detected violations
cluster at night far beyond chance.

The night (night_from to night_to, IST) is a fixed share of the day (8 of 24 hours by default);
with no night effect, that is the chance a violation falls in it. Flagged when the mine has at
least min_count violations and the binomial tail of its night count at that share is below p_max
- a concentration, not just a night shift that works. payload:
  {settings: {night_from, night_to, p_max, min_count}, from, to, violations: [{id, mine_id, hour_ist}]}
"""

from __future__ import annotations

from .common import binom_sf, flag, ordered, rnd, sig

NAME = "night_shift"


def is_night(hour: int, night_from: int, night_to: int) -> bool:
    return hour >= night_from or hour < night_to if night_from > night_to else night_from <= hour < night_to


def detect(payload: dict) -> list[dict]:
    cfg = payload["settings"]
    nf, nt = int(cfg["night_from"]), int(cfg["night_to"])
    night_hours = sum(1 for h in range(24) if is_night(h, nf, nt))
    base = night_hours / 24
    by_mine: dict[int, list[dict]] = {}
    for v in payload["violations"]:
        by_mine.setdefault(int(v["mine_id"]), []).append(v)
    flags = []
    for mine_id, vs in by_mine.items():
        n = len(vs)
        night = [v for v in vs if is_night(int(v["hour_ist"]), nf, nt)]
        k = len(night)
        if n < int(cfg["min_count"]):
            continue
        p = binom_sf(k, n, base)
        if p < float(cfg["p_max"]):
            flags.append(flag(NAME, mine_id, "night", payload["from"], payload["to"], rnd(k / n, 2),
                              [{"code": "NIGHT_CONCENTRATION", "params": {
                                  "night": k, "total": n, "share_pct": rnd(100 * k / n, 1),
                                  "expected_pct": rnd(100 * base, 1), "p": sig(p)}}],
                              {"violation_ids": sorted(int(v["id"]) for v in night)}))
    return ordered(flags)
