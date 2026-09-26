"""inspection, observation, violation, corrective_action.

Rates (reference/msha_rates.csv, US coal mine-years 2017-2025 with employees > 0), by mine type
(underground and mixed -> MSHA Underground; opencast -> Surface):
  inspections per year   median over mine-years
  violations/inspection  total violations / total inspections
  size factor            (GEM workforce / MSHA 75th-percentile headcount) ^ exponent, clipped
                         (config generation.inspection_size_*): Indian mines employ far more
                         people than US ones, so rates grow sub-linearly with headcount.
Category mix: 0.5 x the MSHA category mix of that mine type (via crosswalk_msha_india.csv, in
msha_rates' v_* columns) + 0.5 x the DGMS mix (accident_causes_dgms.csv, fatal + serious accidents
2013-2022 per cause, mapped by the crosswalk's DGMS rows; "Miscellaneous" is unmapped and left out).
Observations: about 1.4 per expected violation; 70 % promoted to violations, 20 % dismissed.
Safety grievances create a linked observation (brief Phase 5), promoted once the grievance is resolved.

Open violations: the backend score counts every unresolved violation (5 points), so each mine gets
exactly its roster's seed_violations open: half (rounded down) its most recent inspection
violations, the rest recent PPE vision detections. Everything else is resolved. This is what
makes the generated data reproduce the demo scores (checked in generate.py).
Corrective action per violation: due 7-30 days after creation (a modelling choice - no legal
deadline applies); closure lognormal around 12 days, so some close late; open ones may be overdue.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from common import REF, Ctx, categories

PPE_TYPES = ["no_helmet", "no_safety_vest", "no_safety_boots", "no_dust_mask"]
CA_TEXT = {
    "roof_strata": "Install additional roof support and re-examine the area before work resumes.",
    "ventilation_gas": "Restore ventilation, test for gas and record readings before resuming work.",
    "electrical": "Isolate and repair the apparatus; recheck earthing and cable condition.",
    "transport_haulage": "Repair and re-examine haulage equipment; brief operators on the procedure.",
    "explosives": "Secure explosives storage and retrain the shotfiring crew.",
    "ppe": "Issue the missing protective equipment and brief the shift on its use.",
    "fire": "Service fire-fighting equipment and clear escape routes.",
    "environment": "Resume dust suppression and sampling; review exposure controls.",
    "welfare": "Restore drinking water and first-aid facilities at the work site.",
    "documentation": "Update the plans and registers and send the pending notice.",
    "machinery": "Fit the missing guard and complete the maintenance schedule.",
}
TEMPLATE_CATEGORY = {"safety_ppe": "ppe", "safety_haul_road": "transport_haulage", "safety_ventilation": "ventilation_gas"}


def rates_and_mix(ctx: Ctx):
    cats = categories()
    label_to_key = cats["crosswalk_labels"]
    keys = [c["key"] for c in cats["categories"]]
    m = pd.read_csv(REF / "msha_rates.csv", dtype={"mine_id": str})
    m = m[m["mine_type"].isin(["Underground", "Surface"]) & (m["employees_now"] > 0)]
    vcol = {label_to_key[lbl]: f"v_{lbl.replace('/', '_')}" for lbl in label_to_key}
    rates, msha_mix = {}, {}
    for t, d in m.groupby("mine_type"):
        rates[t] = {"insp_per_year": float(d["inspections"].median()),
                    "viol_per_insp": float(d["violations"].sum() / d["inspections"].sum()),
                    "p75_employees": float(d["employees_now"].quantile(0.75))}
        tot = np.array([d[vcol[k]].sum() for k in keys], float)
        msha_mix[t] = tot / tot.sum()
    acc = pd.read_csv(REF / "accident_causes_dgms.csv")
    acc = acc[acc["row_type"] == "cause"]
    xw = pd.read_csv(REF / "crosswalk_msha_india.csv")
    dg = dict(zip(xw.loc[xw["source_system"] == "DGMS", "source_label"], xw.loc[xw["source_system"] == "DGMS", "indian_category"]))
    w = acc.groupby("cause")[["fatal_accidents", "serious_accidents"]].sum().sum(axis=1)
    dg_tot = np.zeros(len(keys))
    for cause, v in w.items():
        lbl = dg.get(cause)
        if isinstance(lbl, str) and lbl:
            dg_tot[keys.index(label_to_key[lbl])] += v
    dgms_mix = dg_tot / dg_tot.sum()
    mix = {t: 0.5 * msha_mix[t] + 0.5 * dgms_mix for t in msha_mix}
    return keys, cats, rates, mix, msha_mix, dgms_mix


def run(ctx: Ctx) -> None:
    rng = ctx.rng("inspections")
    gen = ctx.gen
    keys, cats, rates, mix, msha_mix, dgms_mix = rates_and_mix(ctx)
    types_of = {c["key"]: c["example_types"] for c in cats["categories"]}
    head_of = ctx.notes["head_of"]
    inspectors = ctx.notes["inspector_ids"]
    contracts = ctx.tables["contract"]
    start = pd.Timestamp(ctx.start, tz="UTC")
    as_of = ctx.as_of
    days = ctx.days
    ist = pd.Timedelta(hours=5, minutes=30)

    insp, obs, vio = [], [], []
    expected = {}
    for m in ctx.mines.itertuples():
        t = "Underground" if m.type in ("underground", "mixed") else "Surface"
        r = rates[t]
        size = float(np.clip((m.workforce / r["p75_employees"]) ** gen["inspection_size_exponent"], *gen["inspection_size_clip"]))
        lam = r["insp_per_year"] * days / 365 * size
        expected[m.id] = {"inspections": lam, "violations": lam * r["viol_per_insp"] * size, "type": t, "size": size}
        mine_contracts = contracts.loc[contracts["mine_id"] == m.id, "contractor_id"].tolist()
        for _ in range(int(rng.poisson(lam))):
            sched = start + pd.Timedelta(days=int(rng.integers(0, days)))
            visit = sched + pd.Timedelta(days=int(rng.integers(0, 3)), hours=float(rng.uniform(10, 15))) - ist
            close = visit + pd.Timedelta(days=float(rng.uniform(1, 10)))
            status = "closed" if close <= as_of else ("visited" if visit <= as_of else "scheduled")
            iid = len(insp) + 1
            insp.append({"id": iid, "mine_id": m.id, "inspector_id": int(rng.choice(inspectors)),
                         "inspection_type": rng.choice(["regular", "spot", "complaint"], p=[0.4, 0.5, 0.1]),
                         "status": status, "scheduled_for": sched.date(),
                         "visited_at": visit if status != "scheduled" else None,
                         "closed_at": close if status == "closed" else None, "findings_count": 0,
                         "is_locked": status == "closed"})
            if status == "scheduled":
                continue
            for _ in range(int(rng.poisson(r["viol_per_insp"] * size * 1.4))):
                cat = keys[int(rng.choice(len(keys), p=mix[t]))]
                u = rng.random()
                ostatus = "promoted" if u < 0.7 else ("dismissed" if u < 0.9 or status == "closed" else "open")
                oid = len(obs) + 1
                observed = visit + pd.Timedelta(minutes=float(rng.uniform(10, 240)))
                contractor = int(rng.choice(mine_contracts)) if (mine_contracts and rng.random() < 0.3) else None
                obs.append({"id": oid, "inspection_id": iid, "grievance_id": None, "mine_id": m.id, "category": cat,
                            "severity": rng.choice(["low", "medium", "high"], p=[0.35, 0.45, 0.20]),
                            "status": ostatus, "violation_id": None, "contractor_id": contractor,
                            "observed_at": min(observed, as_of)})
                insp[-1]["findings_count"] += 1
                if ostatus == "promoted":
                    vio.append({"mine_id": m.id, "violation_type": types_of[cat][int(rng.integers(len(types_of[cat])))],
                                "category": cat, "confidence": None, "source": "inspection", "frame_ref": None,
                                "inspection_id": iid, "observation_id": oid, "contractor_id": contractor,
                                "detected_at": min(observed, as_of), "_obs": oid})
        # historical PPE vision detections (resolved later)
        for _ in range(int(rng.poisson(3 * days / 90 * size))):
            det = start + pd.Timedelta(hours=float(rng.uniform(0, days * 24 - 1)))
            vio.append(_vision(m, det, rng))

    # safety grievances -> linked observation (promoted once the grievance is resolved)
    gnotes = ctx.notes["grievances"].rename(columns={"_template": "template", "_resolved_at": "resolved_at"})
    for g in gnotes.itertuples():
        if g.category != "safety":
            continue
        cat = TEMPLATE_CATEGORY.get(g.template, "ppe")
        oid = len(obs) + 1
        at = g.created_at + pd.Timedelta(hours=1)
        promoted = g.resolved_at <= as_of
        obs.append({"id": oid, "inspection_id": None, "grievance_id": g.id, "mine_id": g.mine_id, "category": cat,
                    "severity": "high", "status": "promoted" if promoted else "open", "violation_id": None,
                    "contractor_id": None, "observed_at": min(at, as_of)})
        if promoted:
            vio.append({"mine_id": g.mine_id, "violation_type": types_of[cat][0], "category": cat, "confidence": None,
                        "source": "grievance", "frame_ref": None, "inspection_id": None, "observation_id": oid,
                        "contractor_id": None, "detected_at": min(at, as_of), "_obs": oid, "_resolve_by": g.resolved_at})

    v = pd.DataFrame(vio)
    # exactly seed_violations open per mine
    v["resolved"] = True
    for m in ctx.mines.itertuples():
        n = int(m.seed_violations)
        if n == 0:
            continue
        idx_insp = v.index[(v["mine_id"] == m.id) & (v["source"] == "inspection")]
        recent = v.loc[idx_insp].sort_values("detected_at", ascending=False).index[: n // 2]
        v.loc[recent, "resolved"] = False
        extra = []
        for _ in range(n - len(recent)):
            det = as_of - pd.Timedelta(hours=float(rng.uniform(24, 20 * 24)))
            extra.append({**_vision(m, det, rng), "resolved": False})
        v = pd.concat([v, pd.DataFrame(extra)], ignore_index=True)
    v = v.sort_values(["detected_at", "mine_id"], kind="mergesort").reset_index(drop=True)
    v["id"] = range(1, len(v) + 1)
    close_h = rng.lognormal(np.log(12 * 24), 0.8, len(v))
    res_at = []
    if "_resolve_by" not in v:
        v["_resolve_by"] = None
    for det, ok, h, by in zip(v["detected_at"], v["resolved"], close_h, v["_resolve_by"]):
        if not ok:
            res_at.append(None)
            continue
        t = pd.Timestamp(by) if isinstance(by, pd.Timestamp) else det + pd.Timedelta(hours=float(h))
        if t > as_of:
            t = det + (as_of - det) * float(rng.uniform(0.3, 0.95))
        res_at.append(max(t, det + pd.Timedelta(minutes=30)) if t < as_of else as_of)
    v["resolved_at"] = res_at
    obs_df = pd.DataFrame(obs)
    link = v.dropna(subset=["_obs"]).set_index("_obs")["id"]
    obs_df["violation_id"] = obs_df["id"].map(link)

    # corrective actions: one per violation
    ca = []
    for r in v.itertuples():
        created = min(r.detected_at + pd.Timedelta(hours=float(rng.uniform(1, 48))), as_of)
        if r.resolved and r.resolved_at < created:
            created = r.detected_at + (r.resolved_at - r.detected_at) / 2
        if r.resolved:
            due = created + pd.Timedelta(days=int(rng.integers(7, 31)))
        else:
            overdue = rng.random() < 0.4 and (as_of - created) > pd.Timedelta(days=4)
            due = (created + (as_of - created) * float(rng.uniform(0.3, 0.8))) if overdue \
                else as_of + pd.Timedelta(days=int(rng.integers(3, 20)))
            due = min(due, as_of) if overdue else due
        ca.append({"id": len(ca) + 1, "mine_id": r.mine_id, "violation_id": r.id, "alert_id": None,
                   "contractor_id": r.contractor_id if pd.notna(r.contractor_id) else None,
                   "description": CA_TEXT[r.category], "status": "resolved" if r.resolved else "open",
                   "due_at": due, "created_by": head_of[r.mine_id],
                   "proof_image_path": f"proof/ca_{len(ca) + 1:06d}.jpg" if (r.resolved and rng.random() < 0.7) else None,
                   "created_at": created, "resolved_at": r.resolved_at if r.resolved else None})
    ca_df = pd.DataFrame(ca)
    # due dates in the future are allowed only for open actions (a deadline is not an event)
    ctx.emit("inspection", pd.DataFrame(insp))
    ctx.emit("observation", obs_df)
    ctx.emit("violation", v.drop(columns=[c for c in ["_obs", "_resolve_by"] if c in v.columns]))
    ctx.emit("corrective_action", ca_df)
    ctx.notes["inspection_model"] = {"rates": rates, "mix": dict(zip(keys, np.round(mix["Underground"], 4))),
                                     "mix_surface": dict(zip(keys, np.round(mix["Surface"], 4))),
                                     "msha_mix": {t: dict(zip(keys, np.round(x, 4))) for t, x in msha_mix.items()},
                                     "dgms_mix": dict(zip(keys, np.round(dgms_mix, 4))), "expected": expected}


def _vision(m, det: pd.Timestamp, rng) -> dict:
    return {"mine_id": m.id, "violation_type": PPE_TYPES[int(rng.integers(len(PPE_TYPES)))], "category": "ppe",
            "confidence": round(float(rng.uniform(0.55, 0.95)), 2), "source": "vision",
            "frame_ref": f"annotated/{m.code.lower()}_frame_{int(rng.integers(1, 9999)):04d}.jpg",
            "inspection_id": None, "observation_id": None, "contractor_id": None, "detected_at": det, "_obs": None}
