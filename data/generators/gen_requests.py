"""production_detail_request (+ response file rows) - regulator "Call for Detailed Report".

At least one of each status; exactly one `overdue` (dataset brief: "a few pending, submitted, one
overdue"). Due dates are 5-10 days after the request - chosen by the requester in the product, not
a legal period.
"""

from __future__ import annotations

import hashlib

import numpy as np
import pandas as pd

from common import Ctx

REASONS = ["Production above target on several days; shift-wise records requested.",
           "Dispatch and closing stock do not reconcile for the period; detailed entries requested.",
           "Breakdown hours unusually high; equipment-wise details requested.",
           "Output deviates from the 30-day average; supporting records requested."]


def run(ctx: Ctx) -> None:
    rng = ctx.rng("requests")
    n = int(ctx.scale["detail_requests"])
    as_of = ctx.as_of
    sub_of = dict(zip(ctx.tables["mine"]["id"], ctx.tables["mine"]["subsidiary_id"]))
    users = ctx.tables["user"]
    corp_of = dict(zip(users.loc[users.role == "corporate", "subsidiary_id"], users.loc[users.role == "corporate", "id"]))
    statuses = ["overdue", "pending", "submitted", "closed", "escalated"]
    statuses += list(rng.choice(["pending", "submitted", "closed"], size=max(0, n - 5), p=[0.3, 0.35, 0.35]))
    statuses = statuses[:n]
    files = ctx.notes.setdefault("files", [])
    rows = []
    mine_ids = ctx.mines["id"].to_numpy()
    for i, st in enumerate(statuses):
        mid = int(rng.choice(mine_ids))
        due_days = int(rng.integers(5, 11))
        # timeline built backwards from the request time, so nothing lands after the window
        if st == "pending":
            created = as_of - pd.Timedelta(days=float(rng.uniform(0.5, due_days - 1)))
        elif st in ("overdue", "escalated"):
            late = float(rng.uniform(1, 3)) if st == "overdue" else float(rng.uniform(4, 8))
            created = as_of - pd.Timedelta(days=due_days + late)
        else:
            created = as_of - pd.Timedelta(days=float(rng.uniform(due_days + 1, max(due_days + 2, ctx.days - 3))))
        window_start = pd.Timestamp(ctx.start)
        created = max(created, pd.Timestamp(window_start, tz="UTC") + pd.Timedelta(days=2))
        date_to = max(window_start, (created - pd.Timedelta(days=int(rng.integers(1, 4)))).tz_localize(None).normalize())
        date_from = max(window_start, date_to - pd.Timedelta(days=int(rng.integers(6, 20))))
        due = created + pd.Timedelta(days=due_days)
        req_by = ctx.notes["gov_id"] if rng.random() < 0.6 else corp_of[sub_of[mid]]
        row = {"id": i + 1, "mine_id": mid, "requested_by": req_by, "date_from": date_from.date(), "date_to": date_to.date(),
               "reason": REASONS[int(rng.integers(len(REASONS)))], "due_at": due, "status": st, "response_note": None,
               "response_file_id": None, "responded_by": None, "responded_at": None, "created_at": created}
        if st in ("submitted", "closed"):
            resp = created + (min(due, as_of) - created) * float(rng.uniform(0.3, 0.9))
            fid = len(files) + 1
            path = f"uploads/detail_requests/{i + 1}/response.pdf"
            files.append({"id": fid, "path": path, "mime": "application/pdf", "size": int(rng.integers(200, 2400)) * 1024,
                          "sha256": hashlib.sha256(path.encode()).hexdigest(), "uploaded_by": ctx.notes["head_of"][mid],
                          "entity": "production_detail_request", "entity_id": i + 1, "created_at": resp})
            row.update({"response_note": "Shift-wise registers and weighbridge summaries attached.", "response_file_id": fid,
                        "responded_by": ctx.notes["head_of"][mid], "responded_at": resp})
        rows.append(row)
    ctx.emit("production_detail_request", pd.DataFrame(rows))
    f = pd.DataFrame(files)
    f["created_at"] = pd.to_datetime(f["created_at"], utc=True)
    ctx.emit("file", f)
