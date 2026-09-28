"""Stage D5: validate a generated preset from the files on disk and print a pass/fail report.

    data\\.venv\\Scripts\\python.exe data\\generators\\validate.py --preset demo [--roster seed]
                                  [--out DIR] [--generate] [--determinism]

--generate     run generate.py for the preset (and roster) first
--determinism  generate twice more into temporary folders; every CSV and the expectations file
               must be byte-identical across all three
Writes <out>/<preset>/_validation.json and exits 1 if any check fails.

Checks
  V1 schema        every table present; header = schema columns in order; every value matches its
                   type, enum and nullability (schema/*.yaml)
  V2 keys          primary keys unique; declared unique column sets unique
  V3 references    every foreign key resolves; no orphans (each violation has one corrective action,
                   file rows point at existing entities, labels point at existing rows)
  V4 dates         nothing after the window end, except deadlines and validity dates
  V5 production    complete company-months re-aggregate within +-3 % of the company figure
  V6 scenarios     every scenario_label row exists; every signal in scenario_expectations.json holds
                   (positives show the pattern, decoys show they are legitimate)
  V7 legal         rules.yaml cites verified obligations; alert citations are verified; every sensor
                   `breached` value agrees with the cited limit (re-computed); no breach without one
  V8 demo scores   current backend formula on the CSVs: every mine = roster demo score, named mines
                   100/80/70/60/45, and with all 74 mines 6/21/47 and average 83.2
  V9 determinism   (with --determinism)
  V10 stock        opening + production - dispatch = closing stock for every mine-day (opening =
                   the previous day's closing; each mine's first day has no recorded opening)
  V11 incidents    reported_within_48h agrees with the timestamps; obligation code matches severity
                   (fatal RPT-03, injuries RPT-04, dangerous occurrence RPT-05) and is verified
  V12 obligations  tasks only for verified mine obligations with a calendar frequency (RPT-08 never);
                   applicability (underground-only, 500+ workers); every period present; due dates
                   (law or product setting); task status and escalation agree with the submissions
  V13 tracking     grievance tracking codes: 8 characters of the unambiguous alphabet, derived from
                   (seed, ticket), unique
"""

from __future__ import annotations

import argparse
import calendar
import hashlib
import json
import re
import subprocess
import sys
import tempfile
import time
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).parent))
from common import DATA, NAMED, REF, load_config, load_roster, load_rules, load_schemas  # noqa: E402
from gen_production import company_months  # noqa: E402

TYPE_RE = {"integer": r"-?\d+", "number": r"-?\d+(\.\d+)?", "boolean": r"true|false", "date": r"\d{4}-\d{2}-\d{2}",
           "month": r"\d{4}-\d{2}", "datetime": r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z",
           "wkt_point": r"POINT\(-?\d+(\.\d+)? -?\d+(\.\d+)?\)"}
FORWARD_OK = {("corrective_action", "due_at"), ("production_detail_request", "due_at"), ("grievance", "sla_due_at"),
              ("obligation_task", "due_at"), ("obligation_task", "period_end"),
              ("contractor", "licence_valid_to"), ("contract", "end_date"), ("contract_worker", "vt_cert_valid_to")}
IST = pd.Timedelta(hours=5, minutes=30)
BIG = 1_000_000


class Report:
    def __init__(self):
        self.rows = []

    def add(self, check: str, ok: bool, detail=None):
        self.rows.append({"check": check, "pass": bool(ok), "detail": detail})
        print(f"  [{'PASS' if ok else 'FAIL'}] {check}" + (f"  ({detail})" if (detail and not ok) else ""))


def read(folder: Path, table: str, **kw) -> pd.DataFrame:
    return pd.read_csv(folder / f"{table}.csv", dtype=str, keep_default_na=False, **kw)


def ts(s: pd.Series) -> pd.Series:
    return pd.to_datetime(s.replace("", None), utc=True)


# ---------------------------------------------------------------------------------------------
def v1_v2_schema(folder: Path, schemas: dict, rep: Report) -> dict[str, set]:
    """Schema conformance and keys; returns primary-key sets for the reference checks."""
    pks, problems, keyprob = {}, [], []
    for table, sc in sorted(schemas.items()):
        path = folder / f"{table}.csv"
        if not path.exists():
            problems.append(f"{table}: file missing")
            continue
        cols = [c["name"] for c in sc["columns"]]
        header = path.open(encoding="utf-8").readline().rstrip("\n").split(",")
        if header != cols:
            problems.append(f"{table}: header {header} != schema {cols}")
            continue
        pk = sc.get("primary_key")
        ids = []
        uniq_seen = [set() for _ in sc.get("unique", [])]
        for chunk in pd.read_csv(path, dtype=str, keep_default_na=False, chunksize=BIG):
            for c in sc["columns"]:
                s = chunk[c["name"]]
                empty = s == ""
                if empty.any() and not c.get("nullable"):
                    problems.append(f"{table}.{c['name']}: {int(empty.sum())} empty values in a non-nullable column")
                vals = s[~empty]
                if not len(vals):
                    continue
                if c["type"] == "enum":
                    bad = set(vals.unique()) - set(map(str, c["values"]))
                    if bad:
                        problems.append(f"{table}.{c['name']}: values outside enum {sorted(bad)[:3]}")
                elif c["type"] == "json":
                    for v in vals:
                        try:
                            if not isinstance(json.loads(v), dict):
                                raise ValueError
                        except ValueError:
                            problems.append(f"{table}.{c['name']}: not a JSON object: {v[:40]}")
                            break
                elif c["type"] in TYPE_RE:
                    bad = ~vals.str.fullmatch(TYPE_RE[c["type"]])
                    if bad.any():
                        problems.append(f"{table}.{c['name']}: {int(bad.sum())} values not {c['type']}, e.g. {vals[bad].iloc[0]!r}")
            if pk:
                ids.append(chunk[pk].astype(np.int64).to_numpy())
            for k, u in enumerate(sc.get("unique", [])):
                keys = list(zip(*[chunk[x] for x in u]))
                if len(set(keys)) != len(keys) or uniq_seen[k].intersection(keys):
                    keyprob.append(f"{table}: duplicate {u}")
                uniq_seen[k].update(keys)
        if pk:
            arr = np.concatenate(ids) if ids else np.array([], dtype=np.int64)
            if len(np.unique(arr)) != len(arr):
                keyprob.append(f"{table}: duplicate primary key")
            pks[table] = set(arr.tolist()) if len(arr) < BIG else None
    rep.add("V1 schema: every CSV matches its schema (columns, types, enums, nullability)", not problems, problems[:8])
    rep.add("V2 keys: primary keys and unique columns are unique", not keyprob, keyprob[:8])
    return pks


def v3_references(folder: Path, schemas: dict, pks: dict, rep: Report) -> None:
    probs = []
    for table, sc in schemas.items():
        fks = [c for c in sc["columns"] if c.get("fk")]
        if not fks:
            continue
        cols = [c["name"] for c in fks]
        for chunk in pd.read_csv(folder / f"{table}.csv", dtype=str, keep_default_na=False, usecols=cols, chunksize=BIG):
            for c in fks:
                ref_t = c["fk"].split(".")[0]
                vals = chunk[c["name"]]
                vals = vals[vals != ""].astype(np.int64)
                target = pks.get(ref_t)
                if target is None:
                    continue
                missing = set(vals.unique().tolist()) - target
                if missing:
                    probs.append(f"{table}.{c['name']} -> {ref_t}: {len(missing)} unresolved, e.g. {sorted(missing)[:3]}")
    v, ca = read(folder, "violation"), read(folder, "corrective_action")
    per = ca.groupby("violation_id").size()
    if set(per.index) != set(v["id"]) or (per != 1).any():
        probs.append("violation <-> corrective_action is not one-to-one")
    both = v.merge(ca, left_on="id", right_on="violation_id", suffixes=("", "_ca"))
    if ((both["resolved"] == "true") != (both["status"] == "resolved")).any():
        probs.append("violation.resolved disagrees with its corrective action status")
    if (both.loc[both["resolved"] == "true", "resolved_at"] != both.loc[both["resolved"] == "true", "resolved_at_ca"]).any():
        probs.append("violation and corrective action resolved_at differ")
    f = read(folder, "file")
    for ent, grp in f.groupby("entity"):
        ids = set(read(folder, ent, usecols=["id"])["id"])
        orphan = set(grp["entity_id"]) - ids
        if orphan:
            probs.append(f"file rows point at missing {ent}: {sorted(orphan)[:3]}")
    lab = read(folder, "scenario_label")
    for ent, grp in lab.groupby("entity"):
        if ent.endswith("_missing"):
            continue
        cols = ["id", "mine_id"] if "mine_id" in [c["name"] for c in schemas[ent]["columns"]] else ["id"]
        t = read(folder, ent, usecols=cols)
        miss = set(grp["entity_id"]) - set(t["id"])
        if miss:
            probs.append(f"scenario_label -> {ent}: {len(miss)} ids missing")
        if "mine_id" in cols:
            mm = grp.merge(t, left_on="entity_id", right_on="id", suffixes=("", "_t"))
            if (mm["mine_id"] != mm["mine_id_t"]).any():
                probs.append(f"scenario_label -> {ent}: mine_id mismatch")
    rep.add("V3 references: foreign keys resolve, no orphans", not probs, probs[:8])


def v4_dates(folder: Path, schemas: dict, end: pd.Timestamp, rep: Report) -> None:
    late = []
    for table, sc in schemas.items():
        cols = [c for c in sc["columns"] if c["type"] in ("date", "datetime") and (table, c["name"]) not in FORWARD_OK]
        if not cols:
            continue
        for chunk in pd.read_csv(folder / f"{table}.csv", dtype=str, keep_default_na=False,
                                 usecols=[c["name"] for c in cols], chunksize=BIG):
            for c in cols:
                s = chunk[c["name"]]
                s = s[s != ""]
                if len(s) and ts(s).max() > end:
                    late.append(f"{table}.{c['name']}")
    rep.add("V4 dates: nothing after the window end (deadlines and validity dates excepted)", not late, sorted(set(late)))


def v5_production(folder: Path, manifest: dict, config: dict, rep: Report) -> None:
    mines = load_roster(manifest["roster"])
    mines = mines[mines["id"].isin(read(folder, "mine")["id"].astype(int))]
    p = read(folder, "daily_production", usecols=["mine_id", "date", "coal_actual_t"])
    p["mine_id"] = p["mine_id"].astype(int)
    p["coal_actual_t"] = p["coal_actual_t"].astype(float)
    p["company_id"] = p["mine_id"].map(mines.set_index("id")["company_id"])
    d = pd.to_datetime(p["date"])
    p["y"], p["m"] = d.dt.year, d.dt.month
    split = config["generation"].get("production_split", "company_capacity_share")
    ccap = pd.read_csv(REF / "company_capacity.csv").set_index("company_id")
    get = company_months(None)
    results = []
    for (cid, y, m), grp in p.groupby(["company_id", "y", "m"]):
        cm = mines[mines["company_id"] == cid]
        complete = grp["date"].nunique() == calendar.monthrange(y, m)[1] and grp["mine_id"].nunique() == len(cm)
        if not complete:
            continue
        caps = cm["capacity_mtpa"].to_numpy(float)
        if split == "company_capacity_share" and cid in ccap.index and pd.notna(ccap.at[cid, "gem_capacity_mtpa"]):
            tot = float(ccap.at[cid, "gem_capacity_mtpa"])
            caps = np.where(cm["capacity_imputed"].to_numpy(bool), tot / float(ccap.at[cid, "gem_operating_mines"]), caps)
            cov = caps.sum() / max(tot, caps.sum())
        else:
            cov = 1.0
        expected = get(cid, y, m)[0] * 1e6
        got = grp["coal_actual_t"].sum() / cov
        ok = (abs(got - expected) / expected <= 0.03) if expected > 0 else abs(got) < 1
        results.append({"company": cid, "month": f"{y}-{m:02d}", "dev_pct": round((got - expected) / expected * 100, 3) if expected else 0.0, "ok": ok})
    bad = [r for r in results if not r["ok"]]
    worst = max((abs(r["dev_pct"]) for r in results), default=0)
    rep.add(f"V5 production: {len(results)} complete company-months within +-3 % (worst {worst} %)"
            + ("" if results else " - not applicable, no complete month"), not bad, bad[:5])


def v6_scenarios(folder: Path, end: pd.Timestamp, rep: Report) -> list[dict]:
    exp = json.loads((folder / "scenario_expectations.json").read_text(encoding="utf-8"))
    v = read(folder, "violation")
    v["dt"] = ts(v["detected_at"])
    out = []
    for e in exp:
        sig, mid, t = e["signal"], str(e["mine_id"]), e["signal"]["type"]
        ok, measured = False, {}
        if t == "repeat_category_then_incident":
            rep_ids = set(map(str, e["entities"]["violation"]))
            vv = v[v["id"].isin(rep_ids) & (v["mine_id"] == mid) & (v["category"] == sig["category"])]
            first_n = vv.sort_values("dt").head(sig["min_repeats"])
            span = (first_n["dt"].max() - first_n["dt"].min()).days
            a = read(folder, "alert")
            inc = a[a["id"].isin(map(str, e["entities"]["alert"])) & (a["code"] == sig["incident_alert_code"]) & (a["mine_id"] == mid)]
            inc_t = ts(inc["created_at"]).min() if len(inc) else None
            open_after = vv[(vv["resolved"] == "false") & (vv["dt"] > inc_t)] if inc_t is not None else vv.iloc[0:0]
            incs = read(folder, "incident")
            irow = incs[incs["id"].isin(map(str, e["entities"].get("incident", []))) & (incs["mine_id"] == mid)]
            inc_ok = len(irow) == 1 and irow["severity"].iloc[0] == "dangerous_occurrence" and                 irow["type"].iloc[0] == "ground_movement" and irow["related_violation_id"].iloc[0] in rep_ids
            ok = (len(first_n) >= sig["min_repeats"] and span <= sig["within_days"] and inc_t is not None
                  and inc_t > first_n["dt"].max() and (len(open_after) >= 1) == sig["open_after_incident"] and inc_ok)
            measured = {"repeats": len(first_n), "span_days": span, "incident": str(inc_t), "open_after": len(open_after),
                        "incident_row_linked": bool(inc_ok)}
        elif t == "production_spike_before_inspection":
            p = read(folder, "daily_production", usecols=["mine_id", "date", "coal_actual_t"])
            p = p[p["mine_id"] == mid].assign(c=lambda d: d["coal_actual_t"].astype(float)).groupby("date")["c"].sum()
            day = sig["day"]
            prior = p[(p.index < day)].tail(sig["trailing_days"])
            ratio = p.get(day, 0) / prior.mean() if len(prior) else float("inf")
            ins = read(folder, "inspection")
            has_ins = ((ins["mine_id"] == mid) & (ins["scheduled_for"] == sig["inspection_date"])).any()
            ok = ratio >= sig["min_ratio_to_trailing_mean"] and has_ins
            measured = {"ratio": round(float(ratio), 2), "trailing_days_used": len(prior), "inspection_next_day": bool(has_ins)}
        elif t == "flatline":
            lo, hi = pd.Timestamp(e["date_from"], tz="UTC"), pd.Timestamp(e["date_to"], tz="UTC") + pd.Timedelta(days=1)
            vals = []
            for ch in pd.read_csv(folder / "sensor_reading.csv", dtype=str, keep_default_na=False,
                                  usecols=["mine_id", "sensor_type", "value", "recorded_at"], chunksize=BIG):
                ch = ch[(ch["mine_id"] == mid) & (ch["sensor_type"] == sig["sensor_type"])]
                r = ts(ch["recorded_at"])
                vals.append(ch.loc[(r >= lo) & (r < hi), "value"].astype(float))
            vals = pd.concat(vals)
            # the 72 flat hours sit inside [date_from, date_to]; measure the longest constant run
            runs = (vals != vals.shift()).cumsum()
            longest = int(vals.groupby(runs).size().max()) if len(vals) else 0
            ok = longest >= sig["readings"]
            measured = {"longest_constant_run": longest, "needed": sig["readings"]}
        elif t == "contractor_missing_docs_and_violations":
            docs = read(folder, "contractor_compliance_doc")
            have = set(zip(docs["contract_id"], docs["period"], docs["doc_type"]))
            still = [m for m in sig["missing_docs"] if (str(m["contract_id"]), m["period"], m["doc_type"]) in have]
            con, w = read(folder, "contract"), read(folder, "contract_worker")
            act = w[w["active"] == "true"].merge(con[["id", "contractor_id"]], left_on="contract_id", right_on="id").groupby("contractor_id").size()
            cnt = v[v["contractor_id"] != ""].groupby("contractor_id").size()
            vpw = (cnt / act).dropna().sort_values(ascending=False)
            top = vpw.index[0] if len(vpw) else None
            ok = not still and len(sig["missing_docs"]) > 0 and top == str(sig["contractor_id"])
            measured = {"missing_docs": len(sig["missing_docs"]), "docs_present_that_should_be_missing": len(still),
                        "top_contractor": top, "vpw": round(float(vpw.iloc[0]), 3) if len(vpw) else None}
        elif t in ("night_share", "night_production_lower_not_compliance"):
            vv = v[(v["mine_id"] == mid) & (v["source"] == "vision")]
            h = (vv["dt"] + IST).dt.hour
            share = float(((h >= 22) | (h < 6)).mean()) if len(vv) else 0.0
            if t == "night_share":
                ok = share >= sig["min_share"]
            else:
                p = read(folder, "daily_production", usecols=["mine_id", "shift", "coal_actual_t"])
                p = p[p["mine_id"] == mid]
                c_share = float(p.loc[p["shift"] == "C", "coal_actual_t"].astype(float).sum() / p["coal_actual_t"].astype(float).sum())
                ok = share <= sig["max_night_violation_share"] and c_share < 0.25
                measured["c_output_share"] = round(c_share, 3)
            measured["night_violation_share"] = round(share, 3)
        elif t in ("sla_cluster", "burst_within_sla"):
            g, ga = read(folder, "grievance"), read(folder, "grievance_action")
            gg = g[g["id"].isin(map(str, e["entities"]["grievance"])) & (g["mine_id"] == mid)]
            res = ga[ga["action"] == "resolve"].groupby("grievance_id")["created_at"].min()
            due = ts(gg["sla_due_at"])
            done = ts(gg["id"].map(res))
            breached = ((done > due) | (done.isna() & (due < end))).sum()
            if t == "sla_cluster":
                ok = breached >= sig["min_breaches"]
            else:
                ok = breached <= sig["max_breaches"] and len(gg) == sig["count"]
            measured = {"grievances": len(gg), "breached": int(breached)}
        elif t == "late_closure":
            ca = read(folder, "corrective_action")
            mine_res = ca[(ca["mine_id"] == mid) & (ca["status"] == "resolved")]
            late = ts(mine_res["resolved_at"]) > ts(mine_res["due_at"])
            ids_late = set(mine_res.loc[late, "id"])
            ok = set(map(str, e["entities"]["corrective_action"])) <= ids_late and late.mean() >= sig["min_late_share_of_resolved"]
            measured = {"late": int(late.sum()), "resolved": len(mine_res), "share": round(float(late.mean()), 3)}
        elif t == "legit_increase":
            p = read(folder, "daily_production")
            rows = p[p["id"].isin(map(str, e["entities"]["daily_production"]))]
            ach = rows["coal_actual_t"].astype(float).sum() / rows["coal_target_t"].astype(float).sum()
            log = read(folder, "production_edit_log")
            has_log = log["id"].isin(map(str, e["entities"]["production_edit_log"])).any() and \
                log.loc[log["id"].isin(map(str, e["entities"]["production_edit_log"])), "reason"].str.len().gt(10).all()
            ins = read(folder, "inspection")
            d0 = pd.Timestamp(e["date_from"]) - pd.Timedelta(days=sig["no_inspection_within_days"])
            d1 = pd.Timestamp(e["date_to"]) + pd.Timedelta(days=sig["no_inspection_within_days"])
            near = ins[(ins["mine_id"] == mid) & pd.to_datetime(ins["scheduled_for"]).between(d0, d1)]
            ok = 0.8 <= ach <= 1.25 and has_log and near.empty and rows["remarks"].str.len().gt(0).all()
            measured = {"achievement_vs_revised_target": round(float(ach), 3), "edit_log_reason": bool(has_log),
                        "inspections_nearby": len(near)}
        out.append({"scenario": e["scenario_code"], "polarity": e["polarity"], "mine": e["mine_code"], "ok": bool(ok), **measured})
    bad = [o for o in out if not o["ok"]]
    pos = sum(o["polarity"] == "positive" for o in out)
    rep.add(f"V6 scenarios: {pos} positives show their signal, {len(out) - pos} decoys verified legitimate", not bad, bad)
    return out


def v7_legal(folder: Path, rep: Report) -> None:
    rules = load_rules()              # raises if a cited obligation is missing or unverified
    ob = pd.read_csv(REF / "obligations.csv", dtype=str).set_index("obligation_code")
    a = read(folder, "alert")
    cited = {json.loads(p).get("obligation") for p in a["params"]} - {None}
    cited |= set(read(folder, "incident", usecols=["obligation_code"])["obligation_code"])
    unverified = [c for c in cited if c not in ob.index or ob.at[c, "verified"] != "yes"]
    lim = rules["sensors"]
    wrong, parts = [], []
    for ch in pd.read_csv(folder / "sensor_reading.csv", dtype=str, keep_default_na=False,
                          usecols=["id", "mine_id", "sensor_type", "value", "recorded_at", "breached"], chunksize=BIG):
        parts.append(ch)
    s = pd.concat(parts, ignore_index=True)
    s["value"] = s["value"].astype(float)
    for st, grp in s.groupby("sensor_type"):
        r = lim[st]
        if not (r.get("obligation") and isinstance(r.get("limit"), (int, float))):
            if (grp["breached"] != "").any():
                wrong.append(f"{st}: breached set without a cited limit")
            continue
        if r["compare"] == "rolling_8h_mean":
            exp = []
            for _, g in grp.sort_values(["mine_id", "recorded_at"]).groupby("mine_id"):
                step = ts(g["recorded_at"].iloc[:2]).diff().iloc[-1].total_seconds() / 60
                w = int(8 * 60 / step)
                m = g["value"].rolling(w, min_periods=w).mean()
                exp.append(pd.Series(np.where(m.isna(), False, m > r["limit"]), index=g.index))
            expected = pd.concat(exp).reindex(grp.index)
        else:
            expected = grp["value"] > r["limit"]
        got = grp["breached"] == "true"
        if (got != expected).any():
            wrong.append(f"{st}: {int((got != expected).sum())} readings disagree with limit {r['limit']} ({r['obligation']})")
    rep.add("V7 legal: every threshold used cites a verified obligation; breaches agree with the cited limits",
            not unverified and not wrong, {"unverified_citations": unverified, "breach_mismatches": wrong})


def v8_scores(folder: Path, manifest: dict, config: dict, rep: Report) -> dict:
    base = config["repo_demo_baseline"]
    roster = load_roster(manifest["roster"]).set_index("code")
    m = read(folder, "mine")
    v = read(folder, "violation", usecols=["mine_id", "resolved"])
    open_n = v[v["resolved"] == "false"].groupby("mine_id").size()
    since = pd.Timestamp(manifest["window"][1], tz="UTC") + pd.Timedelta(hours=23, minutes=59, seconds=59) \
        - pd.Timedelta(hours=float(base["breach_window_hours"]))
    br_n = pd.Series(dtype=float)
    for ch in pd.read_csv(folder / "sensor_reading.csv", dtype=str, keep_default_na=False,
                          usecols=["mine_id", "recorded_at", "breached", "resolved"], chunksize=BIG):
        ch = ch[(ch["breached"] == "true") & (ch["resolved"] == "false")]
        ch = ch[ts(ch["recorded_at"]) >= since]
        br_n = br_n.add(ch.groupby("mine_id").size(), fill_value=0)
    br = int(br_n.sum())
    raw = 100 - (m["id"].map(open_n).fillna(0) * base["weight_ppe"] + m["id"].map(br_n).fillna(0) * base["weight_env"])
    m["score"] = raw.clip(0, 100).round(1)
    lb = base["risk_bands"]
    m["band"] = np.where(m["score"] >= lb["low_min"], "LOW", np.where(m["score"] >= lb["medium_min"], "MEDIUM", "HIGH"))
    exp = base["expected"]
    mism = [c for c, s in zip(m["code"], m["score"]) if float(roster.at[c, "demo_score"]) != s]
    rep.add("V8 demo scores: every mine's score from the CSVs equals its roster demo score", not mism and br == 0,
            {"mismatches": mism[:5], "in_window_breaches": br})
    named = {c: float(s) for c, s in zip(m["code"], m["score"]) if c in NAMED}
    rep.add("V8 demo scores: named mines 100/80/70/60/45",
            named == {k: float(v) for k, v in exp["named_scores"].items() if k in named}, named)
    res = {"named": named}
    if len(m) == exp["mine_count"]:
        bands = m["band"].value_counts().to_dict()
        avg = round(float(m["score"].mean()), 1)
        rep.add("V8 demo scores: bands 6 High / 21 Medium / 47 Low", bands == exp["band_counts"], bands)
        rep.add("V8 demo scores: average 83.2", avg == exp["average_score"], avg)
        res.update({"bands": bands, "average": avg})
    return res


def v10_stock(folder: Path, rep: Report) -> None:
    p = read(folder, "daily_production", usecols=["mine_id", "date", "shift", "coal_actual_t", "dispatch_t", "closing_stock_t"])
    for c in ["coal_actual_t", "dispatch_t", "closing_stock_t"]:
        p[c] = p[c].astype(float)
    p = p.sort_values(["mine_id", "date", "shift"])
    day = p.groupby(["mine_id", "date"]).agg(prod=("coal_actual_t", "sum"), disp=("dispatch_t", "sum"),
                                             close=("closing_stock_t", "last")).reset_index()
    day["open"] = day.groupby("mine_id")["close"].shift(1)
    chk = day.dropna(subset=["open"])
    gap = (chk["open"] + chk["prod"] - chk["disp"] - chk["close"]).abs()
    neg = int((p["closing_stock_t"] < 0).sum())
    rep.add(f"V10 stock: opening + production - dispatch = closing for {len(chk):,} mine-days (worst gap {gap.max():.2f} t)",
            bool((gap < 0.05).all()) and neg == 0, {"bad_mine_days": int((gap >= 0.05).sum()), "negative_stock_rows": neg})


def v11_incidents(folder: Path, rep: Report) -> None:
    i = read(folder, "incident")
    delay = ts(i["reported_at"]) - ts(i["occurred_at"])
    flag_ok = ((delay <= pd.Timedelta(hours=48)) == (i["reported_within_48h"] == "true")).all()
    order_ok = (delay >= pd.Timedelta(0)).all()
    want = i["severity"].map({"fatal": "RPT-03", "serious": "RPT-04", "minor": "RPT-04", "dangerous_occurrence": "RPT-05"})
    code_ok = (want == i["obligation_code"]).all()
    do_ok = (i.loc[i["severity"] == "dangerous_occurrence", "persons_affected"] == "0").all()
    rep.add(f"V11 incidents: {len(i)} incidents consistent (48 h flag, obligation by severity, dangerous occurrences without casualties)",
            bool(flag_ok and order_ok and code_ok and do_ok),
            {"flag_ok": bool(flag_ok), "reported_after_occurred": bool(order_ok), "obligation_ok": bool(code_ok),
             "do_no_casualties": bool(do_ok), "late_reports": int((i["reported_within_48h"] == "false").sum()),
             "by_severity": i["severity"].value_counts().to_dict()})


def v12_obligations(folder: Path, manifest: dict, rep: Report) -> None:
    """The obligation register: which obligations get tasks, applicability, due dates, statuses."""
    from gen_obligations import IST as OB_IST, LEGAL_ANNUAL, UNDERGROUND_ONLY, WORKFORCE_500, incident_deadline, periods
    rules = load_rules()
    esc_after = pd.Timedelta(hours=float(rules["product"]["obligation_schedule"]["escalate_after_hours"]))
    ob = read(folder, "obligation")
    t = read(folder, "obligation_task")
    s = read(folder, "obligation_submission")
    ap = read(folder, "obligation_applicability")
    mines = read(folder, "mine").set_index("id")
    roster = load_roster(manifest["roster"]).set_index("code")
    ref = pd.read_csv(REF / "obligations.csv", dtype=str)
    probs = []

    gen = ob[ob["generates_tasks"] == "true"]
    expected_gen = ref[(ref["verified"] == "yes") & (ref["applies_to"] == "mine")
                       & ~ref["frequency"].isin(["continuous", "on event", "every shift"]) & ~ref["frequency"].str.startswith("once")]
    if set(gen["code"]) != set(expected_gen["obligation_code"]):
        probs.append(f"task-generating set differs: {sorted(set(gen['code']) ^ set(expected_gen['obligation_code']))}")
    code_of = dict(zip(ob["id"], ob["code"]))
    # Incident reporting tasks (on event): one per incident, its obligation, due at the law's time
    # (incident_deadline), done at reported_at. Checked here, then set aside for the calendar checks.
    inc = read(folder, "incident")
    inc.index = inc["id"].astype(int)
    it = t[t["incident_id"] != ""].copy()
    t = t[t["incident_id"] == ""].copy()
    iid = it["incident_id"].astype(int)
    if sorted(iid) != sorted(inc.index) or iid.duplicated().any():
        probs.append(f"incident tasks: {len(it)} for {len(inc)} incidents (want exactly one each)")
    else:
        row = inc.loc[iid.values]
        occurred, reported = ts(row["occurred_at"]).values, ts(row["reported_at"]).values
        if (it["obligation_id"].map(code_of).values != row["obligation_code"].values).any():
            probs.append("an incident task for another obligation than the incident's")
        deadline = [incident_deadline(rules, c) for c in row["obligation_code"]]
        want_due = ts(row["occurred_at"]) + pd.to_timedelta([h for h, _ in deadline], unit="h")
        if (ts(it["due_at"]).values != want_due.values).any() or (it["due_basis"].values != [b for _, b in deadline]).any():
            probs.append("an incident task not due at its obligation's reporting deadline (law, or forthwith + grace)")
        if ((it["status"] != "accepted").values | (ts(it["accepted_at"].replace("", None)).values != reported)).any():
            probs.append("an incident task not accepted at the incident's reported_at")
        late_tasks = int((ts(it["accepted_at"]).values > ts(it["due_at"]).values).sum())
    if set(s["task_id"]) & set(it["id"]):
        probs.append("an incident task has an uploaded submission")
    s = s[~s["task_id"].isin(it["id"])]
    with_tasks = set(t["obligation_id"].map(code_of))
    if not with_tasks <= set(gen["code"]):
        probs.append(f"tasks for non-generating obligations: {sorted(with_tasks - set(gen['code']))}")
    if "RPT-08" in with_tasks or ob.loc[ob["code"] == "RPT-08", "verified"].eq("true").any():
        probs.append("RPT-08 (TODO-VERIFY) has tasks or is marked verified")

    ap["code"] = ap["obligation_id"].map(code_of)
    ap["type"] = ap["mine_id"].map(mines["type"])
    ap["workforce"] = ap["mine_id"].map(mines["code"]).map(roster["workforce"]).astype(float)
    if (ap["code"].isin(UNDERGROUND_ONLY) & ~ap["type"].isin(["underground", "mixed"])).any():
        probs.append("an underground-only obligation applies to an opencast mine")
    if (ap["code"].isin(WORKFORCE_500) & ~(ap["workforce"] >= 500)).any():
        probs.append("SAF-01 applies to a mine with fewer than 500 workers")
    pairs = set(zip(t["mine_id"], t["obligation_id"]))
    if pairs != set(zip(ap["mine_id"], ap["obligation_id"])):
        probs.append("tasks and applicability disagree")

    first = pd.Timestamp(manifest["window"][0]).date()
    last = pd.Timestamp(manifest["window"][1]).date()
    end = pd.Timestamp(manifest["window"][1], tz="UTC") + pd.Timedelta(hours=23, minutes=59, seconds=59)
    sched = dict(zip(ob["code"], ob["schedule"]))
    missing = 0
    for (mine_id, oid), grp in t.groupby(["mine_id", "obligation_id"]):
        want = {p[0]: p for p in periods(sched[code_of[oid]], code_of[oid], first, last)}
        if set(grp["period"]) != set(want):
            missing += 1
            continue
        for row in grp.itertuples():
            p = want[row.period]
            due = pd.Timestamp(pd.Timestamp(p[3]).to_pydatetime().replace(hour=23, minute=59, second=59) - OB_IST, tz="UTC")
            if ts(pd.Series([row.due_at])).iloc[0] != due or row.due_basis != p[4]:
                probs.append(f"due date of task {row.id} ({code_of[oid]} {row.period})")
                break
    if missing:
        probs.append(f"{missing} (mine, obligation) pairs without exactly the expected periods")
    if set(t.loc[t["due_basis"] == "law", "obligation_id"].map(code_of)) - set(LEGAL_ANNUAL):
        probs.append("due_basis law on an obligation whose rule names no date")

    s["submitted_at_ts"], s["reviewed_at_ts"] = ts(s["submitted_at"]), ts(s["reviewed_at"].replace("", None))
    last_sub = s.sort_values(["task_id", "submitted_at_ts", "id"]).groupby("task_id").last()
    t["due_ts"] = ts(t["due_at"])
    t["latest"] = t["id"].map(last_sub["status"])
    t["latest_reviewed"] = t["id"].map(last_sub["reviewed_at"])
    past = t["due_ts"] <= end
    expect = np.select(
        [t["latest"] == "accepted", t["latest"] == "pending", past & ((end - t["due_ts"]) > esc_after), past,
         t["latest"] == "rejected"],
        ["accepted", "submitted", "escalated", "overdue", "rejected"], default="open")
    bad_status = int((t["status"] != expect).sum())
    level = np.where(t["status"] == "escalated", "2", np.where(t["status"] == "overdue", "1", "0"))
    bad_level = int((t["escalation_level"] != level).sum())
    bad_accept = int(((t["status"] == "accepted") & (t["accepted_at"] != t["latest_reviewed"])).sum())
    if bad_status or bad_level or bad_accept:
        probs.append(f"statuses: {bad_status} wrong, levels: {bad_level} wrong, accepted_at: {bad_accept} wrong")
    ps = s["task_id"].map(dict(zip(t["id"], ts(t["period_start"].astype(str) + "T00:00:00Z") - pd.Timedelta(hours=5, minutes=30))))
    if (s["submitted_at_ts"] < ps).any() or (s["submitted_at_ts"] > end).any():
        probs.append("a submission outside its period start .. window end")
    reviewed = s["reviewed_at"] != ""
    if (s.loc[reviewed, "reviewed_at_ts"] < s.loc[reviewed, "submitted_at_ts"]).any():
        probs.append("a review before its submission")
    if (s.loc[s["status"] == "rejected", "review_note"] == "").any() or (s.loc[s["status"] == "pending", "reviewed_by"] != "").any():
        probs.append("a rejection without a reason, or a pending submission with a reviewer")
    counts = t["status"].value_counts().to_dict()
    rep.add(f"V12 obligations: {len(t):,} tasks from {len(gen)} verified calendar obligations, applicability, due dates, statuses; "
            f"{len(it)} incident reporting tasks (one per incident, due at the law's time, done at reported_at); RPT-08 never", not probs, {"problems": probs[:6], "by_status": counts,
                                         "incident_tasks_late": locals().get("late_tasks"),
                                         "rejected_submissions": int((s['status'] == 'rejected').sum())})


def v13_tracking_codes(folder: Path, rep: Report) -> None:
    from common import TRACKING_ALPHABET, tracking_code
    g = read(folder, "grievance", usecols=["ticket_no", "tracking_code"])
    seed = int(load_config()["seed"])
    fmt = g["tracking_code"].str.fullmatch(f"[{TRACKING_ALPHABET}]{{8}}")
    derived = g["tracking_code"] == [tracking_code(seed, x) for x in g["ticket_no"]]
    rep.add(f"V13 grievance tracking codes: {len(g)} codes, 8 characters of the unambiguous alphabet, derived from (seed, ticket)",
            bool(fmt.all() and derived.all() and g["tracking_code"].is_unique),
            {"bad_format": int((~fmt).sum()), "not_derived": int((~derived).sum()), "duplicates": int(g["tracking_code"].duplicated().sum())})


def hashes(folder: Path) -> dict:
    return {p.name: hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted(folder.iterdir()) if p.suffix in (".csv",) or p.name == "scenario_expectations.json"}


def generate(preset: str, roster: str | None, out_root: Path) -> None:
    cmd = [sys.executable, "-W", "ignore", str(Path(__file__).parent / "generate.py"), "--preset", preset, "--out", str(out_root)]
    if roster:
        cmd += ["--roster", roster]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if r.returncode != 0:
        raise SystemExit(f"ERROR: generate.py failed for {preset}/{roster}:\n{r.stdout[-2000:]}\n{r.stderr[-2000:]}")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--preset", default=None)
    ap.add_argument("--roster", default=None, choices=["real", "seed"])
    ap.add_argument("--out", default=None)
    ap.add_argument("--generate", action="store_true")
    ap.add_argument("--determinism", action="store_true")
    args = ap.parse_args()
    config = load_config()
    preset = args.preset or config.get("scale", "demo")
    out_root = Path(args.out) if args.out else DATA / "out"
    folder = out_root / preset
    t0 = time.time()
    if args.generate:
        print(f"[validate] generating {preset} ({args.roster or config['mine_roster']} roster) ...")
        generate(preset, args.roster, out_root)
    manifest = json.loads((folder / "_manifest.json").read_text(encoding="utf-8"))
    if args.roster and manifest["roster"] != args.roster:
        raise SystemExit(f"ERROR: {folder} holds the {manifest['roster']} roster, not {args.roster}; use --generate")
    end = pd.Timestamp(manifest["window"][1], tz="UTC") + pd.Timedelta(hours=23, minutes=59, seconds=59)
    print(f"[validate] {folder}  preset {preset}, {manifest['roster']} roster, {manifest['mines']} mines, "
          f"window {manifest['window'][0]}..{manifest['window'][1]}")
    rep = Report()
    schemas = load_schemas()
    pks = v1_v2_schema(folder, schemas, rep)
    v3_references(folder, schemas, pks, rep)
    v4_dates(folder, schemas, end, rep)
    v5_production(folder, manifest, config, rep)
    scen = v6_scenarios(folder, end, rep)
    v7_legal(folder, rep)
    scores = v8_scores(folder, manifest, config, rep)
    v10_stock(folder, rep)
    v11_incidents(folder, rep)
    v12_obligations(folder, manifest, rep)
    v13_tracking_codes(folder, rep)
    if args.determinism:
        with tempfile.TemporaryDirectory() as t1, tempfile.TemporaryDirectory() as t2:
            generate(preset, manifest["roster"], Path(t1))
            generate(preset, manifest["roster"], Path(t2))
            h0, h1, h2 = hashes(folder), hashes(Path(t1) / preset), hashes(Path(t2) / preset)
            diff = sorted({k for k in h0 if not (h0.get(k) == h1.get(k) == h2.get(k))})
            rep.add(f"V9 determinism: {len(h0)} files byte-identical across three runs", not diff, diff)
    result = {"preset": preset, "roster": manifest["roster"], "seconds": round(time.time() - t0, 1),
              "checks": rep.rows, "scenarios": scen, "scores": scores}
    (folder / "_validation.json").write_text(json.dumps(result, indent=1, default=str, ensure_ascii=False) + "\n", encoding="utf-8")
    failed = [r for r in rep.rows if not r["pass"]]
    print(f"[validate] {len(rep.rows) - len(failed)}/{len(rep.rows)} checks passed in {result['seconds']} s")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
