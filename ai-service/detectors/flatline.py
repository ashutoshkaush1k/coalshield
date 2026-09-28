"""Flatlined sensor (S3 positive): a sensor that reports the same value, hour after hour.

A real methane or dust sensor always moves a little; a run of identical readings for
min_hours or more means a stuck, covered or bypassed sensor (tampering). payload:
  {settings: {min_hours, tolerance}, hours: [{mine_id, sensor_type, hour, min, max, count}]}
where `hour` is the hour's start (unix seconds). The API sends only hours whose readings span
at most `tolerance` - the candidates; this re-checks them and finds runs of consecutive hours
whose values together stay within `tolerance`.
"""

from __future__ import annotations

from .common import flag, iso, ordered, rnd

NAME = "sensor_flatline"


def detect(payload: dict) -> list[dict]:
    cfg = payload["settings"]
    min_hours = int(cfg["min_hours"])
    tol = float(cfg["tolerance"])
    series: dict[tuple[int, str], list[dict]] = {}
    for h in payload["hours"]:
        if float(h["max"]) - float(h["min"]) <= tol and int(h["count"]) >= 1:
            series.setdefault((int(h["mine_id"]), h["sensor_type"]), []).append(h)

    flags = []
    for (mine_id, sensor), hours in series.items():
        hours.sort(key=lambda h: int(h["hour"]))
        run: list[dict] = []

        def close(run: list[dict]) -> None:
            if len(run) >= min_hours:
                lo, hi = min(float(h["min"]) for h in run), max(float(h["max"]) for h in run)
                start, end = int(run[0]["hour"]), int(run[-1]["hour"]) + 3600
                flags.append(flag(NAME, mine_id, f"{sensor}|{iso(start)}", iso(start), iso(end), float(len(run)),
                                  [{"code": "FLATLINE", "params": {"sensor_type": sensor, "hours": len(run), "value": rnd(lo, 3),
                                                                   "readings": sum(int(h["count"]) for h in run)}}],
                                  {"sensor_type": sensor, "value_range": [rnd(lo, 3), rnd(hi, 3)]}))

        for h in hours:
            if run and (int(h["hour"]) - int(run[-1]["hour"]) != 3600
                        or max(max(float(x["max"]) for x in run), float(h["max"])) - min(min(float(x["min"]) for x in run), float(h["min"])) > tol):
                close(run)
                run = []
            run.append(h)
        close(run)
    return ordered(flags)
