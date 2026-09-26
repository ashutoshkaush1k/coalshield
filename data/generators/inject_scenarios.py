"""Stage D5: inject labelled scenarios (positives) and decoys (negatives) into the generated tables.

Runs inside generate.py after the D4 generators and before gen_alerts (so injected events get
their alerts), then `finalize` writes the ground truth once alert ids exist:
  scenario_label        one row per affected entity (schema/scenario_label.yaml)
  scenario_expectations what a detector should find: mine, entities, dates, and a measurable signal
                        (validate.py re-measures every signal on the written CSVs)

Demo scores must not move (owner rule): the backend score counts unresolved violations, so
scenarios work through historical rows - resolved violations, observations, production, sensor
history, contractor documents, grievances. S1 needs one open violation after the incident; it is
balanced by resolving one existing open violation at the same mine, so the open count is unchanged.

Placement (owner rule): S1 and S7 on the HIGH demo mine OD-TLC-05; S2 on a MEDIUM demo mine; S3 on an
underground or mixed mine; S4 at the mine with the most contract workers; the rest and the decoys on
non-demo mines. Dates are relative to the window end, so every preset gets every scenario.
Production changes keep company-month totals exact (the difference is taken from the company's
other mines that month, or the same mine's other days if it is alone), so the +-3 % check holds.
"""

from __future__ import annotations

import numpy as np
import pandas as pd
from faker import Faker

from common import NAMED, Ctx, categories
from gen_contractors import REGIONAL_SURNAMES
from gen_inspections import CA_TEXT
from grievance_templates import RESOLUTION, TEMPLATES

IST = pd.Timedelta(hours=5, minutes=30)
UTC = "UTC"


def _next_id(df: pd.DataFrame) -> int:
    return int(df["id"].max()) + 1 if len(df) else 1


def _ts(d, hour_ist: float) -> pd.Timestamp:
    return pd.Timestamp(d, tz=UTC) + pd.Timedelta(hours=hour_ist) - IST


class Injector:
    def __init__(self, ctx: Ctx):
        self.ctx = ctx
        self.rng = ctx.rng("scenarios")
        self.fake = Faker("en_IN")
        self.fake.seed_instance(ctx.seed + 5)
        self.as_of = ctx.as_of
        self.E = pd.Timestamp(ctx.end)
        self.S = pd.Timestamp(ctx.start)
        self.mines = ctx.mines.set_index("code")
        self.by_id = ctx.mines.set_index("id")
        self.used: dict[str, int] = {}
        self.labels: list[dict] = []
        self.expect: list[dict] = []
        self.cats = {c["key"]: c["example_types"] for c in categories()["categories"]}
        self.head_of = ctx.notes["head_of"]
        ctx.notes.setdefault("extra_alerts", [])

    # ---------------------------------------------------------------------------------- helpers
    def T(self, name: str) -> pd.DataFrame:
        return self.ctx.tables[name]

    def put(self, name: str, df: pd.DataFrame) -> None:
        self.ctx.tables[name] = df.reset_index(drop=True)

    def day(self, back: int) -> pd.Timestamp:
        """The day `back` days before the window end, never before the window start."""
        return max(self.S, self.E - pd.Timedelta(days=back))

    def pick(self, key: str, pred=lambda m: True, prefer_non_demo=True, order=None) -> int:
        pool = self.ctx.mines.copy()
        if order is not None:
            pool = pool.assign(_o=pool["id"].map(order)).sort_values(["_o", "id"], ascending=[False, True])
        tiers = []
        if prefer_non_demo:
            tiers.append(pool[~pool["code"].isin(NAMED) & ~pool["id"].isin(self.used.values())])
        tiers.append(pool[~pool["id"].isin(self.used.values())])
        tiers.append(pool)
        for t in tiers:
            t = t[t.apply(pred, axis=1)] if len(t) else t
            if len(t):
                mid = int(t["id"].iloc[0])
                self.used[key] = mid
                return mid
        raise RuntimeError(f"no mine for scenario {key}")

    def label(self, code, pol, mine_id, entity, ids, d_from, d_to, notes):
        for i in ids:
            self.labels.append({"scenario_code": code, "polarity": pol, "mine_id": int(mine_id), "entity": entity,
                                "entity_id": int(i), "date_from": pd.Timestamp(d_from).date(),
                                "date_to": pd.Timestamp(d_to).date(), "notes": notes})

    def add_violation(self, mine_id, cat, vtype, source, detected, resolved_at, inspection_id=None,
                      observation_id=None, contractor_id=None, confidence=None, frame_ref=None,
                      ca_due_days=7) -> int:
        v, ca = self.T("violation"), self.T("corrective_action")
        vid = _next_id(v)
        row = {"id": vid, "mine_id": mine_id, "violation_type": vtype, "category": cat, "confidence": confidence,
               "source": source, "frame_ref": frame_ref, "inspection_id": inspection_id,
               "observation_id": observation_id, "contractor_id": contractor_id, "detected_at": detected,
               "resolved": resolved_at is not None, "resolved_at": resolved_at}
        self.put("violation", pd.concat([v, pd.DataFrame([row])], ignore_index=True))
        created = min(detected + pd.Timedelta(hours=6), self.as_of)
        if resolved_at is not None and resolved_at < created:
            created = detected + (resolved_at - detected) / 2
        car = {"id": _next_id(ca), "mine_id": mine_id, "violation_id": vid, "alert_id": None, "contractor_id": contractor_id,
               "description": CA_TEXT[cat], "status": "resolved" if resolved_at is not None else "open",
               "due_at": created + pd.Timedelta(days=ca_due_days), "created_by": self.head_of[mine_id],
               "proof_image_path": f"proof/ca_{_next_id(ca):06d}.jpg" if resolved_at is not None else None,
               "created_at": created, "resolved_at": resolved_at}
        self.put("corrective_action", pd.concat([ca, pd.DataFrame([car])], ignore_index=True))
        return vid

    def add_inspection(self, mine_id, day, itype, findings) -> int:
        ins = self.T("inspection")
        iid = _next_id(ins)
        visit = _ts(day, 11.0)
        close = visit + pd.Timedelta(days=2)
        status = "closed" if close <= self.as_of else ("visited" if visit <= self.as_of else "scheduled")
        row = {"id": iid, "mine_id": mine_id, "inspector_id": int(self.rng.choice(self.ctx.notes["inspector_ids"])),
               "inspection_type": itype, "status": status, "scheduled_for": pd.Timestamp(day).date(),
               "visited_at": visit if status != "scheduled" else None, "closed_at": close if status == "closed" else None,
               "findings_count": findings, "is_locked": status == "closed"}
        self.put("inspection", pd.concat([ins, pd.DataFrame([row])], ignore_index=True))
        return iid

    def add_observation(self, mine_id, inspection_id, cat, severity, status, observed, contractor_id=None) -> int:
        obs = self.T("observation")
        oid = _next_id(obs)
        row = {"id": oid, "inspection_id": inspection_id, "grievance_id": None, "mine_id": mine_id, "category": cat,
               "severity": severity, "status": status, "violation_id": None, "contractor_id": contractor_id,
               "observed_at": observed}
        self.put("observation", pd.concat([obs, pd.DataFrame([row])], ignore_index=True))
        return oid

    def link_obs(self, oid, vid):
        obs = self.T("observation")
        obs.loc[obs["id"] == oid, "violation_id"] = vid

    def shift_production(self, rows: pd.Index, factor: float, target_too: bool = False) -> None:
        """Scale coal on the given rows; take the difference from the company's other mines in the
        same month (or this mine's other days) so company-month totals stay exact."""
        p = self.T("daily_production")
        comp = p["mine_id"].map(self.by_id["company_id"])
        month = pd.to_datetime(p["date"]).dt.to_period("M")
        for (cid, mon), grp in p.loc[rows].groupby([comp.loc[rows], month.loc[rows]]):
            delta = float((grp["coal_actual_t"] * (factor - 1)).sum())
            p.loc[grp.index, "coal_actual_t"] = (grp["coal_actual_t"] * factor).round(1)
            p.loc[grp.index, "ob_actual_m3"] = (grp["ob_actual_m3"] * factor).round(1)
            if target_too:
                p.loc[grp.index, "coal_target_t"] = (grp["coal_target_t"] * factor).round(1)
                p.loc[grp.index, "ob_target_m3"] = (grp["ob_target_m3"] * factor).round(1)
            mine = int(grp["mine_id"].iloc[0])
            others = p.index[(comp == cid) & (month == mon) & (p["mine_id"] != mine)]
            if not len(others):
                others = p.index[(p["mine_id"] == mine) & (month == mon) & ~p.index.isin(grp.index)]
            base = p.loc[others, "coal_actual_t"].sum()
            if base > 0:
                p.loc[others, "coal_actual_t"] = (p.loc[others, "coal_actual_t"] * (1 - delta / base)).round(1)

    # ---------------------------------------------------------------------------------- scenarios
    def s1_strata_incident(self):
        mid = int(self.mines.loc["OD-TLC-05", "id"]) if "OD-TLC-05" in self.mines.index else self.pick("S1", prefer_non_demo=False)
        self.used["S1"] = mid
        m = self.by_id.loc[mid]
        n = 5 if self.ctx.days >= 30 else 4
        L = min(50, self.ctx.days - 2)
        first = self.day(L)
        span = max(1, L - 12)
        days = [first + pd.Timedelta(days=round(k * span / (n - 1))) for k in range(n)]
        vtype = "highwall_bench_instability" if m["type"] == "opencast" else "inadequate_roof_support"
        vids, iids = [], []
        for k, d in enumerate(days):
            iid = self.add_inspection(mid, d, "spot" if k else "regular", 1)
            obs_at = _ts(d, 12.0)
            oid = self.add_observation(mid, iid, "roof_strata", "high" if k >= n - 2 else "medium", "promoted", obs_at)
            res = min(obs_at + pd.Timedelta(days=float(self.rng.uniform(3, 7))), self.as_of - pd.Timedelta(hours=1))
            vid = self.add_violation(mid, "roof_strata", vtype, "inspection", obs_at, res, iid, oid)
            self.link_obs(oid, vid)
            vids.append(vid)
            iids.append(iid)
        incident = min(days[-1] + pd.Timedelta(days=5), self.E - pd.Timedelta(days=1))
        kind = "fall_of_sides" if m["type"] == "opencast" else "fall_of_roof"
        self.ctx.notes["extra_alerts"].append({
            "key": "S1_incident", "code": "DANGEROUS_OCCURRENCE_REPORTED", "severity": "high", "mine_id": mid,
            "entity_type": "mine", "entity_id": mid, "created_at": _ts(incident, 14.3), "resolved": True,
            "params": {"kind": kind, "dgms_cause": "Fall of Sides" if kind == "fall_of_sides" else "Fall of Roof",
                       "persons_seriously_injured": 1, "obligation": "RPT-05"}})
        # post-incident inspection finds strata still unsafe: one OPEN strata violation, balanced by
        # resolving one existing open violation at this mine (open count unchanged)
        v = self.T("violation")
        open_idx = v.index[(v["mine_id"] == mid) & ~v["resolved"].astype(bool)]
        post_vid = post_iid = None
        if len(open_idx):
            post_day = incident + pd.Timedelta(days=1)
            post_iid = self.add_inspection(mid, post_day, "spot", 1)
            ins = self.T("inspection")
            ins.loc[ins["id"] == post_iid, ["status", "closed_at", "is_locked"]] = ["visited", None, False]
            obs_at = _ts(post_day, 12.0)
            oid = self.add_observation(mid, post_iid, "roof_strata", "high", "promoted", obs_at)
            post_vid = self.add_violation(mid, "roof_strata", vtype, "inspection", obs_at, None, post_iid, oid)
            self.link_obs(oid, post_vid)
            v = self.T("violation")
            cand = v.loc[open_idx]
            cand = cand.sort_values(["source", "detected_at"], key=lambda s: s.eq("vision") if s.name == "source" else s,
                                    ascending=[False, True])
            ri = cand.index[0]
            res = max(v.at[ri, "detected_at"] + pd.Timedelta(hours=2), min(obs_at, self.as_of))
            v.loc[ri, ["resolved", "resolved_at"]] = [True, res]
            ca = self.T("corrective_action")
            ci = ca.index[ca["violation_id"] == v.at[ri, "id"]]
            ca.loc[ci, ["status", "resolved_at"]] = ["resolved", res]
            self.ctx.notes["s1_rebalanced_violation"] = int(v.at[ri, "id"])
        d_to = incident + pd.Timedelta(days=1) if post_vid else incident
        self.label("S1_STRATA_REPEAT_INCIDENT", "positive", mid, "violation", vids, days[0], days[-1],
                   f"repeat roof/strata violation ({n} within {span} days)")
        self.label("S1_STRATA_REPEAT_INCIDENT", "positive", mid, "inspection", iids, days[0], days[-1],
                   "inspection that recorded the strata violation")
        if post_vid:
            self.label("S1_STRATA_REPEAT_INCIDENT", "positive", mid, "violation", [post_vid], d_to, d_to,
                       "strata violation still open after the incident")
        self.s1 = {"mine_id": mid, "violations": vids, "inspections": iids, "incident": incident, "post": post_vid,
                   "first": days[0], "last": days[-1], "d_to": d_to, "n": n, "span": span, "kind": kind}

    def s7_late_ca(self):
        mid = self.used["S1"]
        ca, v = self.T("corrective_action"), self.T("violation")
        idx = ca.index[(ca["mine_id"] == mid) & (ca["status"] == "resolved")]
        late_ids = []
        for i in idx:
            lateness = pd.Timedelta(days=float(self.rng.uniform(4, 18)))
            created, res = ca.at[i, "created_at"], ca.at[i, "resolved_at"]
            if res - created < lateness + pd.Timedelta(days=1):
                res = min(created + lateness + pd.Timedelta(days=2), self.as_of - pd.Timedelta(minutes=5))
                if res - created < pd.Timedelta(days=2):
                    continue
                ca.at[i, "resolved_at"] = res
                vi = v.index[v["id"] == ca.at[i, "violation_id"]]
                v.loc[vi, "resolved_at"] = res
            due = max(created + pd.Timedelta(days=1), res - lateness)
            ca.at[i, "due_at"] = due
            late_ids.append(int(ca.at[i, "id"]))
        late = ca[ca["id"].isin(late_ids)]
        d_from, d_to = late["created_at"].min(), late["resolved_at"].max()
        self.label("S7_LATE_CORRECTIVE_ACTIONS", "positive", mid, "corrective_action", late_ids, d_from, d_to,
                   "corrective action closed after its due date (mine has no published area; mine-level pattern)")
        self.s7 = {"mine_id": mid, "ids": late_ids, "from": d_from, "to": d_to}

    def s2_spike(self):
        med = [c for c in NAMED if c in self.mines.index and self.mines.loc[c, "demo_risk_level"] == "MEDIUM"]
        mid = int(self.mines.loc[med[0], "id"]) if med else self.pick("S2", prefer_non_demo=False)
        self.used["S2"] = mid
        t_i = self.day(20 if self.ctx.days >= 30 else 4)
        iid = self.add_inspection(mid, t_i, "regular", 0)
        spike_day = (t_i - pd.Timedelta(days=1)).date()
        p = self.T("daily_production")
        rows = p.index[(p["mine_id"] == mid) & (p["date"] == spike_day)]
        daily = p[p["mine_id"] == mid].groupby("date")["coal_actual_t"].sum()
        trailing = daily[daily.index < spike_day].tail(30).mean()
        factor = 2.0 * trailing / daily[spike_day] if len(daily[daily.index < spike_day]) else 1.9
        self.shift_production(rows, factor)
        self.label("S2_PRODUCTION_SPIKE_BEFORE_INSPECTION", "positive", mid, "daily_production", p.loc[rows, "id"],
                   spike_day, spike_day, "coal about 2x the trailing 30-day mean the day before a scheduled inspection, manpower unchanged")
        self.label("S2_PRODUCTION_SPIKE_BEFORE_INSPECTION", "positive", mid, "inspection", [iid], t_i, t_i,
                   "the inspection that followed the spike")
        self.s2 = {"mine_id": mid, "rows": p.loc[rows, "id"].tolist(), "day": spike_day, "inspection": iid, "t_i": t_i}

    def s3_flatline(self):
        mid = self.pick("S3", lambda m: m["type"] in ("underground", "mixed"))
        s = self.T("sensor_reading")
        t0 = pd.Timestamp(self.day(30 if self.ctx.days >= 30 else 6), tz=UTC)
        t1 = t0 + pd.Timedelta(hours=72)
        idx = s.index[(s["mine_id"] == mid) & (s["sensor_type"] == "ch4") & (s["recorded_at"] >= t0) & (s["recorded_at"] < t1)]
        val = round(float(s.loc[(s["mine_id"] == mid) & (s["sensor_type"] == "ch4"), "value"].median()), 2)
        s.loc[idx, "value"] = val
        s.loc[idx, "breached"] = False
        self.label("S3_GAS_SENSOR_FLATLINE", "positive", mid, "sensor_reading", s.loc[idx, "id"], t0, t1 - pd.Timedelta(minutes=1),
                   f"ch4 constant at {val} % for 72 h (possible tampering)")
        self.s3 = {"mine_id": mid, "ids": s.loc[idx, "id"].tolist(), "from": t0, "to": t1, "value": val}

    def s4_contractor(self):
        con, w = self.T("contract"), self.T("contract_worker")
        active = w[w["active"]].groupby("contract_id").size()
        per_mine = con.assign(n=con["id"].map(active).fillna(0)).groupby("mine_id")["n"].sum()
        mid = self.pick("S4", order=per_mine.to_dict())
        # the contractor working at this mine with the smallest total workforce, so "most violations
        # per worker" is reachable with a plausible number of violations
        crew = w[w["active"]].merge(con[["id", "contractor_id"]], left_on="contract_id", right_on="id").groupby("contractor_id").size()
        here = con.loc[con["mine_id"] == mid, "contractor_id"].unique()
        docs0 = self.T("contractor_compliance_doc")
        with_docs = set(con.loc[con["id"].isin(docs0.loc[docs0["doc_type"].isin(["wage_register", "epf_challan"]), "contract_id"]),
                                "contractor_id"])
        cid = int(sorted(here, key=lambda c: (c not in with_docs, crew.get(c, 0) == 0, crew.get(c, 0), c))[0])
        contracts = con.loc[con["contractor_id"] == cid, "id"].tolist()
        docs, files = self.T("contractor_compliance_doc"), self.T("file")
        drop = docs[docs["contract_id"].isin(contracts) & docs["doc_type"].isin(["wage_register", "epf_challan"])]
        missing = sorted({(int(r.contract_id), r.period, r.doc_type) for r in drop.itertuples()})
        self.put("contractor_compliance_doc", docs.drop(drop.index))
        self.put("file", files[~files["id"].isin(drop["file_id"])])
        # violations: this mine's inspection violations are on this contractor's work, plus a few more
        v, obs, ca = self.T("violation"), self.T("observation"), self.T("corrective_action")
        mine_v = v.index[(v["mine_id"] == mid) & (v["source"] == "inspection")]
        v.loc[mine_v, "contractor_id"] = cid
        obs.loc[obs["violation_id"].isin(v.loc[mine_v, "id"]), "contractor_id"] = cid
        ca.loc[ca["violation_id"].isin(v.loc[mine_v, "id"]), "contractor_id"] = cid

        def vpw():
            vv = self.T("violation")
            cnt = vv.dropna(subset=["contractor_id"]).groupby("contractor_id").size()
            act = w[w["active"]].merge(con[["id", "contractor_id"]], left_on="contract_id", right_on="id").groupby("contractor_id").size()
            return (cnt / act).dropna().sort_values(ascending=False)
        closed = self.T("inspection")
        closed = closed[(closed["mine_id"] == mid) & (closed["status"] == "closed")]
        r = vpw()
        other = r.drop(cid, errors="ignore")
        have = int(self.T("violation")["contractor_id"].eq(cid).sum())
        need = int(np.ceil(1.5 * (other.iloc[0] if len(other) else 0) * crew.get(cid, 1))) + 1 - have
        guard = 0
        while guard < max(0, need):
            iid = int(closed["id"].iloc[guard % len(closed)]) if len(closed) else self.add_inspection(mid, self.day(10), "regular", 0)
            ins = self.T("inspection")
            vis = ins.loc[ins["id"] == iid, "visited_at"].iloc[0]
            cat = ["transport_haulage", "ppe", "machinery", "roof_strata"][guard % 4]
            oid = self.add_observation(mid, iid, cat, "medium", "promoted", vis + pd.Timedelta(minutes=30 + guard), cid)
            vid = self.add_violation(mid, cat, self.cats[cat][0], "inspection", vis + pd.Timedelta(minutes=30 + guard),
                                     min(vis + pd.Timedelta(days=6), self.as_of - pd.Timedelta(hours=1)), iid, oid, cid)
            self.link_obs(oid, vid)
            ins.loc[ins["id"] == iid, "findings_count"] += 1
            guard += 1
        r = vpw()
        vids = self.T("violation").loc[self.T("violation")["contractor_id"] == cid, "id"].tolist()
        d_from, d_to = self.S, self.E
        self.label("S4_CONTRACTOR_MISSING_WAGE_EPF", "positive", mid, "contractor", [cid], d_from, d_to,
                   "contractor with repeated missing wage/EPF proof and the most violations per worker")
        for (k, per, dt) in missing:
            self.label("S4_CONTRACTOR_MISSING_WAGE_EPF", "positive", mid, "contractor_compliance_doc_missing", [k], d_from, d_to,
                       f"{dt} for {per} not uploaded")
        vv = self.T("violation")
        for vm, grp in vv[vv["id"].isin(vids)].groupby("mine_id"):     # the contractor also works at other mines
            self.label("S4_CONTRACTOR_MISSING_WAGE_EPF", "positive", vm, "violation", grp["id"], d_from, d_to,
                       "violation linked to the contractor")
        other = r.drop(cid, errors="ignore")
        self.s4 = {"mine_id": mid, "contractor_id": cid, "contracts": contracts, "missing": missing, "violations": vids,
                   "vpw": round(float(r[cid]), 4), "next_vpw": round(float(other.iloc[0]), 4) if len(other) else 0.0}

    def s5_night(self):
        v = self.T("violation")
        vis = v[(v["source"] == "vision") & v["resolved"].astype(bool)].groupby("mine_id").size()
        mid = self.pick("S5", order=vis.to_dict())
        ca = self.T("corrective_action")
        idx = v.index[(v["mine_id"] == mid) & (v["source"] == "vision") & v["resolved"].astype(bool)]
        for i in idx:
            if self.rng.random() > 0.85:
                continue
            old, res = v.at[i, "detected_at"], v.at[i, "resolved_at"]
            d = (old + IST).normalize()
            new = d + pd.Timedelta(hours=float(self.rng.uniform(22.2, 29.5))) - IST     # 22:12-05:30 IST
            if new > self.as_of - pd.Timedelta(hours=2):
                continue
            new_res = min(max(res + (new - old), new + pd.Timedelta(hours=1)), self.as_of - pd.Timedelta(minutes=1))
            v.loc[i, ["detected_at", "resolved_at"]] = [new, new_res]
            ci = ca.index[ca["violation_id"] == v.at[i, "id"]]
            ca.loc[ci, "created_at"] = min(new + pd.Timedelta(hours=2), new_res)
            ca.loc[ci, "resolved_at"] = new_res
            ca.loc[ci, "due_at"] = ca.loc[ci, "created_at"] + pd.Timedelta(days=10)
        k = 8 if self.ctx.days >= 30 else 4
        code = self.by_id.loc[mid, "code"].lower()
        for j in range(k):
            d = self.S + pd.Timedelta(days=int(self.rng.integers(0, max(1, self.ctx.days - 3))))
            det = _ts(d, float(self.rng.uniform(22.3, 29.0)))
            self.add_violation(mid, "ppe", ["no_helmet", "no_safety_vest", "no_safety_boots"][j % 3], "vision", det,
                               min(det + pd.Timedelta(hours=float(self.rng.uniform(6, 60))), self.as_of - pd.Timedelta(minutes=1)),
                               confidence=round(float(self.rng.uniform(0.62, 0.93)), 2),
                               frame_ref=f"annotated/{code}_night_{j:03d}.jpg")
        v = self.T("violation")
        mv = v[(v["mine_id"] == mid) & (v["source"] == "vision")]
        hour = (mv["detected_at"] + IST).dt.hour
        night = mv[(hour >= 22) | (hour < 6)]
        self.label("S5_NIGHT_SHIFT_COMPLIANCE", "positive", mid, "violation", night["id"], self.S, self.E,
                   "PPE violation detected in night shift C (22:00-06:00 IST)")
        self.s5 = {"mine_id": mid, "ids": night["id"].tolist(), "share": round(len(night) / max(1, len(mv)), 3)}

    def _grievances(self, mid, n, d_from, days_span, cats, breach: bool, code, notes):
        g, ga = self.T("grievance"), self.T("grievance_action")
        m = self.by_id.loc[mid]
        sla = self.ctx.rules["product"]["grievance_sla_hours"]
        keys = {c: [k for k, t in TEMPLATES.items() if t["category"] == c and not t.get("community")] for c in cats}
        new_g, new_a, ids = [], [], []
        gid, aid = _next_id(g), _next_id(ga)
        year_max = {}
        for t in g["ticket_no"]:
            y, num = t.split("-")[1], int(t.split("-")[2])
            year_max[y] = max(year_max.get(y, 0), num)
        head = self.head_of[mid]
        surn = REGIONAL_SURNAMES.get(m["state"], REGIONAL_SURNAMES["Jharkhand"])
        for j in range(n):
            cat = cats[j % len(cats)]
            key = keys[cat][j % len(keys[cat])]
            created = pd.Timestamp(d_from, tz=UTC) + pd.Timedelta(hours=float(self.rng.uniform(0, days_span * 24)))
            due = created + pd.Timedelta(hours=sla[cat])
            text = TEMPLATES[key][m["language"]].format(n=int(self.rng.integers(2, 9)), shift="ABC"[j % 3])
            tl = [("submit", None, "received", None, created)]
            status = "received"
            esc, note, rating = 0, None, None
            if breach:
                ack = created + pd.Timedelta(hours=float(self.rng.uniform(40, 70)))
                tl.append(("acknowledge", "received", "acknowledged", None, ack)); status = "acknowledged"
                inv = ack + pd.Timedelta(hours=float(self.rng.uniform(20, 40)))
                if inv <= self.as_of:
                    tl.append(("investigate", "acknowledged", "under_investigation", None, inv)); status = "under_investigation"
                esc = 2 if self.as_of - due > pd.Timedelta(hours=sla[cat]) else 1
                tl.append(("escalate", status, status, f"SLA breached; escalation level {esc}", due))
                res = due + pd.Timedelta(days=float(self.rng.uniform(2, 5)))
                if j % 2 == 0 and res <= self.as_of:
                    tl.append(("resolve", status, "resolved", None, res)); status = "resolved"; note = RESOLUTION[cat]
            else:
                ack = created + pd.Timedelta(hours=float(self.rng.uniform(2, 8)))
                res = created + pd.Timedelta(hours=sla[cat] * float(self.rng.uniform(0.3, 0.6)))
                close = res + pd.Timedelta(days=1)
                tl += [("acknowledge", "received", "acknowledged", None, ack),
                       ("investigate", "acknowledged", "under_investigation", None, ack + pd.Timedelta(hours=6)),
                       ("resolve", "under_investigation", "resolved", None, res)]
                status, note = "resolved", RESOLUTION[cat]
                if close <= self.as_of:
                    tl.append(("close", "resolved", "closed", None, close)); status, rating = "closed", int(self.rng.integers(3, 6))
            y = str(created.year)
            year_max[y] = year_max.get(y, 0) + 1
            anon = j % 4 == 3
            new_g.append({"id": gid, "ticket_no": f"GRV-{y}-{year_max[y]:06d}", "mine_id": mid,
                          "submitter_type": "anonymous" if anon else ("contract_worker" if j % 2 else "employee"),
                          "name": None if anon else f"{self.fake.first_name()} {surn[j % len(surn)]}",
                          "contact": None if anon else f"XXXXXX{int(self.rng.integers(0, 10000)):04d}", "is_anonymous": anon,
                          "category": cat, "severity": "medium", "language": m["language"], "description": text,
                          "file_id": None, "location": None, "status": status, "assigned_to": head, "sla_due_at": due,
                          "escalation_level": esc, "against_mine_head": False, "resolution_note": note,
                          "satisfaction_rating": rating, "created_at": created})
            for a, fr, to, nt, at in sorted(tl, key=lambda x: x[4]):
                new_a.append({"id": aid, "grievance_id": gid, "action": a, "from_status": fr, "to_status": to, "note": nt,
                              "actor_id": None if a == "submit" else head, "created_at": at})
                aid += 1
            ids.append(gid)
            self.ctx.notes["grievances"] = pd.concat([self.ctx.notes["grievances"], pd.DataFrame([{
                "id": gid, "mine_id": mid, "category": cat, "created_at": created, "_breached": breach and due <= self.as_of,
                "sla_due_at": due, "status": status, "_template": key, "_resolved_at": None, "escalation_level": esc,
                "assigned_to": head}])], ignore_index=True)
            gid += 1
        self.put("grievance", pd.concat([g, pd.DataFrame(new_g)], ignore_index=True))
        self.put("grievance_action", pd.concat([ga, pd.DataFrame(new_a)], ignore_index=True))
        d_to = pd.Timestamp(d_from) + pd.Timedelta(days=days_span)
        self.label(code, "positive" if breach else "negative", mid, "grievance", ids, d_from, d_to, notes)
        return ids, d_to

    def s6_sla_cluster(self):
        mid = self.pick("S6")
        d_from = self.day(25 if self.ctx.days >= 30 else 12)
        ids, d_to = self._grievances(mid, 7 if self.ctx.days >= 30 else 5, d_from, 10 if self.ctx.days >= 30 else 3,
                                     ["wages", "working_conditions"], True, "S6_GRIEVANCE_SLA_CLUSTER",
                                     "grievance past its SLA, part of a cluster at one mine")
        self.s6 = {"mine_id": mid, "ids": ids, "from": d_from, "to": d_to}

    def n1_legit_increase(self):
        comp_n = self.ctx.mines.groupby("company_id").size()
        ins = self.T("inspection")
        length = min(12, self.ctx.days - 3)
        d0 = self.day(40 if self.ctx.days >= 50 else length + 2)

        pp = self.T("daily_production")
        span_dates = {(d0 + pd.Timedelta(days=k)).date() for k in range(length)}
        prod = pp[pp["date"].isin(span_dates)].groupby("mine_id")["coal_actual_t"].sum()   # output inside the decoy's dates

        def quiet(m):   # producing (a +25 % step on ~zero output means nothing), no inspection nearby
            if prod.get(m["id"], 0) <= 100 * length:
                return False
            sched = pd.to_datetime(ins.loc[ins["mine_id"] == m["id"], "scheduled_for"])
            return not ((sched >= d0 - pd.Timedelta(days=5)) & (sched <= d0 + pd.Timedelta(days=length + 5))).any()

        def ok(m):   # opencast, no inspection nearby, company with other roster mines to rebalance across
            return m["type"] == "opencast" and comp_n.get(m["company_id"], 0) >= 3 and quiet(m)
        try:
            mid = self.pick("N1", ok)
        except RuntimeError:   # small preset: one mine per company - rebalance within the mine's month
            mid = self.pick("N1", lambda m: quiet(m))
        p = self.T("daily_production")
        dates = [(d0 + pd.Timedelta(days=k)).date() for k in range(length)]
        rows = p.index[(p["mine_id"] == mid) & p["date"].isin(dates)]
        before_target = p.loc[rows, "coal_target_t"].copy()
        self.shift_production(rows, 1.25, target_too=True)
        remark = f"Additional excavator crew deployed from {dates[0]} (approved)"
        p.loc[rows, "remarks"] = remark
        first = p.loc[rows].sort_values(["date", "shift"]).index[0]
        log = self.T("production_edit_log")
        lid = _next_id(log)
        edited = min(p.at[first, "submitted_at"] + pd.Timedelta(hours=1), self.as_of) if pd.notna(p.at[first, "submitted_at"]) else self.as_of
        row = {"id": lid, "production_id": int(p.at[first, "id"]), "field": "coal_target_t",
               "old_value": f"{before_target[first]:g}", "new_value": f"{p.at[first, 'coal_target_t']:g}",
               "reason": f"Target revised: additional excavator crew approved by the Area General Manager from {dates[0]}",
               "edited_by": self.head_of[mid], "edited_at": edited}
        self.put("production_edit_log", pd.concat([log, pd.DataFrame([row])], ignore_index=True))
        self.label("N1_LEGIT_PRODUCTION_INCREASE", "negative", mid, "daily_production", p.loc[rows, "id"], dates[0], dates[-1],
                   "legitimate +25 % after an approved crew addition; target revised; not an anomaly")
        self.label("N1_LEGIT_PRODUCTION_INCREASE", "negative", mid, "production_edit_log", [lid], dates[0], dates[0],
                   "edit log giving the reason")
        self.n1 = {"mine_id": mid, "rows": p.loc[rows, "id"].tolist(), "from": dates[0], "to": dates[-1], "edit_log": lid}

    def n2_burst_within_sla(self):
        mid = self.pick("N2")
        d_from = self.day(35 if self.ctx.days >= 40 else 10)
        ids, d_to = self._grievances(mid, 6, d_from, 5 if self.ctx.days >= 30 else 3, ["wages", "working_conditions"], False,
                                     "N2_GRIEVANCE_BURST_WITHIN_SLA", "grievance burst, all resolved within SLA; not a breach cluster")
        self.n2 = {"mine_id": mid, "ids": ids, "from": d_from, "to": d_to}

    def n3_night_maintenance(self):
        mid = self.pick("N3")
        p = self.T("daily_production")
        mine_rows = p[p["mine_id"] == mid]
        c_ids = []
        for d, grp in mine_rows.groupby("date"):
            c = grp.index[grp["shift"] == "C"]
            ab = grp.index[grp["shift"] != "C"]
            for col in ["coal_actual_t", "coal_target_t", "ob_actual_m3", "ob_target_m3"]:
                cut = float(p.loc[c, col].sum()) * 0.4
                p.loc[c, col] = (p.loc[c, col] * 0.6).round(1)
                p.loc[ab, col] = (p.loc[ab, col] + cut / len(ab)).round(1)
            p.loc[c, "remarks"] = p.loc[c, "remarks"].fillna("Planned maintenance in night shift")
            c_ids += p.loc[c, "id"].tolist()
        self.label("N3_NIGHT_SHIFT_MAINTENANCE", "negative", mid, "daily_production", c_ids, self.S, self.E,
                   "night-shift output 40 % lower for planned maintenance; compliance unchanged; not a compliance signal")
        self.n3 = {"mine_id": mid, "ids": c_ids}


def run(ctx: Ctx) -> None:
    inj = Injector(ctx)
    for step in ["s1_strata_incident", "s7_late_ca", "s2_spike", "s3_flatline", "s4_contractor", "s5_night",
                 "s6_sla_cluster", "n1_legit_increase", "n2_burst_within_sla", "n3_night_maintenance"]:
        getattr(inj, step)()
    ctx.notes["injector"] = inj


def finalize(ctx: Ctx) -> None:
    """After gen_alerts: resolve alert ids, emit scenario_label, build scenario_expectations."""
    inj: Injector = ctx.notes["injector"]
    code = lambda mid: str(inj.by_id.loc[mid, "code"])  # noqa: E731
    s1 = inj.s1
    inc_id = ctx.notes["alert_ids_by_key"]["S1_incident"]
    inj.label("S1_STRATA_REPEAT_INCIDENT", "positive", s1["mine_id"], "alert", [inc_id], s1["incident"], s1["incident"],
              f"dangerous occurrence ({s1['kind']}) after the repeat violations")
    lab = pd.DataFrame(inj.labels)
    ctx.emit("scenario_label", lab)
    iso = lambda t: pd.Timestamp(t).strftime("%Y-%m-%d")  # noqa: E731
    exp = [
        {"scenario_code": "S1_STRATA_REPEAT_INCIDENT", "polarity": "positive", "expected_flag": True,
         "detector": "risk_trend / inspection prioritisation", "mine_id": s1["mine_id"], "mine_code": code(s1["mine_id"]),
         "date_from": iso(s1["first"]), "date_to": iso(s1["d_to"]),
         "entities": {"violation": s1["violations"] + ([s1["post"]] if s1["post"] else []), "inspection": s1["inspections"],
                      "alert": [inc_id]},
         "signal": {"type": "repeat_category_then_incident", "category": "roof_strata", "min_repeats": s1["n"],
                    "within_days": s1["span"], "incident_alert_code": "DANGEROUS_OCCURRENCE_REPORTED",
                    "incident_date": iso(s1["incident"]), "open_after_incident": bool(s1["post"]),
                    "rebalanced_violation_id": ctx.notes.get("s1_rebalanced_violation")}},
        {"scenario_code": "S2_PRODUCTION_SPIKE_BEFORE_INSPECTION", "polarity": "positive", "expected_flag": True,
         "detector": "production anomaly", "mine_id": inj.s2["mine_id"], "mine_code": code(inj.s2["mine_id"]),
         "date_from": str(inj.s2["day"]), "date_to": iso(inj.s2["t_i"]),
         "entities": {"daily_production": inj.s2["rows"], "inspection": [inj.s2["inspection"]]},
         "signal": {"type": "production_spike_before_inspection", "day": str(inj.s2["day"]), "inspection_date": iso(inj.s2["t_i"]),
                    "min_ratio_to_trailing_mean": 1.6, "trailing_days": 30}},
        {"scenario_code": "S3_GAS_SENSOR_FLATLINE", "polarity": "positive", "expected_flag": True,
         "detector": "sensor anomaly (tampering)", "mine_id": inj.s3["mine_id"], "mine_code": code(inj.s3["mine_id"]),
         "date_from": iso(inj.s3["from"]), "date_to": iso(inj.s3["to"] - pd.Timedelta(minutes=1)),
         "entities": {"sensor_reading": [inj.s3["ids"][0], inj.s3["ids"][-1]]},
         "signal": {"type": "flatline", "sensor_type": "ch4", "hours": 72, "value": inj.s3["value"], "max_std": 0.0,
                    "readings": len(inj.s3["ids"]), "note": "entities lists the first and last reading id; all ids are in scenario_label"}},
        {"scenario_code": "S4_CONTRACTOR_MISSING_WAGE_EPF", "polarity": "positive", "expected_flag": True,
         "detector": "contractor score", "mine_id": inj.s4["mine_id"], "mine_code": code(inj.s4["mine_id"]),
         "date_from": iso(inj.S), "date_to": iso(inj.E),
         "entities": {"contractor": [inj.s4["contractor_id"]], "contract": inj.s4["contracts"], "violation": inj.s4["violations"]},
         "signal": {"type": "contractor_missing_docs_and_violations", "contractor_id": inj.s4["contractor_id"],
                    "missing_docs": [{"contract_id": k, "period": p, "doc_type": d} for k, p, d in inj.s4["missing"]],
                    "violations_per_active_worker": inj.s4["vpw"], "next_highest": inj.s4["next_vpw"],
                    "must_rank_first": True}},
        {"scenario_code": "S5_NIGHT_SHIFT_COMPLIANCE", "polarity": "positive", "expected_flag": True,
         "detector": "shift compliance", "mine_id": inj.s5["mine_id"], "mine_code": code(inj.s5["mine_id"]),
         "date_from": iso(inj.S), "date_to": iso(inj.E), "entities": {"violation": inj.s5["ids"]},
         "signal": {"type": "night_share", "source": "vision", "night_hours_ist": [22, 6], "min_share": 0.6,
                    "share": inj.s5["share"]}},
        {"scenario_code": "S6_GRIEVANCE_SLA_CLUSTER", "polarity": "positive", "expected_flag": True,
         "detector": "grievance SLA", "mine_id": inj.s6["mine_id"], "mine_code": code(inj.s6["mine_id"]),
         "date_from": iso(inj.s6["from"]), "date_to": iso(inj.s6["to"]), "entities": {"grievance": inj.s6["ids"]},
         "signal": {"type": "sla_cluster", "min_breaches": len(inj.s6["ids"]), "window_days": (inj.s6["to"] - inj.s6["from"]).days}},
        {"scenario_code": "S7_LATE_CORRECTIVE_ACTIONS", "polarity": "positive", "expected_flag": True,
         "detector": "corrective-action timeliness", "mine_id": inj.s7["mine_id"], "mine_code": code(inj.s7["mine_id"]),
         "date_from": iso(inj.s7["from"]), "date_to": iso(inj.s7["to"]), "entities": {"corrective_action": inj.s7["ids"]},
         "signal": {"type": "late_closure", "min_late": len(inj.s7["ids"]), "min_late_share_of_resolved": 0.8,
                    "note": "brief says 'at one area'; OD-TLC-05 has no published area, so the pattern is mine-level"}},
        {"scenario_code": "N1_LEGIT_PRODUCTION_INCREASE", "polarity": "negative", "expected_flag": False,
         "detector": "production anomaly", "mine_id": inj.n1["mine_id"], "mine_code": code(inj.n1["mine_id"]),
         "date_from": str(inj.n1["from"]), "date_to": str(inj.n1["to"]),
         "entities": {"daily_production": inj.n1["rows"], "production_edit_log": [inj.n1["edit_log"]]},
         "signal": {"type": "legit_increase", "factor": 1.25, "target_revised": True, "edit_log_reason": True,
                    "no_inspection_within_days": 5}},
        {"scenario_code": "N2_GRIEVANCE_BURST_WITHIN_SLA", "polarity": "negative", "expected_flag": False,
         "detector": "grievance SLA", "mine_id": inj.n2["mine_id"], "mine_code": code(inj.n2["mine_id"]),
         "date_from": iso(inj.n2["from"]), "date_to": iso(inj.n2["to"]), "entities": {"grievance": inj.n2["ids"]},
         "signal": {"type": "burst_within_sla", "count": len(inj.n2["ids"]), "max_breaches": 0}},
        {"scenario_code": "N3_NIGHT_SHIFT_MAINTENANCE", "polarity": "negative", "expected_flag": False,
         "detector": "shift compliance", "mine_id": inj.n3["mine_id"], "mine_code": code(inj.n3["mine_id"]),
         "date_from": iso(inj.S), "date_to": iso(inj.E), "entities": {"daily_production": inj.n3["ids"]},
         "signal": {"type": "night_production_lower_not_compliance", "c_output_factor": 0.6, "max_night_violation_share": 0.5}},
    ]
    ctx.notes["scenario_expectations"] = exp
