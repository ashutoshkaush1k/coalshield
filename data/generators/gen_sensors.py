"""sensor_reading - synthetic signals; breaches judged only against obligation-cited limits.

Sensor sets (dataset brief: gas sensors only for underground and mixed mines):
  underground, mixed  ch4, ch4_return_air, co, dust, temperature (wet bulb), humidity - every
                      config sensor_interval_minutes.underground (15)
  opencast            dust, temperature (wet bulb), humidity - every ...opencast (60)
Signal = mine base level + daily cycle (shift work) + seasonal factor by month + slow AR(1) drift
+ noise + rare spikes that decay over hours. The shapes are modelling choices, not measurements.

breached (schema/rules.yaml, each limit cites a verified obligation):
  ch4 > 1.25 %  and  ch4_return_air > 0.75 %      SAF-11  CMR 2017 reg.153(2)(c)
  dust: 8-hour rolling mean > 2 mg/m3              HLT-04  CMR 2017 reg.143(2) (an 8-hour TWA limit)
  temperature (wet bulb) > 33.5 degC               HLT-05  CMR 2017 reg.153(2)(d)
  co, humidity: no verified limit -> breached is empty (co is TODO-VERIFY)
Breaches are never "resolved" (they age out of the backend's rolling window), as today.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from common import Ctx

SEASON = {  # month -> (dust factor, wet-bulb offset degC, humidity offset %)
    1: (1.10, -3.0, -12), 2: (1.10, -2.0, -15), 3: (1.15, 0.0, -18), 4: (1.20, 1.5, -20), 5: (1.20, 2.0, -15),
    6: (0.90, 1.5, 5), 7: (0.75, 1.0, 10), 8: (0.75, 1.0, 10), 9: (0.85, 0.8, 8), 10: (1.00, 0.0, 0),
    11: (1.05, -1.5, -8), 12: (1.10, -3.0, -10)}


def spikes(rng, n: int, per_day: float, steps_per_hour: float, amp: tuple, hours: tuple) -> np.ndarray:
    """Sum of rare exponentially decaying spikes."""
    out = np.zeros(n)
    k = rng.poisson(per_day * n / (24 * steps_per_hour))
    for start in rng.integers(0, n, k):
        a = rng.uniform(*amp)
        tau = rng.uniform(*hours) * steps_per_hour
        length = int(min(n - start, tau * 5))
        out[start:start + length] += a * np.exp(-np.arange(length) / tau)
    return out


def ar1(rng, n: int, phi: float, sigma: float) -> np.ndarray:
    e = rng.normal(0, sigma, n)
    x = np.empty(n)
    acc = 0.0
    for i in range(n):
        acc = phi * acc + e[i]
        x[i] = acc
    return x


def series_for(rng, mine_type: str, ts: pd.DatetimeIndex, step_min: int) -> dict[str, tuple[np.ndarray, str]]:
    n = len(ts)
    sph = 60 / step_min
    hour = ((ts.hour + 5.5) % 24).to_numpy()                 # IST hour
    work = np.where((hour >= 6) & (hour < 22), 1.0, 0.7)       # A and B shifts busier than C
    diurnal = np.sin(2 * np.pi * (hour - 9) / 24)
    months = ts.month.to_numpy()
    dust_f = np.array([SEASON[m][0] for m in months])
    wb_off = np.array([SEASON[m][1] for m in months])
    rh_off = np.array([SEASON[m][2] for m in months])
    out = {}
    if mine_type in ("underground", "mixed"):
        b = rng.uniform(0.08, 0.35)
        ch4 = b * (0.85 + 0.25 * work) + ar1(rng, n, 0.995, 0.004) + rng.normal(0, 0.015, n) \
            + spikes(rng, n, 1 / 30, sph, (0.4, 1.3), (0.5, 3))
        out["ch4"] = (np.clip(ch4, 0, None), "%")
        br = rng.uniform(0.15, 0.40)
        ret = br + 0.5 * (ch4 - b) + rng.normal(0, 0.012, n) + spikes(rng, n, 1 / 45, sph, (0.2, 0.5), (1, 4))
        out["ch4_return_air"] = (np.clip(ret, 0, None), "%")
        co = rng.uniform(2, 9) * (0.8 + 0.3 * work) + rng.normal(0, 0.8, n) + spikes(rng, n, 1 / 10, sph, (10, 45), (0.3, 1.5))
        out["co"] = (np.clip(co, 0, None), "ppm")
        dust = rng.uniform(0.7, 1.4) * work * dust_f * np.exp(rng.normal(0, 0.12, n)) + spikes(rng, n, 1 / 25, sph, (0.8, 2.0), (2, 8))
        wb = rng.uniform(27.0, 30.5) + wb_off * 0.5 + 0.4 * diurnal + ar1(rng, n, 0.998, 0.03) \
            + spikes(rng, n, 1 / 40, sph, (2.0, 4.5), (1, 5))
        rh = np.clip(rng.uniform(82, 92) + rh_off * 0.2 + rng.normal(0, 2, n), 40, 100)
    else:
        dust = rng.uniform(0.9, 1.7) * work * dust_f * np.exp(rng.normal(0, 0.15, n)) + spikes(rng, n, 1 / 20, sph, (0.8, 2.2), (2, 10))
        wb = rng.uniform(23.5, 25.5) + wb_off + 2.0 * diurnal + ar1(rng, n, 0.99, 0.08) \
            + spikes(rng, n, 1 / 60, sph, (1.5, 4.0), (2, 6))
        rh = np.clip(rng.uniform(65, 78) + rh_off - 10 * diurnal + rng.normal(0, 3, n), 20, 100)
    out["dust"] = (np.clip(dust, 0.05, None), "mg/m3")
    out["temperature"] = (wb, "degC (wet bulb)")
    out["humidity"] = (rh, "%RH")
    return out


def run(ctx: Ctx) -> None:
    lim = ctx.rules["sensors"]
    intervals = ctx.scale["sensor_interval_minutes"]
    frames = []
    for m in ctx.mines.itertuples():
        rng = ctx.rng(f"sensors:{m.code}")
        step = intervals["underground" if m.type in ("underground", "mixed") else "opencast"]
        ts = pd.date_range(pd.Timestamp(ctx.start, tz="UTC"), ctx.as_of, freq=f"{step}min")
        for stype, (vals, unit) in series_for(rng, m.type, ts, step).items():
            vals = np.round(vals, 3 if stype.startswith("ch4") else 2)
            rule = lim[stype]
            if rule.get("obligation") and isinstance(rule.get("limit"), (int, float)):
                if rule["compare"] == "rolling_8h_mean":
                    w = int(8 * 60 / step)
                    judged = pd.Series(vals).rolling(w, min_periods=w).mean().to_numpy()
                    breached = np.where(np.isnan(judged), False, judged > rule["limit"])
                else:
                    breached = vals > rule["limit"]
                breached = pd.array(breached, dtype="boolean")
            else:
                breached = pd.array([pd.NA] * len(vals), dtype="boolean")
            frames.append(pd.DataFrame({"mine_id": m.id, "sensor_type": stype, "value": vals, "unit": unit,
                                        "recorded_at": ts, "breached": breached}))
    df = pd.concat(frames, ignore_index=True)
    order = {k: i for i, k in enumerate(["ch4", "ch4_return_air", "co", "dust", "temperature", "humidity"])}
    df["_o"] = df["sensor_type"].map(order)
    df = df.sort_values(["recorded_at", "mine_id", "_o"], kind="mergesort").drop(columns="_o").reset_index(drop=True)
    df.insert(0, "id", np.arange(1, len(df) + 1))     # ids rise with time, as the charts expect
    df["resolved"] = False
    df["resolved_at"] = pd.NaT
    ctx.emit("sensor_reading", df)
    b = df[df["breached"].fillna(False).astype(bool)]
    ctx.notes["sensor_breaches"] = b[["id", "mine_id", "sensor_type", "value", "recorded_at"]].copy()
    ctx.notes["sensor_summary"] = {
        "rows": len(df), "breaches": int(len(b)),
        "breaches_by_type": b["sensor_type"].value_counts().to_dict(),
        "gas_sensor_mines": sorted(df.loc[df["sensor_type"] == "ch4", "mine_id"].unique().tolist())}
