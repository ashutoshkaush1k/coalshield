"""Stage D4 entry point: generate every table for one preset, check calibration, write data/out/<preset>/.

    data\\.venv\\Scripts\\python.exe data\\generators\\generate.py [--preset small|demo|full]

Writes <table>.csv for every schema, _manifest.json (rows, bytes, sha256 per table, time) and
_checks.json (calibration checks, pass/fail). Exits 1 if any check fails - including the demo-score
check, which scores the generated data with the CURRENT backend formula and must reproduce the
roster's demo scores (and, with all 74 mines, 100/80/70/60/45, 6/21/47 and average 83.2).
Stage D5: after the generators, inject_scenarios adds labelled scenarios and decoys (before alerts
are derived, so injected events get alerts), then writes scenario_label.csv and
scenario_expectations.json. Demo scores are measured before and after injection and must be equal.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).parent))
import gen_alerts  # noqa: E402
import gen_contractors  # noqa: E402
import gen_environment  # noqa: E402
import gen_grievances  # noqa: E402
import gen_inspections  # noqa: E402
import gen_org  # noqa: E402
import gen_production  # noqa: E402
import gen_requests  # noqa: E402
import gen_sensors  # noqa: E402
import gen_users  # noqa: E402
import inject_scenarios  # noqa: E402
from common import NAMED, GenerationError, make_ctx, write_all  # noqa: E402

STEPS = [("org", gen_org), ("users", gen_users), ("contractors", gen_contractors), ("grievances", gen_grievances),
         ("inspections", gen_inspections), ("production", gen_production), ("requests", gen_requests),
         ("sensors", gen_sensors), ("environment", gen_environment)]
# Deadlines and validity dates may lie after the reference time; every other date may not.
FORWARD_OK = {("corrective_action", "due_at"), ("production_detail_request", "due_at"), ("grievance", "sla_due_at"),
              ("contractor", "licence_valid_to"), ("contract", "end_date"), ("contract_worker", "vt_cert_valid_to")}


def check(name: str, ok: bool, detail) -> dict:
    return {"check": name, "pass": bool(ok), "detail": detail}


def mine_scores(ctx) -> dict[str, float]:
    """Each mine's score under the current backend formula, from the current tables."""
    base = ctx.config["repo_demo_baseline"]
    v = ctx.tables["violation"]
    open_n = v[~v["resolved"].astype(bool)].groupby("mine_id").size()
    s = ctx.tables["sensor_reading"]
    since = ctx.as_of - pd.Timedelta(hours=float(base["breach_window_hours"]))
    br_n = s[s["breached"].fillna(False).astype(bool) & ~s["resolved"].astype(bool) & (s["recorded_at"] >= since)].groupby("mine_id").size()
    raw = 100 - (ctx.mines["id"].map(open_n).fillna(0) * base["weight_ppe"] + ctx.mines["id"].map(br_n).fillna(0) * base["weight_env"])
    return dict(zip(ctx.mines["code"], raw.clip(0, 100).round(1)))


def demo_scores(ctx) -> list[dict]:
    base = ctx.config["repo_demo_baseline"]
    v = ctx.tables["violation"]
    open_n = v[~v["resolved"].astype(bool)].groupby("mine_id").size()
    s = ctx.tables["sensor_reading"]
    since = ctx.as_of - pd.Timedelta(hours=float(base["breach_window_hours"]))
    br = s[s["breached"].fillna(False).astype(bool) & ~s["resolved"].astype(bool) & (s["recorded_at"] >= since)]
    br_n = br.groupby("mine_id").size()
    m = ctx.mines.copy()
    raw = 100 - (m["id"].map(open_n).fillna(0) * base["weight_ppe"] + m["id"].map(br_n).fillna(0) * base["weight_env"])
    m["score"] = raw.clip(0, 100).round(1)
    lb = base["risk_bands"]
    m["band"] = np.where(m["score"] >= lb["low_min"], "LOW", np.where(m["score"] >= lb["medium_min"], "MEDIUM", "HIGH"))
    out = []
    mism = m[(m["score"] != m["demo_score"]) | (m["band"] != m["demo_risk_level"])]
    out.append(check("demo_scores: every mine's score from generated data equals its roster demo score",
                     mism.empty, {"mismatches": mism[["code", "score", "demo_score"]].to_dict("records"),
                                  "in_window_breaches": int(len(br))}))
    exp = base["expected"]
    named = {c: float(m.loc[m["code"] == c, "score"].iloc[0]) for c in NAMED if (m["code"] == c).any()}
    out.append(check("demo_scores: named mines 100/80/70/60/45", named == {k: float(v) for k, v in exp["named_scores"].items() if k in named},
                     named))
    if ctx.scale.get("mines") == "all":
        bands = m["band"].value_counts().to_dict()
        avg = round(float(m["score"].mean()), 1)
        out.append(check("demo_scores: band distribution 6 High / 21 Medium / 47 Low",
                         bands == exp["band_counts"], bands))
        out.append(check("demo_scores: average 83.2", avg == exp["average_score"], avg))
    return out


def calibration(ctx) -> list[dict]:
    out = []
    pc = [c for c in ctx.notes["production_checks"] if c["complete_month"]]
    worst = max((abs(c["deviation_pct"]) for c in pc), default=0)
    out.append(check("production: complete company-months re-aggregate within +-3 % of the company figure"
                     + ("" if pc else " (not applicable: no complete month in the window)"),
                     all(c["pass"] for c in pc),
                     {"company_months_checked": len(pc), "worst_deviation_pct": worst,
                      "bases": sorted({c["basis"] for c in ctx.notes["production_checks"]}),
                      "partial_months_not_checked": sum(not c["complete_month"] for c in ctx.notes["production_checks"])}))
    s = ctx.tables["sensor_reading"]
    types = dict(zip(ctx.mines["id"], ctx.mines["type"]))
    gas_mines = set(s.loc[s["sensor_type"].isin(["ch4", "ch4_return_air", "co"]), "mine_id"])
    ug = {i for i, t in types.items() if t in ("underground", "mixed")}
    out.append(check("sensors: gas sensors only at underground and mixed mines, and at all of them",
                     gas_mines == ug, {"gas_mines": len(gas_mines), "underground_or_mixed": len(ug)}))
    judged = s.loc[s["breached"].notna(), "sensor_type"].unique().tolist()
    cited = [k for k, v in ctx.rules["sensors"].items() if v.get("obligation")]
    out.append(check("sensors: breaches judged only where rules.yaml cites a verified obligation",
                     set(judged) <= set(cited), {"judged": sorted(judged), "cited": {k: ctx.rules["sensors"][k]["obligation"] for k in cited},
                                                 "breaches": ctx.notes["sensor_summary"]["breaches_by_type"]}))
    v = ctx.tables["violation"]
    iv = v[v["source"] == "inspection"]
    model = ctx.notes["inspection_model"]
    if len(iv) < 100:
        out.append(check(f"violations: category mix vs MSHA x DGMS target (not applicable: {len(iv)} inspection violations, "
                         "fewer than 100)", True, {"violations": len(iv)}))
    else:
        share = iv["category"].value_counts(normalize=True)
        n_ug = sum(1 for m in ctx.mines.itertuples() if m.type != "opencast")
        w_ug = n_ug / len(ctx.mines)
        target = {k: w_ug * model["mix"][k] + (1 - w_ug) * model["mix_surface"][k] for k in model["mix"]}
        tvd = 0.5 * sum(abs(share.get(k, 0) - t) for k, t in target.items())
        out.append(check("violations: category mix within 0.15 total-variation distance of the MSHA x DGMS target",
                         tvd <= 0.15, {"tvd": round(tvd, 3), "violations": len(iv),
                                       "generated": share.round(3).to_dict(), "target": {k: round(t, 3) for k, t in target.items()}}))
    exp_v = sum(e["violations"] for e in model["expected"].values())
    out.append(check("violations: inspection violations within 25 % of the MSHA-rate expectation",
                     abs(len(iv) - exp_v) <= 0.25 * exp_v + 5, {"generated": len(iv), "expected": round(exp_v, 1)}))
    out.append(check("legal: every legal value used cites a verified obligation (rules.yaml)", True,
                     {k: v["obligation"] for k, v in ctx.rules["legal"].items()}))
    e = ctx.tables["env_reading"]
    lab_ok = e["data_kind"].isin(["calibrated", "synthetic"]).all() and \
        e.loc[e["data_kind"] == "calibrated", "station_id"].notna().all() and \
        e.loc[e["data_kind"] == "synthetic", "station_id"].isna().all()
    out.append(check("environment: every row labelled calibrated (nearest station) or synthetic", bool(lab_ok),
                     ctx.notes["env_summary"]))
    g = ctx.tables["grievance"]
    lang_of = dict(zip(ctx.mines["id"], ctx.mines["language"]))
    match = float((g["language"] == g["mine_id"].map(lang_of)).mean()) if len(g) else 1.0
    out.append(check("grievances: language matches the mine's region for most tickets (>= 65 %)", match >= 0.65,
                     {"share_in_region_language": round(match, 3), "languages": g["language"].value_counts().to_dict()}))
    c = ctx.tables["contractor"]
    masked = all(c[col].str.contains("XXXX").all() for col in ["registration_no", "labour_licence_no", "epf_code", "esi_code", "contact"])
    gc = g["contact"].dropna()
    masked &= bool(gc.str.fullmatch(r"X{6}\d{4}").all())
    out.append(check("people: identifiers and contacts masked; names from Faker/regional lists (fictitious)", masked,
                     {"contractors": len(c), "workers": len(ctx.tables["contract_worker"])}))
    future = []
    for t, df in ctx.tables.items():
        for col in ctx.schemas[t]["columns"]:
            if col["type"] in ("date", "datetime") and (t, col["name"]) not in FORWARD_OK:
                vals = pd.to_datetime(df[col["name"]], utc=True).dropna()
                if (vals > ctx.as_of).any():
                    future.append(f"{t}.{col['name']}")
    out.append(check("dates: nothing dated after the end of the window (deadlines and validity dates excepted)",
                     not future, future))
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--preset", default=None, help="small | demo | full (default: config.yaml scale)")
    ap.add_argument("--roster", default=None, choices=["real", "seed"], help="override config.yaml mine_roster")
    ap.add_argument("--out", default=None, help="output root instead of data/out (the preset folder goes inside)")
    args = ap.parse_args()
    t0 = time.time()
    try:
        ctx = make_ctx(args.preset, Path(args.out) if args.out else None, args.roster)
        print(f"[generate] preset {ctx.preset}: {len(ctx.mines)} mines ({ctx.roster_name} roster), "
              f"{ctx.start} .. {ctx.end} ({ctx.days} days)")
        for name, mod in STEPS:
            t = time.time()
            mod.run(ctx)
            print(f"  {name:12s} {time.time() - t:6.1f} s")
        before = mine_scores(ctx)
        pre = demo_scores(ctx)
        t = time.time()
        inject_scenarios.run(ctx)
        gen_alerts.run(ctx)
        inject_scenarios.finalize(ctx)
        print(f"  {'scenarios+alerts':12s} {time.time() - t:6.1f} s")
        after = mine_scores(ctx)
        moved = {c: (before[c], after[c]) for c in before if before[c] != after[c]}
        checks = calibration(ctx) + demo_scores(ctx)
        checks.append(check("scenarios: demo checks passed before injection too", all(c["pass"] for c in pre),
                            [c["check"] for c in pre if not c["pass"]]))
        checks.append(check("scenarios: no mine's score moved during injection", not moved, moved))
        info = write_all(ctx)
        (ctx.out_dir / "scenario_expectations.json").write_text(
            json.dumps(ctx.notes["scenario_expectations"], indent=1, ensure_ascii=False, default=str) + "\n", encoding="utf-8")
    except GenerationError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return 1
    elapsed = round(time.time() - t0, 1)
    manifest = {"preset": ctx.preset, "roster": ctx.roster_name, "mines": len(ctx.mines), "window": [str(ctx.start), str(ctx.end)],
                "seed": ctx.seed, "seconds": elapsed, "total_bytes": sum(v["bytes"] for v in info.values()), "tables": info}
    (ctx.out_dir / "_manifest.json").write_text(json.dumps(manifest, indent=1, sort_keys=True) + "\n", encoding="utf-8")
    (ctx.out_dir / "_checks.json").write_text(json.dumps(checks, indent=1, default=str, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"[generate] wrote {len(info)} tables, {manifest['total_bytes'] / 1e6:.1f} MB, in {elapsed} s -> {ctx.out_dir}")
    for t, v in info.items():
        print(f"    {t:28s} {v['rows']:>10,} rows  {v['bytes'] / 1e6:8.2f} MB")
    failed = [c for c in checks if not c["pass"]]
    for c in checks:
        print(f"  [{'PASS' if c['pass'] else 'FAIL'}] {c['check']}")
    if failed:
        print(f"ERROR: {len(failed)} check(s) failed - see {ctx.out_dir / '_checks.json'}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
