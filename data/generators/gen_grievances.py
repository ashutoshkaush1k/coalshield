"""grievance, grievance_action.

Texts: generators/grievance_templates.py (six languages, glossary terms; every template must
contain a glossary.csv term of its language or the run stops). Language by the mine's state
(common.STATE_LANGUAGE): 75 % the state language, 15 % Hindi, 10 % English.
SLA per category: schema/rules.yaml `product.grievance_sla_hours` (product settings from the
brief's example, not legal values). Sensitive routing (brief Phase 5): harassment or
against_mine_head -> assigned to government. Modelling choices (documented, not facts): category
mix below, resolution time lognormal around 0.6 x SLA, about 15 % past SLA.
"""

from __future__ import annotations

import re

import numpy as np
import pandas as pd
from faker import Faker

from common import REF, Ctx, GenerationError, tracking_code
from gen_contractors import REGIONAL_SURNAMES
from grievance_templates import RESOLUTION, TEMPLATES

CATEGORY_MIX = {"wages": 0.22, "safety": 0.24, "working_conditions": 0.18, "harassment": 0.06, "environment": 0.14,
                "land_compensation": 0.08, "other": 0.08}
LANGS = ["en", "hi", "bn", "or", "te", "mr"]


def check_glossary_use() -> None:
    g = pd.read_csv(REF / "glossary.csv", dtype=str)
    for lang in LANGS:
        terms = [re.sub(r"\s*\(.*?\)\s*$", "", t).strip() for t in g[lang].dropna()]
        terms = [t for t in terms if len(t) >= 2]
        for key, tpl in TEMPLATES.items():
            text = tpl[lang].lower() if lang == "en" else tpl[lang]
            if not any((t.lower() if lang == "en" else t) in text for t in terms):
                raise GenerationError(f"grievance template {key}/{lang} uses no glossary term")


def run(ctx: Ctx) -> None:
    check_glossary_use()
    rng = ctx.rng("grievances")
    fake = Faker("en_IN")
    fake.seed_instance(ctx.seed + 2)
    sla = ctx.rules["product"]["grievance_sla_hours"]
    gov = ctx.notes["gov_id"]
    head_of = ctx.notes["head_of"]
    start = pd.Timestamp(ctx.start, tz="UTC")
    span_h = (ctx.as_of - start).total_seconds() / 3600
    by_cat = {}
    for k, t in TEMPLATES.items():
        by_cat.setdefault(t["category"], []).append(k)
    cats, probs = list(CATEGORY_MIX), np.array(list(CATEGORY_MIX.values()))
    rows, acts, counters = [], [], {}
    for m in ctx.mines.itertuples():
        k = int(rng.poisson(ctx.scale["grievances_per_mine"]))
        for _ in range(k):
            cat = cats[int(rng.choice(len(cats), p=probs))]
            keys = [x for x in by_cat[cat] if not (TEMPLATES[x].get("underground_only") and m.type == "opencast")]
            key = keys[int(rng.integers(len(keys)))]
            tpl = TEMPLATES[key]
            u = rng.random()
            lang = m.language if u < 0.75 else ("hi" if u < 0.90 else "en")
            text = tpl[lang].format(n=int(rng.integers(2, 9)), shift="ABC"[int(rng.integers(3))])
            created = start + pd.Timedelta(hours=float(rng.uniform(0, span_h - 1)))
            community = tpl.get("community", False)
            if tpl.get("against_mine_head") or cat == "harassment":
                sub = "anonymous" if rng.random() < 0.8 else rng.choice(["employee", "contract_worker"])
            elif community:
                sub = "community" if rng.random() < 0.85 else "anonymous"
            else:
                sub = rng.choice(["employee", "contract_worker", "anonymous"], p=[0.4, 0.45, 0.15])
            anon = sub == "anonymous"
            surn = REGIONAL_SURNAMES.get(m.state, REGIONAL_SURNAMES["Jharkhand"])
            sens = cat == "harassment" or bool(tpl.get("against_mine_head"))
            sev = rng.choice(["low", "medium", "high"], p=[0.1, 0.4, 0.5] if cat in ("safety", "harassment") else [0.35, 0.45, 0.2])
            sla_h = sla[cat]
            due = created + pd.Timedelta(hours=sla_h)
            res_h = float(rng.lognormal(np.log(0.6 * sla_h), 0.7))
            resolved_at = created + pd.Timedelta(hours=res_h)
            year = created.year
            counters[year] = counters.get(year, 0) + 1
            gid = len(rows) + 1
            timeline = [("submit", None, "received", None, created)]
            ack = created + pd.Timedelta(hours=float(rng.uniform(1, min(24, sla_h / 3))))
            actor = gov if sens else head_of[m.id]
            status = "received"
            if ack <= ctx.as_of:
                timeline.append(("acknowledge", "received", "acknowledged", None, ack)); status = "acknowledged"
                inv = ack + pd.Timedelta(hours=float(rng.uniform(2, max(3, sla_h / 3))))
                if inv <= ctx.as_of and inv < resolved_at:
                    timeline.append(("investigate", "acknowledged", "under_investigation", None, inv)); status = "under_investigation"
            breached = resolved_at > due and due <= ctx.as_of
            esc = 0
            if breached:
                esc = 2 if (min(resolved_at, ctx.as_of) - due) > pd.Timedelta(hours=sla_h) else 1
                timeline.append(("escalate", status, status, f"SLA breached; escalation level {esc}", due))
            note = rating = None
            if resolved_at <= ctx.as_of:
                timeline.append(("resolve", status, "resolved", None, resolved_at)); status = "resolved"
                note = RESOLUTION[cat]
                close = resolved_at + pd.Timedelta(days=float(rng.uniform(1, 5)))
                if close <= ctx.as_of and rng.random() < 0.7:
                    timeline.append(("close", "resolved", "closed", None, close)); status = "closed"
                    rating = int(rng.choice([1, 2, 3, 4, 5], p=[0.08, 0.12, 0.25, 0.35, 0.2]))
                elif rng.random() < 0.05:
                    reo = resolved_at + pd.Timedelta(hours=float(rng.uniform(12, 72)))
                    if reo <= ctx.as_of:
                        timeline.append(("reopen", "resolved", "reopened", "Complainant reports the issue continues", reo))
                        status = "reopened"
            for a, fr, to, nt, at in sorted(timeline, key=lambda x: x[4]):
                acts.append({"grievance_id": gid, "action": a, "from_status": fr, "to_status": to, "note": nt,
                             "actor_id": None if a == "submit" else actor, "created_at": at})
            rows.append({"id": gid, "ticket_no": f"GRV-{year}-{counters[year]:06d}", "mine_id": m.id,
                         "submitter_type": sub, "name": None if anon else f"{fake.first_name()} {surn[int(rng.integers(len(surn)))]}",
                         "contact": None if anon else f"XXXXXX{int(rng.integers(0, 10000)):04d}", "is_anonymous": anon,
                         "category": cat, "severity": sev, "language": lang, "description": text, "file_id": None,
                         "location": f"POINT({m.lon} {m.lat})" if community and pd.notna(m.lat) else None,
                         "status": status, "assigned_to": actor, "sla_due_at": due, "escalation_level": esc,
                         "against_mine_head": bool(tpl.get("against_mine_head", False)), "resolution_note": note,
                         "satisfaction_rating": rating, "created_at": created, "_resolved_at": resolved_at, "_breached": breached,
                         "_template": key})
    g = pd.DataFrame(rows)
    # ticket numbers in creation order within each year
    g = g.sort_values("created_at", kind="mergesort")
    g["ticket_no"] = [f"GRV-{y}-{i:06d}" for y, i in zip(g["created_at"].dt.year, g.groupby(g["created_at"].dt.year).cumcount() + 1)]
    g = g.sort_values("id")
    g.insert(2, "tracking_code", [tracking_code(ctx.seed, t) for t in g["ticket_no"]])
    ctx.notes["grievances"] = g[["id", "mine_id", "category", "created_at", "_breached", "sla_due_at", "status",
                                 "_template", "_resolved_at", "escalation_level", "assigned_to"]].copy()
    ctx.emit("grievance", g.drop(columns=["_resolved_at", "_breached", "_template"]))
    a = pd.DataFrame(acts).sort_values(["grievance_id", "created_at"], kind="mergesort").reset_index(drop=True)
    a.insert(0, "id", range(1, len(a) + 1))
    ctx.emit("grievance_action", a)
