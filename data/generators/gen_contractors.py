"""contractor, contract, contract_worker, contractor_compliance_doc (+ their file rows).

Fictitious names only: contractor = a generic surname + a trade suffix; workers = Faker en_IN first
name + a regional surname from the mine's state (common surnames, no real people). Identifiers
(registration, licence, EPF, ESI, phone) are masked, e.g. REG-XXXX-0417, +91-XXXXX-X4821.

Legal periods (schema/rules.yaml, each citing a verified obligation):
  licence valid 5 years            LAB-02 (OSH Code 2020 s.48(3))
  VT refresher every 4 years       SAF-04 (OSH (Central) Rules 2026 r.159) -> vt_cert_valid_to
  medical examination annually     HLT-01 (r.109(1)) -> medical_exam_date older than 12 months = overdue
Product settings (not legal): documents for month P are due on day `contractor_doc_due_day` of
month P+1 (brief Phase 3: "due day configurable"); months not yet due are not generated.
Modelling shares: 8 % licences expired, 10 % expiring within 30 days, 6 % VT certificates expired,
7 % medicals overdue, 8 % monthly documents missing, 15 % unverified, 5 % of contracts over cap.
"""

from __future__ import annotations

import hashlib

import numpy as np
import pandas as pd
from faker import Faker

from common import Ctx

SUFFIX = ["Earthmovers", "Infra Projects", "Logistics", "Constructions", "Mining Services", "Security Services",
          "Enterprises", "Transport Company", "Engineering Works", "Minerals Handling"]
REGIONAL_SURNAMES = {
    "Jharkhand": ["Mahato", "Soren", "Munda", "Oraon", "Hembrom", "Kumar", "Prasad", "Yadav"],
    "West Bengal": ["Das", "Mondal", "Ghosh", "Bauri", "Majhi", "Roy", "Paul", "Bagdi"],
    "Odisha": ["Behera", "Nayak", "Sahu", "Pradhan", "Naik", "Barik", "Rout", "Majhi"],
    "Chhattisgarh": ["Sahu", "Yadav", "Verma", "Netam", "Kanwar", "Patel", "Dhruw", "Rathia"],
    "Madhya Pradesh": ["Singh", "Yadav", "Kushwaha", "Patel", "Baiga", "Kol", "Sahu", "Gond"],
    "Uttar Pradesh": ["Singh", "Yadav", "Kharwar", "Prasad", "Gupta", "Maurya", "Chauhan", "Pal"],
    "Maharashtra": ["Patil", "Deshmukh", "Pawar", "Jadhav", "Gond", "Kamble", "Shinde", "Wagh"],
    "Telangana": ["Reddy", "Rao", "Goud", "Naidu", "Yadav", "Chary", "Mudiraj", "Bhukya"],
    "Assam": ["Gogoi", "Bora", "Das", "Saikia", "Kalita", "Baruah", "Deka", "Hazarika"],
    "Jammu and Kashmir": ["Sharma", "Singh", "Gupta", "Bhat", "Raina", "Kumar", "Dogra", "Verma"],
}
WORK_TYPES = {"opencast": ["ob_removal", "ob_removal", "transport", "loading", "civil", "security", "other"],
              "underground": ["civil", "transport", "loading", "security", "other"],
              "mixed": ["ob_removal", "transport", "loading", "civil", "security", "other"]}
VALUE_INR = {"ob_removal": (50e7, 500e7), "transport": (5e7, 80e7), "loading": (5e7, 60e7), "civil": (1e7, 20e7),
             "security": (0.5e7, 5e7), "other": (0.2e7, 3e7)}
CAP = {"ob_removal": (60, 200), "transport": (30, 120), "loading": (25, 90), "civil": (15, 60), "security": (10, 40),
       "other": (8, 30)}
DOC_TYPES = ["wage_register", "epf_challan", "esi_challan"]


def masked(prefix: str, rng) -> str:
    return f"{prefix}{int(rng.integers(0, 10000)):04d}"


def run(ctx: Ctx) -> None:
    rng = ctx.rng("contractors")
    fake = Faker("en_IN")
    fake.seed_instance(ctx.seed + 1)
    sc, legal, prod = ctx.scale, ctx.rules["legal"], ctx.rules["product"]
    as_of = ctx.as_of.normalize().tz_localize(None)
    years_lic = legal["contractor_licence_validity_years"]["value"]
    years_vt = legal["vocational_refresher_years"]["value"]
    months_med = legal["medical_exam_interval_months"]["value"]

    # contractors
    n = int(sc["contractors"])
    kind = rng.choice(["valid", "expiring", "expired"], size=n, p=[0.82, 0.10, 0.08])
    rows = []
    used = set()
    for i in range(n):
        while True:
            name = f"{fake.last_name()} {SUFFIX[int(rng.integers(len(SUFFIX)))]}"
            if name not in used:
                used.add(name)
                break
        if kind[i] == "expired":
            valid_to = as_of - pd.Timedelta(days=int(rng.integers(5, 120)))
        elif kind[i] == "expiring":
            valid_to = as_of + pd.Timedelta(days=int(rng.integers(1, 31)))
        else:
            valid_to = as_of + pd.Timedelta(days=int(rng.integers(45, years_lic * 365 - 30)))
        status = "active" if kind[i] != "expired" else rng.choice(["active", "suspended"], p=[0.6, 0.4])
        rows.append({"id": i + 1, "name": name, "registration_no": masked("REG-XXXX-", rng),
                     "labour_licence_no": masked("LL/XXXX/", rng), "licence_valid_to": valid_to.date(),
                     "epf_code": masked("EPF-XXXXX-", rng), "esi_code": masked("ESI-XXXXX-", rng),
                     "contact": f"+91-XXXXX-X{int(rng.integers(0, 10000)):04d}", "status": status})
    contractor = pd.DataFrame(rows)
    blk = rng.choice(contractor.index, size=max(1, n // 40), replace=False)
    contractor.loc[blk, "status"] = "blacklisted"
    ctx.emit("contractor", contractor)
    eligible = contractor.loc[contractor["status"] != "blacklisted", "id"].to_numpy()

    # contracts
    crow = []
    for m in ctx.mines.itertuples():
        for k in range(int(sc["contracts_per_mine"])):
            wt = WORK_TYPES[m.type][int(rng.integers(len(WORK_TYPES[m.type])))]
            start = as_of - pd.Timedelta(days=int(rng.integers(ctx.days + 30, 3 * 365)))
            end = start + pd.Timedelta(days=int(rng.integers(365, 4 * 365)))
            lo, hi = VALUE_INR[wt]
            crow.append({"contractor_id": int(rng.choice(eligible)), "mine_id": m.id, "work_type": wt,
                         "work_order_no": f"WO/{m.code}/{start.year}/{len(crow) + 1:04d}",
                         "value": round(float(np.exp(rng.uniform(np.log(lo), np.log(hi)))), -3),
                         "start_date": start.date(), "end_date": end.date(),
                         "max_workers": int(rng.integers(*CAP[wt]))})
    contract = pd.DataFrame(crow)
    contract.insert(0, "id", range(1, len(contract) + 1))

    # workers
    lo, hi = sc["workers_per_contract"]
    state_of = dict(zip(ctx.mines["id"], ctx.mines["state"]))
    wrows = []
    over = set(rng.choice(contract["id"], size=max(1, len(contract) // 20), replace=False).tolist())
    for c in contract.itertuples():
        k = int(rng.integers(lo, hi + 1))
        surnames = REGIONAL_SURNAMES.get(state_of[c.mine_id], REGIONAL_SURNAMES["Jharkhand"])
        for j in range(k):
            vt_expired = rng.random() < 0.06
            last_vt = as_of - pd.Timedelta(days=int(rng.integers(years_vt * 365 + 1, years_vt * 365 + 400))) if vt_expired \
                else as_of - pd.Timedelta(days=int(rng.integers(0, years_vt * 365 - 10)))
            med_overdue = rng.random() < 0.07
            med = as_of - pd.Timedelta(days=int(rng.integers(months_med * 30 + 5, months_med * 30 + 180))) if med_overdue \
                else as_of - pd.Timedelta(days=int(rng.integers(0, months_med * 30 - 5)))
            wrows.append({"contract_id": c.id, "name": f"{fake.first_name()} {surnames[int(rng.integers(len(surnames)))]}",
                          "worker_code": f"CW{c.id:04d}{j + 1:03d}",
                          "vt_cert_valid_to": (last_vt + pd.DateOffset(years=years_vt)).date(),
                          "medical_exam_date": med.date(), "active": bool(rng.random() < 0.93)})
    worker = pd.DataFrame(wrows)
    worker.insert(0, "id", range(1, len(worker) + 1))
    active = worker[worker["active"]].groupby("contract_id").size()
    contract["max_workers"] = [
        max(1, int(active.get(cid, 0)) - int(rng.integers(1, 4))) if cid in over
        else max(mw, int(active.get(cid, 0)) + int(rng.integers(2, 12)))
        for cid, mw in zip(contract["id"], contract["max_workers"])]
    ctx.emit("contract", contract)
    ctx.emit("contract_worker", worker)

    # monthly documents (month P due on day D of month P+1); only months already due
    due_day = int(prod["contractor_doc_due_day"])
    head_of = ctx.notes["head_of"]
    periods = pd.period_range(pd.Timestamp(ctx.start) - pd.DateOffset(months=1), as_of, freq="M")
    drows, frows = [], []
    for c in contract.itertuples():
        for p in periods:
            due = (p + 1).to_timestamp() + pd.Timedelta(days=due_day - 1)
            if due > as_of or p.end_time < pd.Timestamp(c.start_date) or p.start_time > pd.Timestamp(c.end_date):
                continue
            for dt in DOC_TYPES:
                if rng.random() < 0.08:
                    continue                                  # missing
                up = due - pd.Timedelta(days=int(rng.integers(-6, 12)))  # a few uploaded late
                up = min(up + pd.Timedelta(hours=int(rng.integers(9, 18))), ctx.as_of.tz_localize(None))
                doc_id = len(drows) + 1
                fid = len(frows) + 1
                path = f"uploads/contractor_docs/{c.id}/{p}_{dt}.pdf"
                frows.append({"id": fid, "path": path, "mime": "application/pdf", "size": int(rng.integers(80, 900)) * 1024,
                              "sha256": hashlib.sha256(path.encode()).hexdigest(), "uploaded_by": head_of[c.mine_id],
                              "entity": "contractor_compliance_doc", "entity_id": doc_id,
                              "created_at": pd.Timestamp(up, tz="UTC")})
                ver = bool(rng.random() < 0.85)
                drows.append({"id": doc_id, "contract_id": c.id, "doc_type": dt, "period": str(p), "file_id": fid,
                              "verified": ver, "verified_by": head_of[c.mine_id] if ver else None})
    ctx.emit("contractor_compliance_doc", pd.DataFrame(drows))
    ctx.notes["files"] = frows
    ctx.notes["periods_due"] = [str(p) for p in periods if (p + 1).to_timestamp() + pd.Timedelta(days=due_day - 1) <= as_of]
