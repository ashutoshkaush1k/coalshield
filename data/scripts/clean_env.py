"""Stage D3: reference/env_stations.csv and reference/env_daily.csv - CPCB air-quality stations near
coalfields and their daily readings, via OpenAQ (S09).

Inputs (D2, raw/openaq/): matched_locations.json (14 stations matched to the coalfield clusters,
with their sensors), days/sensor<id>_<from>_<to>_p<page>.json (OpenAQ v3 daily aggregates for
PM10, PM2.5, SO2 and NO2 over the config window 2026-06-28..2026-09-25).

env_daily   one row per station, local date and pollutant. OpenAQ's daily value (mean of the hourly
            values) with min, max and percentComplete. Where a station has two sensors for the
            same pollutant, the one with more complete coverage that day is kept (then the lower
            sensor id). Nothing is dropped for low coverage; `coverage_pct` lets the generator
            decide. Units are OpenAQ's as returned for each record.
            Not committed (see .gitignore): OpenAQ returns no licence for these stations.
env_stations one row per matched station: coordinates, provider, days with data per pollutant,
            and the nearest mines of both rosters (reference/mines_real.csv, reference/mines.csv),
            by great-circle distance. A station more than 50 km from every real mine is flagged:
            its coordinates or its cluster match are suspect.
"""

from __future__ import annotations

import csv
import glob
import json
import math
import re
import sys
from collections import defaultdict

from common import DATA, sha256_file

RAW = DATA / "raw/openaq"
OUT_S = DATA / "reference/env_stations.csv"
OUT_D = DATA / "reference/env_daily.csv"
WANTED = ["pm10", "pm25", "so2", "no2"]
FAR_KM = 50
S_COLUMNS = ["station_id", "name", "provider", "owner", "lat", "lon", "cluster", "days_pm10", "days_pm25", "days_so2",
             "days_no2", "first_day", "last_day", "nearest_real_mines", "nearest_real_km", "nearest_seed_mines",
             "licence", "note"]
D_COLUMNS = ["station_id", "date", "parameter", "value", "units", "min", "max", "coverage_pct", "sensor_id", "source_file"]


def km(a: tuple[float, float], b: tuple[float, float]) -> float:
    (la1, lo1), (la2, lo2) = [(math.radians(x), math.radians(y)) for x, y in (a, b)]
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 6371.0 * 2 * math.asin(math.sqrt(h))


def roster(path) -> list[tuple[str, float, float]]:
    if not path.exists():
        return []
    return [(r["code"], float(r["lat"]), float(r["lon"])) for r in csv.DictReader(path.open(encoding="utf-8"))
            if r["lat"] and r["lon"]]


def main() -> int:
    mfile = RAW / "matched_locations.json"
    if not mfile.exists():
        print("  skip: raw/openaq/matched_locations.json missing (OpenAQ key not set in D2) - "
              "env data will be fully synthetic in D4")
        return 0
    matched = json.load(mfile.open(encoding="utf-8"))
    raw_locs = {}
    for f in glob.glob(str(RAW / "locations/*.json")):
        for r in json.load(open(f, encoding="utf-8"))["results"]:
            raw_locs[r["id"]] = r
    sensor_of = {}
    for loc in matched["locations"]:
        for s in loc["sensors"]:
            sensor_of[s["sensor_id"]] = (loc["location_id"], s["parameter"])

    best: dict[tuple[int, str, str], dict] = {}
    for f in sorted(glob.glob(str(RAW / "days/*.json"))):
        m = re.search(r"sensor(\d+)_", f)
        sid = int(m.group(1))
        if sid not in sensor_of:
            continue
        loc_id, param = sensor_of[sid]
        if param not in WANTED:
            continue
        rel = "raw/openaq/days/" + f.replace("\\", "/").rsplit("/", 1)[-1]
        for r in json.load(open(f, encoding="utf-8"))["results"]:
            if r.get("value") is None:
                continue
            day = r["period"]["datetimeFrom"]["local"][:10]
            cov = float((r.get("coverage") or {}).get("percentComplete") or 0)
            row = {"station_id": loc_id, "date": day, "parameter": param, "value": round(float(r["value"]), 2),
                   "units": r["parameter"]["units"], "min": round(float(r["summary"]["min"]), 2),
                   "max": round(float(r["summary"]["max"]), 2), "coverage_pct": round(cov, 1), "sensor_id": sid,
                   "source_file": rel}
            key = (loc_id, day, param)
            old = best.get(key)
            if old is None or (cov, -sid) > (old["coverage_pct"], -old["sensor_id"]):
                best[key] = row
    daily = sorted(best.values(), key=lambda r: (r["station_id"], r["date"], r["parameter"]))

    real, seed = roster(DATA / "reference/mines_real.csv"), roster(DATA / "reference/mines.csv")
    days = defaultdict(lambda: defaultdict(int))
    span = defaultdict(list)
    for r in daily:
        days[r["station_id"]][r["parameter"]] += 1
        span[r["station_id"]].append(r["date"])
    stations = []
    for loc in sorted(matched["locations"], key=lambda l: l["location_id"]):
        lid = loc["location_id"]
        here = (loc["coordinates"]["latitude"], loc["coordinates"]["longitude"])
        near_r = sorted((km(here, (la, lo)), c) for c, la, lo in real)[:3]
        near_s = sorted((km(here, (la, lo)), c) for c, la, lo in seed)[:3]
        rl = raw_locs.get(lid, {})
        notes = []
        if near_r and near_r[0][0] > FAR_KM:
            notes.append(f"nearest real mine {near_r[0][0]:.0f} km away - coordinates or cluster match suspect")
        if not span[lid]:
            notes.append("no daily data in the window (OpenAQ's last reading: "
                         f"{(rl.get('datetimeLast') or {}).get('local', '?')[:10]})")
        stations.append({
            "station_id": lid, "name": loc["name"], "provider": loc["provider"],
            "owner": (rl.get("owner") or {}).get("name", ""), "lat": here[0], "lon": here[1],
            "cluster": "; ".join(loc["clusters"]),
            **{f"days_{p}": days[lid][p] for p in WANTED},
            "first_day": min(span[lid]) if span[lid] else "", "last_day": max(span[lid]) if span[lid] else "",
            "nearest_real_mines": "; ".join(f"{c} {d:.1f} km" for d, c in near_r),
            "nearest_real_km": f"{near_r[0][0]:.1f}" if near_r else "",
            "nearest_seed_mines": "; ".join(f"{c} {d:.1f} km" for d, c in near_s),
            "licence": "not stated - OpenAQ returns licenses = null for this location (TODO-VERIFY before redistributing)",
            "note": "; ".join(notes)})

    for path, cols, rows in ((OUT_S, S_COLUMNS, stations), (OUT_D, D_COLUMNS, daily)):
        with path.open("w", encoding="utf-8", newline="") as fh:
            w = csv.DictWriter(fh, fieldnames=cols, lineterminator="\n")
            w.writeheader()
            w.writerows(rows)
    with_data = sum(1 for s in stations if s["first_day"])
    print(f"Wrote reference/env_stations.csv: {len(stations)} stations, {with_data} with data in the window; "
          f"sha256 {sha256_file(OUT_S)[:16]}")
    print(f"Wrote reference/env_daily.csv: {len(daily)} station-day-pollutant rows (not committed); "
          f"sha256 {sha256_file(OUT_D)[:16]}")
    for s in stations:
        if s["note"]:
            print(f"    {s['station_id']} {s['name']}: {s['note']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
