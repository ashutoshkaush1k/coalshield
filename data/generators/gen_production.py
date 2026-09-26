"""daily_production and production_edit_log - calibrated to Ministry of Coal company figures.

Company month total, first that applies (the basis is recorded in ctx.notes and the checks):
  1 published   production_company_monthly.csv, same year and month
  2 stand-in    same month a year earlier (September 2026 -> September 2025; brief: "labelled stand-in")
  3 stand-in    FY2024-25 company total (production_company_annual.csv) x that month's share of the
                2024-25 year (production_profile_2024_25.csv, Coal Directory Table 3.7; SCCL uses the
                SCCL column, everyone else the CIL column). NLC always uses this: its monthly
                published figure is lignite, while its roster mine (Talabira) is coal.
Split across mines by GEM capacity (brief: "split company monthly figures across mines by
capacity"), config generation.production_split:
  company_capacity_share (default)  a mine's share = its capacity / the company's total operating
      GEM capacity (reference/company_capacity.csv). The roster is a sample of each company's mines,
      so this keeps per-mine output realistic; the roster's rows add up to the covered share of the
      company, and the check grosses them up by that coverage before comparing.
  roster_total  the whole company figure is shared among the roster's mines (the brief's literal
      reading); per-mine output is then inflated where the roster covers few of a company's mines.
A mine whose capacity GEM does not give takes the company's average (capacity / operating mines).
Then over the days of the month (lognormal day noise, Sundays 0.9) and over shifts A/B/C (config
shift_weights, noise, breakdowns), normalised so each complete month sums exactly to the mine's
share; the check re-adds the rows and must land within +-3 % of the published company figure.

Other columns:
  ob_*        coal x company stripping ratio 2024-25 (Coal Directory Table 3.22; NLC's printed 0.00
              is replaced by the CIL ratio); opencast 1, mixed 0.5, underground 0. Read as m3 per t.
  manpower    coal / output per manshift (Table 3.24, 2024-25, company x OC/UG; mixed uses ALL).
  dispatch    actual x lognormal noise, bounded by stock; closing stock is an exact running balance
              (stock_balance, in tenths of a tonne), recomputed after scenario injection.
  status      locked if older than 7 days, submitted after that, the last night shift a draft.
"""

from __future__ import annotations

import calendar

import numpy as np
import pandas as pd

from common import REF, UTC, Ctx

SHIFT_START_IST = {"A": 6, "B": 14, "C": 22}
IST = pd.Timedelta(hours=5, minutes=30)


def stock_balance(df: pd.DataFrame, opening: dict) -> None:
    """closing = previous closing + actual - dispatch, per mine in (date, shift) order, in exact tenths
    of a tonne. Dispatch is the existing value, capped at what is available (stock never negative).
    Called by gen_production and again after scenario injection (stage D5) edits production."""
    order = df.sort_values(["mine_id", "date", "shift"], kind="mergesort").index
    act = np.rint(df.loc[order, "coal_actual_t"].to_numpy() * 10).astype(np.int64)
    want = np.rint(df.loc[order, "dispatch_t"].to_numpy() * 10).astype(np.int64)
    mids = df.loc[order, "mine_id"].to_numpy()
    disp = np.empty_like(act)
    stock = np.empty_like(act)
    s, cur = 0, None
    for i in range(len(act)):
        if mids[i] != cur:
            cur, s = mids[i], int(round(opening[mids[i]] * 10))
        d = min(want[i], s + act[i])
        s = s + act[i] - d
        disp[i], stock[i] = d, s
    df.loc[order, "coal_actual_t"] = act / 10
    df.loc[order, "dispatch_t"] = disp / 10
    df.loc[order, "closing_stock_t"] = stock / 10


def company_months(ctx: Ctx) -> dict:
    mon = pd.read_csv(REF / "production_company_monthly.csv")
    mon = mon[(mon["mineral"] == "coal") & (mon["scope"].str.startswith("company"))]
    ann = pd.read_csv(REF / "production_company_annual.csv")
    ann = ann[(ann["fiscal_year"] == "2024-25") & ann["scope"].str.startswith("company")]
    prof = pd.read_csv(REF / "production_profile_2024_25.csv")
    share = {"cil": dict(zip(prof["month"], prof["cil_mt"] / prof["cil_mt"].sum())),
             "sccl": dict(zip(prof["month"], prof["sccl_mt"] / prof["sccl_mt"].sum()))}
    annual = dict(zip(ann["company_id"], ann["coal_mt"]))
    pub = {(r.company_id, r.year, r.month): (r.production_mt, r.target_mt) for r in mon.itertuples()}

    def get(cid: str, y: int, m: int):
        if cid != "NLC" and (cid, y, m) in pub:
            v, t = pub[(cid, y, m)]
            return v, (None if pd.isna(t) else t), "published"
        if cid != "NLC" and (cid, y - 1, m) in pub:
            v, _ = pub[(cid, y - 1, m)]
            return v, None, f"stand-in: {calendar.month_abbr[m]} {y - 1} published (not yet published for {y})"
        base = annual.get(cid)
        if base is None or pd.isna(base):
            raise ValueError(f"no production basis for {cid} {y}-{m:02d}")
        prof_key = "sccl" if cid == "SCCL" else "cil"
        return (base * share[prof_key][m], None,
                f"stand-in: FY2024-25 total x Table 3.7 {calendar.month_abbr[m]} share ({prof_key.upper()})")
    return get


def run(ctx: Ctx) -> None:
    rng = ctx.rng("production")
    gen = ctx.gen
    get = company_months(ctx)
    ann = pd.read_csv(REF / "production_company_annual.csv")
    ann = ann[ann["fiscal_year"] == "2024-25"]
    sr = dict(zip(ann["company_id"].where(ann["scope"].str.startswith("company"), "CIL_TOTAL"), ann["stripping_ratio"]))
    cil_sr = float(ann.loc[ann["row_label"] == "CIL", "stripping_ratio"].iloc[0])
    oms = pd.read_csv(REF / "oms_company.csv")
    oms_key = {(r.company_id if r.scope.startswith("company") else "CIL_TOTAL", r.mine_type): r.oms_t
               for r in oms.itertuples() if pd.notna(r.oms_t) and r.oms_t > 0}

    mines = ctx.mines
    split = gen.get("production_split", "company_capacity_share")
    ccap = pd.read_csv(REF / "company_capacity.csv").set_index("company_id")
    coverage = {}
    months = sorted({(d.year, d.month) for d in ctx.day_range()})
    shifts = ["A", "B", "C"]
    base_w = np.array([gen["shift_weights"][s] for s in shifts])
    rows, checks, basis_used = [], [], {}
    for (y, m) in months:
        ndays = calendar.monthrange(y, m)[1]
        days = pd.date_range(f"{y}-{m:02d}-01", periods=ndays, freq="D")
        in_window = (days.date >= ctx.start) & (days.date <= ctx.end)
        for cid, grp in mines.groupby("company_id", sort=True):
            total_mt, target_mt, basis = get(cid, y, m)
            basis_used[(cid, y, m)] = basis
            caps = grp["capacity_mtpa"].to_numpy(float)
            if split == "company_capacity_share" and cid in ccap.index and pd.notna(ccap.at[cid, "gem_capacity_mtpa"]):
                total_cap = float(ccap.at[cid, "gem_capacity_mtpa"])
                avg = total_cap / float(ccap.at[cid, "gem_operating_mines"])
                caps = np.where(grp["capacity_imputed"].to_numpy(bool), avg, caps)
                shares = caps / max(total_cap, caps.sum())
            else:
                shares = caps / caps.sum()
            coverage[cid] = float(shares.sum())
            for (mine, s) in zip(grp.itertuples(), shares):
                month_t = total_mt * 1e6 * s
                target_t = (target_mt if target_mt is not None else total_mt) * 1e6 * s
                dw = np.exp(rng.normal(0, 0.08, ndays)) * np.where(days.dayofweek == 6, 0.9, 1.0)
                dw /= dw.sum()
                for di in np.flatnonzero(in_window):
                    brk = np.where(rng.random(3) < gen["breakdown_probability"],
                                   np.round(rng.uniform(*gen["breakdown_hours"], 3) * 2) / 2, 0.0)
                    sw = base_w * np.exp(rng.normal(0, 0.05, 3)) * (1 - brk / 8)
                    sw /= sw.sum()
                    for k, sh in enumerate(shifts):
                        rows.append((mine.id, days[di].date(), sh, target_t / ndays * base_w[k],
                                     month_t * dw[di] * sw[k], brk[k], cid, mine.type))
    df = pd.DataFrame(rows, columns=["mine_id", "date", "shift", "coal_target_t", "coal_actual_t", "breakdown_hours",
                                     "company_id", "type"])
    df = df.sort_values(["mine_id", "date", "shift"]).reset_index(drop=True)
    df["coal_target_t"] = df["coal_target_t"].round(1)
    df["coal_actual_t"] = df["coal_actual_t"].round(1)

    ratio = df["company_id"].map(lambda c: sr.get(c) if (c in sr and sr.get(c, 0) > 0.05) else cil_sr)
    oc_part = df["type"].map({"opencast": 1.0, "mixed": 0.5, "underground": 0.0})
    df["ob_target_m3"] = (df["coal_target_t"] * ratio * oc_part).round(1)
    df["ob_actual_m3"] = (df["coal_actual_t"] * ratio * oc_part * np.exp(rng.normal(0, 0.06, len(df)))).round(1)
    tkey = df["type"].map({"opencast": "OC", "underground": "UG", "mixed": "ALL"})
    df["oms"] = [oms_key.get((c, t), oms_key.get(("CIL_TOTAL", t))) for c, t in zip(df["company_id"], tkey)]
    df["manpower_present"] = np.maximum(1, np.round(df["coal_actual_t"] / df["oms"] * np.exp(rng.normal(0, 0.05, len(df))))).astype(int)

    # dispatch targets (actual x lognormal noise) and an exact running stock balance per mine
    df["dispatch_t"] = (df["coal_actual_t"] * np.exp(rng.normal(0, 0.08, len(df)))).round(1)
    opening = (df.groupby("mine_id")["coal_actual_t"].mean() * 3 * 7).round(1).to_dict()   # about a week of output
    ctx.notes["opening_stock"] = opening
    stock_balance(df, opening)

    df["remarks"] = np.where(df["breakdown_hours"] > 0,
                             "Equipment breakdown " + df["breakdown_hours"].map(lambda h: f"{h:g}") + " h", None)
    age = (pd.Timestamp(ctx.end) - pd.to_datetime(df["date"])).dt.days
    last_c = (age == 0) & (df["shift"] == "C")
    df["status"] = np.where(last_c, "draft", np.where(age > 7, "locked", "submitted"))
    shift_end_utc = (pd.to_datetime(df["date"]).dt.tz_localize(UTC) + pd.to_timedelta(df["shift"].map(SHIFT_START_IST) + 8, unit="h") - IST)
    sub_at = shift_end_utc + pd.to_timedelta(rng.uniform(0.5, 6, len(df)), unit="h")
    sub_at = sub_at.where(sub_at <= ctx.as_of, ctx.as_of)
    df["submitted_at"] = sub_at.where(df["status"] != "draft")
    df["submitted_by"] = df["mine_id"].map(ctx.notes["head_of"]).where(df["status"] != "draft")
    df.insert(0, "id", range(1, len(df) + 1))

    # edit log: a few locked entries corrected with a reason
    locked = df.index[df["status"] == "locked"].to_numpy()
    pick = np.sort(rng.choice(locked, size=max(1, len(locked) // 200), replace=False)) if len(locked) else []
    reasons = ["Weighbridge reading corrected after reconciliation", "Late shift report received from the section",
               "Duplicate trip entry removed", "Manpower corrected from attendance register"]
    edits = []
    for i in pick:
        r = df.loc[i]
        field = "manpower_present" if rng.random() < 0.3 else "coal_actual_t"
        cur = r[field]
        old = int(round(cur * rng.uniform(0.9, 0.97))) if field == "manpower_present" else round(cur * rng.uniform(1.02, 1.08), 1)
        when = min(r["submitted_at"] + pd.Timedelta(days=int(rng.integers(1, 4))), ctx.as_of)
        edits.append({"production_id": r["id"], "field": field, "old_value": str(old), "new_value": str(cur),
                      "reason": reasons[int(rng.integers(len(reasons))) if field == "coal_actual_t" else 3],
                      "edited_by": r["submitted_by"], "edited_at": when})
    log = pd.DataFrame(edits, columns=["production_id", "field", "old_value", "new_value", "reason", "edited_by", "edited_at"])
    log.insert(0, "id", range(1, len(log) + 1))

    # re-aggregation checks: complete months in the window against the company basis
    df["y"], df["m"] = pd.to_datetime(df["date"]).dt.year, pd.to_datetime(df["date"]).dt.month
    for (cid, y, m), grp in df.groupby(["company_id", "y", "m"]):
        ndays = calendar.monthrange(y, m)[1]
        complete = grp["date"].nunique() == ndays and grp["mine_id"].nunique() == (mines["company_id"] == cid).sum()
        basis = basis_used[(cid, y, m)]
        expected = get(cid, y, m)[0] * 1e6
        got = grp["coal_actual_t"].sum() / coverage[cid]   # grossed up by the roster's capacity coverage
        checks.append({"company_id": cid, "year": y, "month": m, "basis": basis, "complete_month": bool(complete),
                       "split": split, "roster_coverage": round(coverage[cid], 4),
                       "expected_t": round(expected, 1), "generated_t": round(got, 1),
                       "deviation_pct": (round((got - expected) / expected * 100, 3) if expected > 0 else 0.0) if complete else None,
                       # a published 0.00 (NEC, Jul-Aug 2026) must re-aggregate to zero
                       "pass": ((abs(got - expected) / expected <= 0.03) if expected > 0 else abs(got) < 1.0) if complete else None})
    ctx.notes["production_checks"] = checks
    cols = ["id", "mine_id", "date", "shift", "coal_target_t", "coal_actual_t", "ob_target_m3", "ob_actual_m3",
            "dispatch_t", "closing_stock_t", "breakdown_hours", "manpower_present", "remarks", "status",
            "submitted_by", "submitted_at"]
    ctx.emit("daily_production", df[cols])
    ctx.emit("production_edit_log", log)
