"""Stage D3: reference/msha_rates.csv - US coal mine-year inspection, violation and accident rates:
the calibration base for synthetic inspections (D4).

Source (S08): MSHA Open Government Data, already filtered in D2 to coal (COAL_METAL_IND = C) and
2016-09-25..2026-09-25 (raw/msha/filtered/*.parquet). Only the full calendar years 2017-2025 are
used; 2016 and 2026 are partial.

One row per mine and calendar year with at least one inspection that year:
  inspections          inspection events begun that year (CAL_YR)
  regular_inspections  of which "Regular Safety and Health Inspection"
  inspection_hours     SUM(TOTAL_INSP_HOURS) of those events
  violations           citations and orders issued that year, and by Indian category via
                       reference/crosswalk_msha_india.csv (v_<category>; v_unmapped if no rule)
  ss_violations        violations marked significant and substantial (SIG_SUB = Y); ss_share
  accidents            accident reports that year, excluding "ACCIDENT ONLY" (no injury) and
                       injuries to non-employees; fatal_accidents = DEGREE_INJURY "FATALITY";
                       lost_time = any days away from work
  mine_type, state, employees_now   from the mine record (current values, not per year)
Rates per 100 inspection hours are left to the generator (the counts are all here).
"""

from __future__ import annotations

import csv
import re
import sys

import pandas as pd

from common import DATA, sha256_file

F = DATA / "raw/msha/filtered"
XW = DATA / "reference/crosswalk_msha_india.csv"
OUT = DATA / "reference/msha_rates.csv"
YEARS = range(2017, 2026)


def load_rules() -> list[tuple[int, int, int, str]]:
    rules = []
    for r in csv.DictReader(XW.open(encoding="utf-8")):
        if r["source_system"] == "MSHA" and r["cfr_part"]:
            rules.append((int(r["cfr_part"]), int(r["section_from"]), int(r["section_to"]), r["indian_category"]))
    act = next(r["indian_category"] for r in csv.DictReader(XW.open(encoding="utf-8")) if r["source_code"] == "Mine Act section")
    return rules, act


def categorise(part_section: str, section_of_act: str, rules, act) -> str:
    m = re.match(r"^\s*(\d+)\.(\d+)", part_section or "")
    if m:
        p, s = int(m.group(1)), int(m.group(2))
        return next((c for rp, lo, hi, c in rules if rp == p and lo <= s <= hi), "unmapped")
    return act if (section_of_act or "").strip() else "unmapped"


def main() -> int:
    for f in ("mines", "inspections", "violations", "accidents"):
        if not (F / f"{f}.parquet").exists():
            print(f"ERROR: raw/msha/filtered/{f}.parquet missing - run data\\run_data.bat download", file=sys.stderr)
            return 1
    if not XW.exists():
        print("ERROR: reference/crosswalk_msha_india.csv missing - run clean_crosswalk_msha.py", file=sys.stderr)
        return 1
    rules, act = load_rules()
    cats = sorted({c for *_, c in rules} | {act})

    ins = pd.read_parquet(F / "inspections.parquet", columns=["EVENT_NO", "MINE_ID", "CAL_YR", "ACTIVITY", "SUM(TOTAL_INSP_HOURS)"])
    ins["CAL_YR"] = pd.to_numeric(ins["CAL_YR"], errors="coerce")
    ins = ins[ins["CAL_YR"].isin(YEARS)].drop_duplicates("EVENT_NO")
    ins["hours"] = pd.to_numeric(ins["SUM(TOTAL_INSP_HOURS)"], errors="coerce").fillna(0)
    ins["regular"] = ins["ACTIVITY"].eq("Regular Safety and Health Inspection")
    g = ins.groupby(["MINE_ID", "CAL_YR"]).agg(inspections=("EVENT_NO", "size"), regular_inspections=("regular", "sum"),
                                               inspection_hours=("hours", "sum"))

    vio = pd.read_parquet(F / "violations.parquet", columns=["VIOLATION_NO", "MINE_ID", "CAL_YR", "PART_SECTION",
                                                              "SECTION_OF_ACT", "SIG_SUB"])
    vio["CAL_YR"] = pd.to_numeric(vio["CAL_YR"], errors="coerce")
    vio = vio[vio["CAL_YR"].isin(YEARS)].drop_duplicates("VIOLATION_NO")
    keys = vio[["PART_SECTION", "SECTION_OF_ACT"]].fillna("").astype(str).drop_duplicates()
    keys["cat"] = [categorise(p, a, rules, act) for p, a in zip(keys["PART_SECTION"], keys["SECTION_OF_ACT"])]
    vio = vio.fillna({"PART_SECTION": "", "SECTION_OF_ACT": ""}).astype({"PART_SECTION": str, "SECTION_OF_ACT": str})
    vio = vio.merge(keys, on=["PART_SECTION", "SECTION_OF_ACT"], how="left")
    vio["ss"] = vio["SIG_SUB"].eq("Y")
    vc = vio.pivot_table(index=["MINE_ID", "CAL_YR"], columns="cat", values="VIOLATION_NO", aggfunc="count", fill_value=0)
    vc.columns = [f"v_{c}" for c in vc.columns]
    vt = vio.groupby(["MINE_ID", "CAL_YR"]).agg(violations=("VIOLATION_NO", "size"), ss_violations=("ss", "sum"))

    acc = pd.read_parquet(F / "accidents.parquet", columns=["MINE_ID", "CAL_YR", "DEGREE_INJURY", "DOCUMENT_NO", "DAYS_LOST"])
    acc["CAL_YR"] = pd.to_numeric(acc["CAL_YR"], errors="coerce")
    acc = acc[acc["CAL_YR"].isin(YEARS) & ~acc["DEGREE_INJURY"].isin(["ACCIDENT ONLY", "INJURIES INVOLVNG NONEMPLOYEES"])]
    acc = acc.drop_duplicates("DOCUMENT_NO")
    acc["fatal"] = acc["DEGREE_INJURY"].eq("FATALITY")
    acc["lost"] = acc["DEGREE_INJURY"].str.contains("DAYS AWAY|DYS AWY FRM WRK &", regex=True, na=False)
    ac = acc.groupby(["MINE_ID", "CAL_YR"]).agg(accidents=("DOCUMENT_NO", "size"), fatal_accidents=("fatal", "sum"),
                                                lost_time_accidents=("lost", "sum"))

    mines = pd.read_parquet(F / "mines.parquet", columns=["MINE_ID", "CURRENT_MINE_TYPE", "STATE", "NO_EMPLOYEES"])
    mines = mines.drop_duplicates("MINE_ID").set_index("MINE_ID")

    df = g.join(vt, how="left").join(vc, how="left").join(ac, how="left").fillna(0)
    for c in [f"v_{c}" for c in cats] + ["v_unmapped"]:
        if c not in df:
            df[c] = 0
    df = df.reset_index().rename(columns={"CAL_YR": "year", "MINE_ID": "mine_id"})
    df = df.join(mines, on="mine_id")
    df["ss_share"] = (df["ss_violations"] / df["violations"]).where(df["violations"] > 0)
    ints = ["inspections", "regular_inspections", "violations", "ss_violations", "accidents", "fatal_accidents",
            "lost_time_accidents"] + [f"v_{c}" for c in cats] + ["v_unmapped"]
    df[ints] = df[ints].astype(int)
    df["year"] = df["year"].astype(int)
    df["inspection_hours"] = df["inspection_hours"].round(1)
    df["ss_share"] = df["ss_share"].round(4)
    out = df.rename(columns={"CURRENT_MINE_TYPE": "mine_type", "STATE": "state", "NO_EMPLOYEES": "employees_now"})
    cols = ["mine_id", "year", "mine_type", "state", "employees_now", "inspections", "regular_inspections",
            "inspection_hours", "violations", "ss_violations", "ss_share"] + [f"v_{c}" for c in cats] + \
           ["v_unmapped", "accidents", "fatal_accidents", "lost_time_accidents"]
    out = out[cols].sort_values(["mine_id", "year"])
    out.columns = [c.replace("/", "_") for c in out.columns]
    out.to_csv(OUT, index=False, lineterminator="\n")

    tot = vio["cat"].value_counts()
    print(f"Wrote reference/msha_rates.csv: {len(out)} mine-years, {out['mine_id'].nunique()} mines, 2017-2025; "
          f"sha256 {sha256_file(OUT)[:16]}")
    print(f"  violations {len(vio)}: " + ", ".join(f"{k} {v / len(vio):.1%}" for k, v in tot.items()))
    print(f"  S&S share {vio['ss'].mean():.1%}; accidents {len(acc)} ({int(acc['fatal'].sum())} fatal); "
          f"inspection hours {out['inspection_hours'].sum():,.0f}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
