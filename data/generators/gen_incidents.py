"""incident - background accidents and dangerous occurrences, calibrated to DGMS rates.

Rates (per year, all Indian coal mines, mean of 2020-2022 - the latest three years published):
  fatal and serious accidents   accident_causes_dgms.csv TOTAL rows (Sanket Tables 2.9, 2.10)
  dangerous occurrences         dangerous_occurrences_dgms.csv (Sanket Table 2.6)
  minor accidents               ASSUMPTION: 3 x serious. DGMS names "minor accidents" as a category
                                (Sanket p.17) but no downloaded DGMS source counts them.
Scaled to our mines and window: expected = annual x days/365 x (the roster's coal output in the
window / national output in the window). National = CIL + SCCL + captives/others from the Ministry
of Coal monthly table, same month a year earlier if not yet published, else FY2024-25 All-India x
the Table 3.7 month share. Counts are Poisson draws around the expectation.

Each incident: a mine weighted by workforce (fatal and serious ones not at the five named demo
mines, which the demo script scores on violations); a time uniform over the window; a DGMS cause
drawn from that severity's cause mix (fatal: Table 2.9; serious and minor: Table 2.10; dangerous
occurrences: Table 2.6, mapped to the nine cause groups); persons affected from DGMS persons per
accident; the most recent violation of the matching category at that mine in the prior 60 days as
`related_violation_id` (60 % of the time, when one exists).
Reporting: fatal within 0.5-3 h; injuries within 2-40 h, but 10 % reported after 48 h (for the
compliance feature to find); dangerous occurrences within 2-10 h. Obligations: fatal RPT-03,
injuries RPT-04, dangerous occurrences RPT-05 (schema/rules.yaml legal section cites them).
Incidents are not in the backend score, so demo scores cannot move.
"""

from __future__ import annotations

import calendar
import math
import re

import numpy as np
import pandas as pd

from common import NAMED, REF, Ctx

YEARS = [2020, 2021, 2022]
MINOR_PER_SERIOUS = 3.0
LATE_SHARE = 0.10
GROUP_TYPE = {"1": "ground_movement", "2": "transportation_winding", "3": "transportation_other", "4": "machinery_other",
              "5": "explosives", "6": "electricity", "7": "gas_dust_fire", "8": "fall_other_than_ground", "9": "other_causes"}
DO_TYPE = {"Over winding of cages, skip or bucket": "transportation_winding", "Breakage of winding rope": "transportation_winding",
           "Breakdown of winding engine, crank shaft, bearing etc.": "transportation_winding",
           "Premature collapse of workings or failure of pillars": "ground_movement", "Subsidences": "ground_movement",
           "Explosives": "explosives", "Irruption of water": "other_causes", "Others": "other_causes",
           "Breakage, fracture or failure of essential parts of machinery or apparatus whereby safety of persons was endangered": "machinery_other"}
TYPE_CATEGORY = {"ground_movement": "roof_strata", "transportation_winding": "transport_haulage",
                 "transportation_other": "transport_haulage", "machinery_other": "machinery", "explosives": "explosives",
                 "electricity": "electrical", "gas_dust_fire": "ventilation_gas", "fall_other_than_ground": "ppe"}
OBLIGATION = {"fatal": "RPT-03", "serious": "RPT-04", "minor": "RPT-04", "dangerous_occurrence": "RPT-05"}


def code_of(label: str) -> str:
    return re.sub(r"[^A-Z0-9]+", "_", label.upper()).strip("_")


def national_t(ctx: Ctx) -> float:
    mon = pd.read_csv(REF / "production_company_monthly.csv")
    mon = mon[mon["mineral"] == "coal"]
    parts = mon[(mon["row_label"].isin(["CIL", "SCCL", "Captives/Others"]))]
    pub = parts.groupby(["year", "month"])["production_mt"].agg(["sum", "count"])
    prof = pd.read_csv(REF / "production_profile_2024_25.csv").set_index("month")["all_india_mt"]
    total = 0.0
    for d in ctx.day_range():
        y, m = d.year, d.month
        if (y, m) in pub.index and pub.loc[(y, m), "count"] == 3:
            mt = pub.loc[(y, m), "sum"]
        elif (y - 1, m) in pub.index and pub.loc[(y - 1, m), "count"] == 3:
            mt = pub.loc[(y - 1, m), "sum"]
        else:
            mt = prof[m]
        total += mt * 1e6 / calendar.monthrange(y, m)[1]
    return total


def rates() -> dict:
    acc = pd.read_csv(REF / "accident_causes_dgms.csv")
    acc = acc[acc["year"].isin(YEARS)]
    tot = acc[acc["row_type"] == "total"]
    cause = acc[acc["row_type"] == "cause"].copy()
    cause["grp"] = cause["cause_group"].str.extract(r"^(\d)")[0]
    do = pd.read_csv(REF / "dangerous_occurrences_dgms.csv")
    do = do[do["year"].isin(YEARS)]
    return {
        "fatal": tot["fatal_accidents"].mean(), "serious": tot["serious_accidents"].mean(),
        "dangerous_occurrence": do.groupby("year")["dangerous_occurrences"].sum().mean(),
        "killed_per_fatal": tot["persons_killed"].sum() / tot["fatal_accidents"].sum(),
        "injured_per_serious": tot["persons_seriously_injured"].sum() / tot["serious_accidents"].sum(),
        "fatal_mix": cause.groupby(["grp", "cause"])["fatal_accidents"].sum(),
        "serious_mix": cause.groupby(["grp", "cause"])["serious_accidents"].sum(),
        "do_mix": do.groupby("cause")["dangerous_occurrences"].sum()}


def run(ctx: Ctx) -> None:
    rng = ctx.rng("incidents")
    r = rates()
    roster_t = float(ctx.tables["daily_production"]["coal_actual_t"].sum())
    share = roster_t / national_t(ctx)
    scale = ctx.days / 365 * share
    lam = {"fatal": r["fatal"] * scale, "serious": r["serious"] * scale,
           "minor": r["serious"] * MINOR_PER_SERIOUS * scale, "dangerous_occurrence": r["dangerous_occurrence"] * scale}
    mines = ctx.mines
    w_all = mines["workforce"].to_numpy(float)
    w_nodemo = np.where(mines["code"].isin(NAMED), 0.0, w_all)
    v = ctx.tables["violation"]
    start = pd.Timestamp(ctx.start, tz="UTC")
    span_h = (ctx.as_of - start).total_seconds() / 3600
    rows = []
    for sev in ["fatal", "serious", "minor", "dangerous_occurrence"]:
        n = int(rng.poisson(lam[sev]))
        if sev == "dangerous_occurrence":
            mix = r["do_mix"][r["do_mix"] > 0]
            labels = list(mix.index)
            p = (mix / mix.sum()).to_numpy()
        else:
            mix = r["fatal_mix"] if sev == "fatal" else r["serious_mix"]
            mix = mix[mix > 0]
            labels = list(mix.index)
            p = (mix / mix.sum()).to_numpy()
        weights = w_nodemo if sev in ("fatal", "serious") and w_nodemo.sum() > 0 else w_all
        for _ in range(n):
            mi = int(rng.choice(len(mines), p=weights / weights.sum()))
            mine = mines.iloc[mi]
            lab = labels[int(rng.choice(len(labels), p=p))]
            if sev == "dangerous_occurrence":
                itype = DO_TYPE.get(lab, "gas_dust_fire")
                desc = code_of(lab)[:60]
            else:
                itype, desc = GROUP_TYPE[lab[0]], code_of(lab[1])
            occurred = start + pd.Timedelta(hours=float(rng.uniform(0, span_h - 72)))
            if sev == "fatal":
                delay = rng.uniform(0.5, 3)
                persons = 1 + int(rng.random() < (r["killed_per_fatal"] - 1))
            elif sev == "dangerous_occurrence":
                delay = rng.uniform(2, 10)
                persons = 0
            else:
                delay = rng.uniform(49, 120) if rng.random() < LATE_SHARE else rng.uniform(2, 40)
                persons = 1 + int(sev == "serious" and rng.random() < (r["injured_per_serious"] - 1))
            reported = min(occurred + pd.Timedelta(hours=float(delay)), ctx.as_of)
            related = None
            cat = TYPE_CATEGORY.get(itype)
            if cat and rng.random() < 0.6:
                prior = v[(v["mine_id"] == mine["id"]) & (v["category"] == cat) & (v["detected_at"] < occurred - pd.Timedelta(days=1))
                          & (v["detected_at"] >= occurred - pd.Timedelta(days=60))]
                if len(prior):
                    related = int(prior.sort_values("detected_at")["id"].iloc[-1])
            rows.append({"mine_id": int(mine["id"]), "occurred_at": occurred, "reported_at": reported, "type": itype,
                         "severity": sev, "persons_affected": persons, "description_code": desc,
                         "related_violation_id": related,
                         "reported_within_48h": (reported - occurred) <= pd.Timedelta(hours=48),
                         "obligation_code": OBLIGATION[sev]})
    inc = pd.DataFrame(rows, columns=["mine_id", "occurred_at", "reported_at", "type", "severity", "persons_affected",
                                      "description_code", "related_violation_id", "reported_within_48h", "obligation_code"])
    inc = inc.sort_values(["occurred_at", "mine_id"], kind="mergesort").reset_index(drop=True)
    inc.insert(0, "id", range(1, len(inc) + 1))
    ctx.emit("incident", inc)
    counts = inc["severity"].value_counts().to_dict()
    ctx.notes["incident_model"] = {
        "roster_share_of_national_output": round(share, 4), "expected": {k: round(x, 2) for k, x in lam.items()},
        "generated": {k: int(counts.get(k, 0)) for k in lam}, "late_reports": int((~inc["reported_within_48h"]).sum()),
        "annual_rates_2020_2022": {k: round(float(r[k]), 1) for k in ["fatal", "serious", "dangerous_occurrence"]},
        "minor_assumption": f"{MINOR_PER_SERIOUS} x serious (no DGMS count of minor accidents downloaded)",
        "poisson_ok": all(abs(counts.get(k, 0) - x) <= 3.3 * math.sqrt(max(x, 1e-9)) + 1 for k, x in lam.items())}
