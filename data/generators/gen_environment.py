"""env_reading - daily PM10, PM2.5, SO2, NO2 per mine.

calibrated  The nearest CPCB station (reference/env_stations.csv) within config
            generation.env_station_max_km that has a reading that day (reference/env_daily.csv,
            OpenAQ daily means), times mine-level lognormal noise (sigma env_mine_noise_sigma).
synthetic   No station applies (too far, or no reading that day, or the day is outside the OpenAQ
            window). Drawn from a lognormal fitted to all stations' readings of that pollutant in
            the window; if env_daily.csv is absent (OpenAQ skipped), from the documented
            config generation.env_fallback_ranges instead.
Units: everything in ug/m3. OpenAQ gives SO2 and NO2 in ppb here; converted at 25 degC and 1 atm
(ug/m3 = ppb x molar mass / 24.45): SO2 x 2.620, NO2 x 1.882.
"""

from __future__ import annotations

import math

import numpy as np
import pandas as pd

from common import REF, Ctx

PARAMS = ["pm10", "pm25", "so2", "no2"]
MOLAR = {"so2": 64.066, "no2": 46.0055}


def to_ugm3(row_value: float, unit: str, param: str) -> float:
    if unit.strip().lower() == "ppb" and param in MOLAR:
        return row_value * MOLAR[param] / 24.45
    return row_value


def km(lat1, lon1, lat2, lon2):
    p1, p2 = np.radians(lat1), np.radians(lat2)
    h = np.sin((p2 - p1) / 2) ** 2 + np.cos(p1) * np.cos(p2) * np.sin(np.radians(lon2 - lon1) / 2) ** 2
    return 6371.0 * 2 * np.arcsin(np.sqrt(h))


def run(ctx: Ctx) -> None:
    rng = ctx.rng("environment")
    gen = ctx.gen
    stations = pd.read_csv(REF / "env_stations.csv")
    daily_path = REF / "env_daily.csv"
    have_daily = daily_path.exists()
    if have_daily:
        d = pd.read_csv(daily_path)
        d["value"] = [to_ugm3(v, u, p) for v, u, p in zip(d["value"], d["units"], d["parameter"])]
        lookup = {(r.station_id, r.date, r.parameter): r.value for r in d.itertuples()}
        fit = {p: (float(np.median(g["value"])), float(np.std(np.log(g["value"].clip(lower=0.1)))))
               for p, g in d.groupby("parameter")}
    else:
        lookup = {}
        fit = {p: (v["median"], v["sigma"]) for p, v in gen["env_fallback_ranges"].items()}
    sigma = gen["env_mine_noise_sigma"]
    rows = []
    for m in ctx.mines.itertuples():
        dist = km(m.lat, m.lon, stations["lat"].to_numpy(), stations["lon"].to_numpy())
        near = [(float(dk), int(sid)) for dk, sid in sorted(zip(dist, stations["station_id"])) if dk <= gen["env_station_max_km"]]
        for day in ctx.day_range():
            ds = day.strftime("%Y-%m-%d")
            for p in PARAMS:
                hit = next(((dk, sid, lookup[(sid, ds, p)]) for dk, sid in near if (sid, ds, p) in lookup), None)
                if hit:
                    dk, sid, v = hit
                    val, kind, st, dkm = v * math.exp(rng.normal(0, sigma)), "calibrated", sid, round(dk, 1)
                else:
                    med, sg = fit[p]
                    val, kind, st, dkm = med * math.exp(rng.normal(0, sg)), "synthetic", None, None
                rows.append((m.id, day.date(), p, round(val, 1), "ug/m3", kind, st, dkm))
    df = pd.DataFrame(rows, columns=["mine_id", "date", "parameter", "value", "unit", "data_kind", "station_id",
                                     "station_distance_km"])
    df.insert(0, "id", range(1, len(df) + 1))
    ctx.emit("env_reading", df)
    ctx.notes["env_summary"] = {
        "env_daily_present": have_daily, "rows": len(df),
        "calibrated_share": round(float((df["data_kind"] == "calibrated").mean()), 3),
        "mines_with_a_station": int(df.loc[df["data_kind"] == "calibrated", "mine_id"].nunique()),
        "synthetic_fit": {p: {"median_ugm3": round(v[0], 1), "log_sigma": round(v[1], 3)} for p, v in fit.items()}}
