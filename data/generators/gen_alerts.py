"""alert - historical alerts derived from the generated events; {code, params}, never display text.

One alert per:
  SENSOR_THRESHOLD_BREACHED     breach episode (consecutive breached readings of one sensor), params
                                cite the limit and its obligation code; severity by margin over the
                                limit as the current backend does (>= 30 % high, >= 10 % medium)
  VIOLATION_RECORDED            violation
  CORRECTIVE_ACTION_OVERDUE     open corrective action past its due date
  CONTRACTOR_LICENCE_EXPIRING   contract whose contractor's licence ends within 30 days or has ended
  WORKER_VT_EXPIRED / WORKER_MEDICAL_EXPIRED   contract with such active workers (count in params)
  CONTRACTOR_DOC_MISSING        contract and month with documents missing after the due day
  GRIEVANCE_SLA_BREACHED        grievance past its SLA
  DETAIL_REQUEST_OVERDUE        overdue or escalated detail request
Status: alerts on resolved things are resolved; otherwise open if under 3 days old, else acknowledged.
"""

from __future__ import annotations

import pandas as pd

from common import Ctx


def run(ctx: Ctx) -> None:
    rng = ctx.rng("alerts")
    as_of = ctx.as_of
    head_of, gov = ctx.notes["head_of"], ctx.notes["gov_id"]
    sens = ctx.rules["sensors"]
    rows = []

    def add(code, params, severity, mine_id, etype, eid, created, resolved=False, esc=0):
        created = min(pd.Timestamp(created), as_of)
        if resolved:
            status = "resolved"
        else:
            status = "open" if (as_of - created) < pd.Timedelta(days=3) else "acknowledged"
        rows.append({"code": code, "params": params, "severity": severity, "mine_id": int(mine_id), "entity_type": etype,
                     "entity_id": int(eid), "status": status, "ack_by": None if status == "open" else head_of[int(mine_id)],
                     "escalation_level": esc, "created_at": created})

    # sensor breach episodes
    b = ctx.notes["sensor_breaches"].sort_values(["mine_id", "sensor_type", "recorded_at"])
    for (mid, st), g in b.groupby(["mine_id", "sensor_type"]):
        gap = g["recorded_at"].diff() > pd.Timedelta(hours=2)
        for _, ep in g.groupby(gap.cumsum()):
            peak = ep.loc[ep["value"].idxmax()]
            lim = sens[st]["limit"]
            margin = (peak["value"] - lim) / lim
            sev = "high" if margin >= 0.3 else ("medium" if margin >= 0.1 else "low")
            add("SENSOR_THRESHOLD_BREACHED", {"sensor_type": st, "value": float(peak["value"]), "limit": lim,
                                              "unit": sens[st]["unit"], "obligation": sens[st]["obligation"],
                                              "readings": int(len(ep))},
                sev, mid, "sensor_reading", ep["id"].iloc[0], ep["recorded_at"].iloc[0],
                resolved=(as_of - ep["recorded_at"].iloc[-1]) > pd.Timedelta(hours=6))

    obs = ctx.tables["observation"].set_index("id")
    for v in ctx.tables["violation"].itertuples():
        if v.source == "vision":
            sev = "high" if v.confidence >= 0.8 else ("medium" if v.confidence >= 0.6 else "low")
        elif pd.notna(v.observation_id):
            sev = obs.at[int(v.observation_id), "severity"]
        else:
            sev = "medium"
        add("VIOLATION_RECORDED", {"category": v.category, "violation_type": v.violation_type, "source": v.source},
            sev, v.mine_id, "violation", v.id, v.detected_at, resolved=bool(v.resolved))

    for c in ctx.tables["corrective_action"].itertuples():
        if c.status == "open" and c.due_at < as_of:
            add("CORRECTIVE_ACTION_OVERDUE", {"violation_id": int(c.violation_id),
                                              "days_overdue": int((as_of - c.due_at).days)},
                "medium", c.mine_id, "corrective_action", c.id, c.due_at, esc=1)

    contractor = ctx.tables["contractor"].set_index("id")
    alert_days = ctx.rules["product"]["contractor_licence_expiring_alert_days"]
    for k in ctx.tables["contract"].itertuples():
        vt = pd.Timestamp(contractor.at[k.contractor_id, "licence_valid_to"])
        days_left = (vt - as_of.normalize().tz_localize(None)).days
        if days_left <= alert_days:
            add("CONTRACTOR_LICENCE_EXPIRING", {"contractor_id": int(k.contractor_id), "licence_valid_to": str(vt.date()),
                                                "days_left": int(days_left), "obligation": "LAB-02"},
                "high" if days_left <= 0 else "medium", k.mine_id, "contract", k.id,
                as_of - pd.Timedelta(days=max(0, alert_days - max(days_left, 0))))

    w = ctx.tables["contract_worker"]
    w = w[w["active"]]
    mine_of = dict(zip(ctx.tables["contract"]["id"], ctx.tables["contract"]["mine_id"]))
    today = as_of.normalize().tz_localize(None)
    med_months = ctx.rules["legal"]["medical_exam_interval_months"]["value"]
    vt_exp = w[pd.to_datetime(w["vt_cert_valid_to"]) < today].groupby("contract_id").size()
    med_exp = w[pd.to_datetime(w["medical_exam_date"]) < today - pd.DateOffset(months=med_months)].groupby("contract_id").size()
    for cid, n in vt_exp.items():
        add("WORKER_VT_EXPIRED", {"contract_id": int(cid), "workers": int(n), "obligation": "SAF-04"}, "medium",
            mine_of[cid], "contract", cid, as_of - pd.Timedelta(days=int(rng.integers(1, 20))))
    for cid, n in med_exp.items():
        add("WORKER_MEDICAL_EXPIRED", {"contract_id": int(cid), "workers": int(n), "obligation": "HLT-01"}, "medium",
            mine_of[cid], "contract", cid, as_of - pd.Timedelta(days=int(rng.integers(1, 20))))

    docs = ctx.tables["contractor_compliance_doc"]
    have = set(zip(docs["contract_id"], docs["period"], docs["doc_type"]))
    due_day = ctx.rules["product"]["contractor_doc_due_day"]
    for k in ctx.tables["contract"].itertuples():
        for p in ctx.notes["periods_due"]:
            per = pd.Period(p, "M")
            if per.end_time < pd.Timestamp(k.start_date) or per.start_time > pd.Timestamp(k.end_date):
                continue
            missing = [d for d in ["wage_register", "epf_challan", "esi_challan"] if (k.id, p, d) not in have]
            if missing:
                due = (per + 1).to_timestamp() + pd.Timedelta(days=due_day)
                add("CONTRACTOR_DOC_MISSING", {"contract_id": int(k.id), "period": p, "doc_types": missing},
                    "medium", k.mine_id, "contract", k.id, pd.Timestamp(due, tz="UTC"))

    for g in ctx.notes["grievances"].rename(columns={"_breached": "breached"}).itertuples():
        if g.breached:
            add("GRIEVANCE_SLA_BREACHED", {"grievance_id": int(g.id), "category": g.category}, "high", g.mine_id,
                "grievance", g.id, g.sla_due_at, resolved=g.status in ("resolved", "closed"), esc=1)

    for r in ctx.tables["production_detail_request"].itertuples():
        if r.status in ("overdue", "escalated"):
            add("DETAIL_REQUEST_OVERDUE", {"request_id": int(r.id), "due_at": r.due_at.strftime("%Y-%m-%dT%H:%M:%SZ")},
                "high", r.mine_id, "production_detail_request", r.id, r.due_at, esc=2 if r.status == "escalated" else 1)

    a = pd.DataFrame(rows).sort_values(["created_at", "mine_id", "code"], kind="mergesort").reset_index(drop=True)
    a.insert(0, "id", range(1, len(a) + 1))
    ctx.emit("alert", a)
