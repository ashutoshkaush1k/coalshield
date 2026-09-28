"""obligation, obligation_applicability, obligation_task, obligation_submission (+ file rows) - Phase 5B.

The statutory obligation register's history for the demo window.

Catalogue: reference/obligations.csv (40 obligations, each cited: instrument, clause, page, quote;
`verified` yes or TODO-VERIFY), copied with the schedule the register uses.

Which obligations get due tasks: only **verified** obligations that apply to a **mine** and have a
**calendar frequency** (weekly, fortnightly, monthly, quarterly / every 3 months, half-yearly /
every 6 months, annual). On-event duties come from the events themselves: each incident gets one
task for its reporting obligation (incident.obligation_code, RPT-03 / RPT-04 / RPT-05), due
by the law's time (incident_deadline: RPT-05 12 h, RPT-04 12 h after 48 h of disablement, RPT-03
"forthwith" + a product grace period) and done - on time or late -
at its reported_at (the incident record is the report: no upload, no review); continuous limits (HLT-04, SAF-11, ENV-04/08 ...) are monitored by the sensor rules;
"every shift" (SAF-08) is too fine-grained for a register; contractor and worker duties belong to
the contractor module. RPT-08 (TODO-VERIFY) never gets a task.

Applicability per mine (obligation_applicability; modelling choices, documented, not legal facts):
  all mines                      by default
  underground and mixed mines    SAF-07 (winding ropes), SAF-09 (CO testing of depillaring
                                 districts), SAF-12 (inflammable gas checks where electricity is used)
                                 - their regulations concern shafts and underground workings
  500 or more workers            SAF-01 - the obligation's own note: "r.14(1): required where 500 or
                                 more workers are employed"

Due dates: where the obligation's due_rule names a date, that date (ENV-03: 30 September for the
financial year ending 31 March; RPT-06: 28/29 February following the calendar year) - due_basis
"law". Otherwise the product setting rules.yaml product.obligation_schedule (23:59 IST on the
period's last day) - due_basis "product".

Tasks cover every period that has started by the window end and falls due on or after the window
start. History (own random stream, so it shifts no other generator): each mine has a filing
discipline d ~ Beta(8, 2), nudged by its demo score; a task is submitted on time with probability d,
late with (1 - d) x 0.85, never with (1 - d) x 0.15; about 3 % of reviewed evidence is rejected with
a reason (most resubmitted); reviews by an inspector or the government 0.5-4 days after submission. Tasks still
open past their due time are overdue (escalation level 1), or escalated (level 2) once more than
product.obligation_schedule.escalate_after_hours past it.
"""

from __future__ import annotations

import datetime as dt
import hashlib

import numpy as np
import pandas as pd

from common import REF, Ctx

IST = dt.timedelta(hours=5, minutes=30)
SCHEDULE = {"weekly": "weekly", "fortnightly": "fortnightly", "monthly": "monthly", "quarterly": "quarterly",
            "every 3 months": "quarterly", "half-yearly": "half_yearly", "every 6 months": "half_yearly", "annual": "annual"}
# Annual obligations whose due_rule names a date (obligations.csv, verified): the period and the date.
LEGAL_ANNUAL = {"ENV-03": "financial_year", "RPT-06": "calendar_year_feb"}
UNDERGROUND_ONLY = {"SAF-07", "SAF-09", "SAF-12"}
WORKFORCE_500 = {"SAF-01"}
REJECT_REASONS = [
    "Report is unsigned; upload the signed copy.",
    "The report covers the wrong period.",
    "Sampling locations are missing from the report.",
    "Illegible scan; upload a clear copy.",
]


def due_utc(day: dt.date) -> pd.Timestamp:
    """23:59:59 IST on `day`, as UTC."""
    return pd.Timestamp(dt.datetime.combine(day, dt.time(23, 59, 59)) - IST, tz="UTC")


def periods(schedule: str, code: str, first: dt.date, last: dt.date):
    """(label, start, end, due_day, basis) for every period of `schedule` that starts on or before
    `last` and falls due on or after `first`."""
    out = []
    if schedule in ("weekly", "fortnightly"):
        monday = first - dt.timedelta(days=first.weekday()) - dt.timedelta(days=14)
        while monday <= last:
            y, w, _ = monday.isocalendar()
            if schedule == "weekly":
                start, end, label = monday, monday + dt.timedelta(days=6), f"{y}-W{w:02d}"
            else:
                if w % 2 == 0:            # fortnights are ISO weeks (1,2), (3,4), ...
                    monday += dt.timedelta(days=7)
                    continue
                weeks_in_year = dt.date(y, 12, 28).isocalendar()[1]
                start = monday
                end = monday + dt.timedelta(days=13 if w < weeks_in_year else 6)
                label = f"{y}-F{(w + 1) // 2:02d}"
            out.append((label, start, end, end, "product"))
            monday += dt.timedelta(days=7)
    else:
        years = range(first.year - 1, last.year + 2)
        for y in years:
            if schedule == "monthly":
                for m in range(1, 13):
                    start = dt.date(y, m, 1)
                    end = (dt.date(y + (m == 12), m % 12 + 1, 1) - dt.timedelta(days=1))
                    out.append((f"{y}-{m:02d}", start, end, end, "product"))
            elif schedule == "quarterly":
                for q in range(4):
                    start = dt.date(y, 3 * q + 1, 1)
                    end = dt.date(y + (q == 3), (3 * q + 3) % 12 + 1, 1) - dt.timedelta(days=1)
                    out.append((f"{y}-Q{q + 1}", start, end, end, "product"))
            elif schedule == "half_yearly":
                out.append((f"{y}-H1", dt.date(y, 1, 1), dt.date(y, 6, 30), dt.date(y, 6, 30), "product"))
                out.append((f"{y}-H2", dt.date(y, 7, 1), dt.date(y, 12, 31), dt.date(y, 12, 31), "product"))
            elif LEGAL_ANNUAL.get(code) == "financial_year":
                # "on or before 30 September, for the financial year ending 31 March"
                out.append((f"FY{y}-{str(y + 1)[2:]}", dt.date(y, 4, 1), dt.date(y + 1, 3, 31), dt.date(y + 1, 9, 30), "law"))
            elif LEGAL_ANNUAL.get(code) == "calendar_year_feb":
                # "on or before 28/29 February following each calendar year"
                feb_end = dt.date(y + 1, 3, 1) - dt.timedelta(days=1)
                out.append((f"{y}", dt.date(y, 1, 1), dt.date(y, 12, 31), feb_end, "law"))
            else:
                out.append((f"{y}", dt.date(y, 1, 1), dt.date(y, 12, 31), dt.date(y, 12, 31), "product"))
    return [p for p in out if p[1] <= last and p[3] >= first]


def incident_deadline(rules: dict, code: str) -> tuple[float, str]:
    """Hours from the incident to its reporting obligation's due time, and the basis (owner, Phase 6
    approval: the law's times). RPT-05 r.7(3) "within twelve hours"; RPT-04 r.7(2) "within twelve
    hours after the completion of forty-eight hours" (of disablement, counted from the incident);
    RPT-03 r.7(1) "forthwith" - immediate, with product.obligation_schedule.forthwith_grace_hours."""
    legal = rules["legal"]
    if code == "RPT-05":
        return float(legal["dangerous_occurrence_notice_hours"]["value"]), "law"
    if code == "RPT-04":
        return float(legal["injury_disablement_hours"]["value"] + legal["injury_report_hours_after_48h_disablement"]["value"]), "law"
    if code == "RPT-03":
        return float(rules["product"]["obligation_schedule"]["forthwith_grace_hours"]), "product"
    raise ValueError(f"no reporting deadline for {code}")


def run(ctx: Ctx) -> None:
    rng = ctx.rng("obligations")
    esc_after = pd.Timedelta(hours=float(ctx.rules["product"]["obligation_schedule"]["escalate_after_hours"]))
    ob = pd.read_csv(REF / "obligations.csv", dtype=str, keep_default_na=False)
    ob.insert(0, "id", range(1, len(ob) + 1))
    ob["verified"] = ob["verified"] == "yes"
    ob["schedule"] = ob["frequency"].map(SCHEDULE).fillna("none")
    ob["generates_tasks"] = ob["verified"] & (ob["applies_to"] == "mine") & (ob["schedule"] != "none")
    ob["due_basis"] = np.where(~ob["generates_tasks"], "none", np.where(ob["obligation_code"].isin(LEGAL_ANNUAL), "law", "product"))
    ob["citation_page"] = pd.to_numeric(ob["citation_page"], errors="coerce").astype("Int64")
    for c in ["citation_file", "citation_quote", "note", "instrument", "clause", "due_rule"]:
        ob[c] = ob[c].replace("", None)
    ob = ob.rename(columns={"obligation_code": "code"})
    ctx.emit("obligation", ob)

    users = ctx.tables["user"]
    reviewers = users.loc[users["role"].isin(["government", "inspector"]), "id"].astype(int).tolist()
    head_of = ctx.notes["head_of"]
    first = ctx.start if isinstance(ctx.start, dt.date) else pd.Timestamp(ctx.start).date()
    last = ctx.as_of.date()

    # Each mine's filing discipline (modelling choice): Beta(8, 2), nudged by the mine's demo score so
    # riskier mines also miss more statutory tasks; on time with probability d, late (1 - d) x 0.85,
    # never (1 - d) x 0.15.
    discipline = {int(m.id): float(np.clip(rng.beta(8, 2) + (float(m.demo_score) - 80) / 200, 0.55, 0.99))
                  for m in ctx.mines.itertuples()}
    app, tasks, subs = [], [], []
    files = ctx.tables["file"]
    next_file = int(files["id"].max()) + 1 if len(files) else 1
    new_files = []
    for o in ob[ob["generates_tasks"]].itertuples():
        for m in ctx.mines.itertuples():
            if o.code in UNDERGROUND_ONLY and m.type not in ("underground", "mixed"):
                continue
            if o.code in WORKFORCE_500 and not (pd.notna(m.workforce) and int(m.workforce) >= 500):
                continue
            basis = "underground_or_mixed" if o.code in UNDERGROUND_ONLY else ("workforce_500" if o.code in WORKFORCE_500 else "all_mines")
            app.append({"mine_id": m.id, "obligation_id": o.id, "basis": basis})
            for label, start, end, due_day, due_basis in periods(o.schedule, o.code, first, last):
                tid = len(tasks) + 1
                due = due_utc(due_day)
                period_start = pd.Timestamp(dt.datetime.combine(start, dt.time(0, 0)) - IST, tz="UTC")
                task = {"id": tid, "mine_id": m.id, "obligation_id": o.id, "period": label, "period_start": start,
                        "period_end": end, "due_at": due, "due_basis": due_basis, "status": "open", "escalation_level": 0,
                        "accepted_at": None, "created_at": max(period_start, pd.Timestamp(first, tz="UTC"))}
                u = rng.random()
                sub_at = None
                span = max(0.5, min(5.0, (due - period_start).total_seconds() / 86400 * 0.5))
                d = discipline[int(m.id)]
                if due <= ctx.as_of:
                    if u < d:
                        sub_at = due - pd.Timedelta(days=float(rng.uniform(0.2, span)))
                    elif u < d + (1 - d) * 0.85:
                        sub_at = due + pd.Timedelta(days=float(rng.uniform(0.5, 10)))
                elif (due - ctx.as_of) < pd.Timedelta(days=7) and u < 0.35:
                    sub_at = ctx.as_of - pd.Timedelta(days=float(rng.uniform(0, 2)))
                if sub_at is not None:
                    sub_at = max(sub_at, period_start + pd.Timedelta(hours=1))
                latest = None
                attempt = 0
                while sub_at is not None and sub_at <= ctx.as_of and attempt < 2:
                    attempt += 1
                    sid = len(subs) + 1
                    path = f"uploads/obligations/{tid}/{sid}.pdf"
                    new_files.append({"id": next_file, "path": path, "mime": "application/pdf",
                                      "size": int(rng.integers(120, 3200)) * 1024,
                                      "sha256": hashlib.sha256(path.encode()).hexdigest(), "uploaded_by": head_of[m.id],
                                      "entity": "obligation_submission", "entity_id": sid, "created_at": sub_at})
                    review_at = sub_at + pd.Timedelta(days=float(rng.uniform(0.5, 4)))
                    sub = {"id": sid, "task_id": tid, "file_id": next_file, "note": f"{o.evidence_type} for {label} attached.",
                           "submitted_by": head_of[m.id], "submitted_at": sub_at, "status": "pending",
                           "reviewed_by": None, "reviewed_at": None, "review_note": None}
                    next_file += 1
                    sub_at = None
                    if review_at <= ctx.as_of:
                        sub["reviewed_by"] = reviewers[int(rng.integers(len(reviewers)))]
                        sub["reviewed_at"] = review_at
                        if attempt == 1 and rng.random() < 0.03:
                            sub["status"] = "rejected"
                            sub["review_note"] = REJECT_REASONS[int(rng.integers(len(REJECT_REASONS)))]
                            if rng.random() < 0.6:
                                sub_at = review_at + pd.Timedelta(days=float(rng.uniform(1, 4)))
                        else:
                            sub["status"] = "accepted"
                    subs.append(sub)
                    latest = sub
                if latest is not None and latest["status"] == "accepted":
                    task["status"], task["accepted_at"] = "accepted", latest["reviewed_at"]
                elif latest is not None and latest["status"] == "pending":
                    task["status"] = "submitted"
                elif due <= ctx.as_of:
                    late = ctx.as_of - due
                    task["status"], task["escalation_level"] = ("escalated", 2) if late > esc_after else ("overdue", 1)
                elif latest is not None:
                    task["status"] = "rejected"
                task["incident_id"] = None
                tasks.append(task)

    # On-event reporting tasks (owner, 2026-09-28): one per incident, for its reporting obligation
    # (incident.obligation_code: RPT-03 / RPT-04 / RPT-05), due at the law's time (incident_deadline).
    # The incident record is the report, so the task is done when reported_at comes - on time or late
    # - with no upload and no review. No random draws: nothing else shifts.
    ob_id = dict(zip(ob["code"], ob["id"]))
    inc = ctx.tables["incident"].sort_values("id")
    for i in inc.itertuples():
        occurred = pd.Timestamp(i.occurred_at)
        occurred = occurred.tz_localize("UTC") if occurred.tzinfo is None else occurred.tz_convert("UTC")
        reported = pd.Timestamp(i.reported_at)
        reported = reported.tz_localize("UTC") if reported.tzinfo is None else reported.tz_convert("UTC")
        hours, basis = incident_deadline(ctx.rules, i.obligation_code)
        due = occurred + pd.Timedelta(hours=hours)
        day = (occurred + IST).date()
        task = {"id": len(tasks) + 1, "mine_id": int(i.mine_id), "obligation_id": int(ob_id[i.obligation_code]),
                "period": f"INC-{int(i.id):06d}", "period_start": day, "period_end": day, "due_at": due,
                "due_basis": basis, "status": "open", "escalation_level": 0, "accepted_at": None,
                "created_at": occurred, "incident_id": int(i.id)}
        if reported <= ctx.as_of:
            task["status"], task["accepted_at"] = "accepted", reported
        elif due <= ctx.as_of:
            late = ctx.as_of - due
            task["status"], task["escalation_level"] = ("escalated", 2) if late > esc_after else ("overdue", 1)
        tasks.append(task)

    ctx.emit("obligation_applicability", pd.DataFrame(app).sort_values(["mine_id", "obligation_id"]).assign(
        id=lambda d: range(1, len(d) + 1))[["id", "mine_id", "obligation_id", "basis"]])
    ctx.emit("obligation_task", pd.DataFrame(tasks))
    ctx.emit("obligation_submission", pd.DataFrame(subs))
    if new_files:
        nf = pd.DataFrame(new_files)
        nf["created_at"] = pd.to_datetime(nf["created_at"], utc=True)
        ctx.tables["file"] = pd.concat([files, nf], ignore_index=True)
    t = pd.DataFrame(tasks)
    ctx.notes["obligation_summary"] = {"tasks": len(tasks), "submissions": len(subs),
                                       "by_status": t["status"].value_counts().to_dict() if len(t) else {}}
