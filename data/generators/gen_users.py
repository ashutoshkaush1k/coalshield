"""user - the existing demo accounts, one corporate account per company, and inspectors.

Existing accounts are kept exactly (backend/data/seed/users.json): gov@dgms.gov.in and
head.<code>@coalmine.in per mine, password demo123. The brief adds corporate.secl@coalmine.in;
the same pattern is used for every company. Inspectors are fictitious people on the reserved
.example domain (RFC 2606), so no real address is implied.
"""

from __future__ import annotations

import pandas as pd
from faker import Faker

from common import Ctx

DEMO_PASSWORD = "demo123"   # the repo's demo password (backend/data/seed/users.json); demo only


def run(ctx: Ctx) -> None:
    sub_id, area_id = ctx.notes["subsidiary_id"], ctx.notes["area_id"]
    created = pd.Timestamp(ctx.start, tz="UTC") - pd.Timedelta(days=30)
    rows = [{"email": "gov@dgms.gov.in", "full_name": "DGMS Compliance Authority", "role": "government",
             "subsidiary_id": None, "area_id": None, "mine_id": None, "preferred_language": "en"}]
    for m in ctx.mines.itertuples():
        rows.append({"email": m.head_email, "full_name": f"Mine Head - {m.name}", "role": "mine_head",
                     "subsidiary_id": sub_id[m.company_id], "area_id": area_id.get(m.area_id) if isinstance(m.area_id, str) else None,
                     "mine_id": m.id, "preferred_language": m.language})
    for code, sid in sub_id.items():
        rows.append({"email": f"corporate.{code.lower()}@coalmine.in", "full_name": f"Corporate Office - {code}",
                     "role": "corporate", "subsidiary_id": sid, "area_id": None, "mine_id": None,
                     "preferred_language": "en"})
    fake = Faker("en_IN")
    fake.seed_instance(ctx.seed)
    n_insp = 2 if ctx.scale.get("mines") == "named" else 8
    for i in range(1, n_insp + 1):
        rows.append({"email": f"inspector.{i:02d}@dgms.example", "full_name": f"{fake.first_name()} {fake.last_name()}",
                     "role": "inspector", "subsidiary_id": None, "area_id": None, "mine_id": None,
                     "preferred_language": "en"})
    users = pd.DataFrame(rows)
    users.insert(0, "id", range(1, len(users) + 1))
    users["password"] = DEMO_PASSWORD
    users["status"] = "active"
    users["created_at"] = created
    users["updated_at"] = created
    ctx.emit("user", users[["id", "email", "password", "full_name", "role", "subsidiary_id", "area_id", "mine_id",
                            "preferred_language", "status", "created_at", "updated_at"]])
    ctx.notes["head_of"] = dict(zip(users.loc[users.role == "mine_head", "mine_id"].astype(int),
                                    users.loc[users.role == "mine_head", "id"]))
    ctx.notes["gov_id"] = 1
    ctx.notes["corporate_ids"] = users.loc[users.role == "corporate", "id"].tolist()
    ctx.notes["inspector_ids"] = users.loc[users.role == "inspector", "id"].tolist()
