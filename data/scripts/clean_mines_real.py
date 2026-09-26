"""Stage D3: reference/mines_real.csv and reference/mine_code_mapping.csv - a roster of 74 real
operating Indian coal mines to replace the prototype's fictional seed (decision pending team
approval; reference/mines.csv stays as the fallback).

Every mine is a GEM Global Coal Mine Tracker Aug 2026 row (S02, CC BY 4.0). What is real and what
is demo:
  real  name, company, state, district, coordinates, type, capacity, production, workforce, coalfield
  demo  demo_score, demo_risk_level, seed_violations - carried over from the seed slot the mine
        replaces, so the demo board keeps its shape. They are NOT assessments of the real mine.

Selection
  Candidates  GEM India rows with Status "Operating", coordinates, and a first-listed owner that is
              a company in companies.csv (North Eastern Coalfields -> CIL: NEC has no row there,
              and the Ministry of Coal table sums it inside the CIL total). Dropped when:
              - GEM's production for the mine exceeds its company's annualised Ministry of Coal
                production (the larger of Apr-Aug FY27 and FY26, x 12/5; GEM's figure may be from
                an earlier year) - the two sources cannot both be right;
              - the GEM point lies in no 2011 district polygon.
  Ranking     GEM "Location Accuracy" Exact before Approximate, then capacity (unknown last),
              then GEM ID.
  Per company Seats in proportion to Apr-Aug 2026 production in the Ministry of Coal company-wise
              table (msg-Aug26.pdf p.1, "Production upto Aug, FY 27"), largest remainder, at least
              one seat per company in companies.csv. CIL (holding: its own row is NEC) and NLC (not
              in that table - lignite and captive) get one seat each; the other 72 are shared by
              production. A company never gets more seats than it has candidates.
  Type mix    Each company's underground seats (Underground or Underground & Surface) follow the
              share of such mines among its candidates, at least one when it has two or more seats
              and any underground candidate.
  Demo slots  The five named demo mines keep their code, login and score. Each gets the real mine
              of the seed operator in the named coalfield (GEM "Coalfield"): the mine D3 already
              matched to that seed mine if any (Gevra for CG-KRB-03), else the best-ranked one,
              preferring the seed mine's district.

Location
  district  The 2011 district polygon containing the GEM point, then the current district:
            GEM's own district text when it names that district, a respelling of it, or a district
            carved out of it (Wikidata coordinate inside the polygon); otherwise the 2011 name.
  state     GEM's state when it agrees with the polygon (Telangana = 2011 Andhra Pradesh);
            otherwise the polygon's state, noted.

Codes and logins
  Each seed slot (id 1-74) is paired with one real mine: the five demo slots first, then by same
  company and state, same company, same state, then the rest, each in id / rank order. The real
  mine takes the slot's id, demo score and seed_violations. Its code follows the seed format
  <state>-<first 3 letters of district>-<id>, with the seed's own state prefixes; the demo slots
  keep their seed codes. head_email follows the seed pattern head.<code lower-case>@coalmine.in.
"""

from __future__ import annotations

import csv
import math
import re
import sys
from collections import Counter

import pandas as pd
import pdfplumber
from rapidfuzz import fuzz

from common import DATA, sha256_file
from district_names import (district_2011_at, km_between, km_to_state, norm, norm_state, resolve,
                            resolve_in_state, same_2011_district, splits_of)

SEED = DATA / "reference/mines_base.csv"
MINES = DATA / "reference/mines.csv"
XWALK = DATA / "reference/district_crosswalk.csv"
COMPANIES = DATA / "reference/companies.csv"
AREAS = DATA / "reference/areas.csv"
GEM = DATA / "raw/gem_gcmt/Global Coal Mine Tracker, August 2026.xlsx"
MSG = DATA / "raw/moc/monthly/msg-Aug26.pdf"
MSG_CITE = "Ministry of Coal, Monthly Coal Statistics Aug'2026 (Provisional), p.1, Production upto Aug FY 27"
OUT = DATA / "reference/mines_real.csv"
OUT_MAP = DATA / "reference/mine_code_mapping.csv"
OUT_CAP = DATA / "reference/company_capacity.csv"
N = 74
DEMO = {  # seed code -> GEM coalfield named in the demo script
    "JH-DHN-01": "Jharia", "MP-SGR-02": "Singrauli", "CG-KRB-03": "Korba", "WB-RNG-04": "Raniganj", "OD-TLC-05": "Talcher",
}
MOC_ROWS = ["ECL", "BCCL", "CCL", "NCL", "WCL", "SECL", "MCL", "NEC", "CIL", "SCCL"]
UG = {"Underground", "Underground & Surface"}
TYPE = {"Surface": "opencast", "Underground": "underground", "Underground & Surface": "mixed"}
COLUMNS = ["id", "code", "name", "company_id", "area_id", "area_method", "state", "district", "district_basis",
           "district_method",
           "region", "coalfield", "lat", "lon", "location_quality", "gem_id", "gem_owner", "type", "coal_type",
           "capacity_mtpa", "production_mtpa", "production_year", "workforce", "workforce_accuracy", "status", "head_email", "demo_named",
           "demo_score", "demo_risk_level", "seed_violations", "score_note", "selection", "replaces_code"]
MAP_COLUMNS = ["old_code", "new_code", "old_name", "new_name", "reason"]


def company_key(name: str) -> str:
    s = re.sub(r"\[.*?\]", "", str(name or "")).lower().replace("&", " and ")
    return " ".join(w for w in re.findall(r"[a-z]+", s)
                    if w not in {"the", "ltd", "limited", "co", "company", "corp", "corporation", "pvt", "private"})


def moc_ytd(col: int = -3) -> dict[str, float]:
    """Production upto Aug (Mt) per row of page 1: FY 27 is the third-last number on each company
    line, FY 26 the second-last."""
    out = {}
    for line in pdfplumber.open(MSG).pages[0].extract_text().splitlines():
        m = re.match(r"^(?:\d+\s+)?(" + "|".join(MOC_ROWS) + r")\s+(.*)$", line.strip())
        if m:
            nums = re.findall(r"\d+\.\d+", m.group(2))
            out[m.group(1)] = float(nums[col])
    return out


def largest_remainder(weights: dict[str, float], seats: int, caps: dict[str, int]) -> dict[str, int]:
    """Hamilton (largest remainder) apportionment with a floor of 1 and a cap per company.

    Companies whose proportional share exceeds their cap are fixed at the cap and the rest is
    re-shared among the others; ties break on weight, then name, so the result is deterministic."""
    fixed: dict[str, int] = {}
    while True:
        free = {k: w for k, w in weights.items() if k not in fixed}
        left = seats - sum(fixed.values())
        ideal = {k: left * w / sum(free.values()) for k, w in free.items()}
        over = [k for k in free if ideal[k] > caps[k]]
        if not over:
            break
        fixed.update({k: caps[k] for k in over})
    alloc = {k: min(caps[k], max(1, math.floor(ideal[k]))) for k in free}
    order = sorted(free, key=lambda k: (-(ideal[k] - alloc[k]), -weights[k], k))
    short = left - sum(alloc.values())
    while short > 0:
        k = next(k for k in order if alloc[k] < caps[k])
        alloc[k] += 1
        short -= 1
        order.remove(k)
        order.append(k)
    while short < 0:   # the floor of 1 can overshoot
        k = min((k for k in free if alloc[k] > 1), key=lambda k: (ideal[k] - alloc[k], weights[k], k))
        alloc[k] -= 1
        short += 1
    return {**fixed, **alloc}


CONFLICT_KM = 20   # a GEM point this far outside the state GEM names is not trusted
ADJACENT_KM = 1    # the district GEM names must touch the 2011 district containing the point


def locate(r) -> dict:
    """State and district for a GEM row, from its point; {"drop": reason} if the row contradicts itself."""
    lon, lat = float(r["Longitude"]), float(r["Latitude"])
    row = district_2011_at(lon, lat)
    if row is None:
        return {"drop": "GEM point in no 2011 district"}
    gem_state = "" if pd.isna(r["State, Province"]) else str(r["State, Province"])
    poly_state = row["ST_NM"]
    ok_state = (fuzz.ratio(norm(gem_state), norm(poly_state)) >= 90
                or (norm_state(gem_state) == "Telangana" and norm_state(poly_state) == "Andhra Pradesh"))
    notes = []
    if not ok_state:
        km = km_to_state(gem_state, lon, lat) if gem_state else None
        if km is not None and km > CONFLICT_KM:
            return {"drop": f"GEM state '{gem_state}' but the point is {km:.0f} km outside it (in {poly_state})"}
        dist_txt = "" if km is None else f"{km:.1f} km "
        notes.append(f"GEM's state '{gem_state}' is {dist_txt}across the border; the point's state ({poly_state}) is used")
    state = norm_state(gem_state) if ok_state else norm_state(poly_state)
    text = "" if pd.isna(r.get("Prefecture, District")) else str(r["Prefecture, District"])
    parts = [p.strip() for p in re.split(r"[/,;]", text) if p.strip()]
    for part in parts:
        label = resolve_in_state(part, state)
        if label and same_2011_district(label, state, row):
            split = splits_of(row) if fuzz.ratio(norm(label), row["_dn"]) >= 80 else []
            if split:   # GEM uses the 2011 name of a district that has been split since
                return {"state": state, "district": row["DISTRICT"], "district_basis": "2011", "row": row,
                        "district_method": "; ".join([f"GEM district '{text}' is the 2011 district {row['DISTRICT']}; "
                                                      f"Wikidata districts inside it: {', '.join(split)} - so the "
                                                      f"current district is not determined"] + notes)}
            return {"state": state, "district": label, "district_basis": "current", "row": row,
                    "district_method": "; ".join([f"GEM district '{text}', consistent with the GEM point"] + notes)}
    named = []
    for part in parts:
        label = resolve_in_state(part, state)
        if label:
            km = km_between(label, state, row)
            if km is not None:
                named.append((km, label))
    if named and min(named)[0] > ADJACENT_KM:
        km, label = min(named)
        return {"drop": f"GEM names district '{text}' but the point lies in 2011 {row['DISTRICT']}, "
                        f"{km:.0f} km from {label} (not adjacent)"}
    if named:
        notes.append(f"GEM names '{text}', a district adjacent to the one containing the point "
                     f"(often the district it was carved from)")
    elif text:
        notes.append(f"GEM's district text '{text}' is not a district of {state} in the district lists")
    split = splits_of(row)
    if split:
        return {"state": state, "district": row["DISTRICT"], "district_basis": "2011", "row": row,
                "district_method": "; ".join([f"2011 district containing the GEM point ({row['DISTRICT']}); Wikidata "
                                              f"districts inside it: {', '.join(split)} - so the current district is "
                                              f"not determined"] + notes)}
    label, _, _ = resolve(row["DISTRICT"])
    return {"state": state, "district": label or row["DISTRICT"], "district_basis": "current", "row": row,
            "district_method": "; ".join([f"2011 district containing the GEM point ({row['DISTRICT']}), "
                                          f"no later district inside it"] + notes)}


def area_for(c: dict, areas: list[dict]) -> tuple[str, str]:
    """(area_id, method).
    1 name      the mine's name contains exactly one (the longest) area name of its company, and
                that area's published district, if any, is consistent with the mine's point;
    2 district  the only area of its company whose published district is the mine's current district."""
    own = [a for a in areas if a["company_id"] == c["cid"]]
    name = norm(c["r"]["Mine Name"])
    core = {a["area_id"]: norm(re.sub(r"\s+Projects?$", "", a["area_name"].removesuffix(" Area"))) for a in own}
    hits = [a for a in own if len(core[a["area_id"]]) >= 4 and core[a["area_id"]] in name]
    if hits:
        longest = max(len(core[a["area_id"]]) for a in hits)
        hits = [a for a in hits if len(core[a["area_id"]]) == longest]
    if len(hits) == 1:
        a = hits[0]
        if not a["district"] or same_2011_district(a["district"], a["state"], c["row"]):
            return a["area_id"], ("name: the mine's name contains the area name '" + a["area_name"] + "'; "
                                  + (f"the area's published district ({a['district']}) is consistent with the mine's point"
                                     if a["district"] else "the area page names no district"))
    if c["district_basis"] == "current":
        same = [a for a in own if a["district"] and a["district"] == c["district"]]
        if len(same) == 1:
            return same[0]["area_id"], f"district: the only {c['cid']} area whose published district is {c['district']}"
        if same:
            return "", f"{len(same)} {c['cid']} areas in {c['district']}: {' '.join(a['area_id'] for a in same)}"
    if c["district_basis"] == "2011":
        return "", "no area name in the mine's name, and its district is a 2011 district split since"
    return "", f"no {c['cid']} area name in the mine's name or with a published district of {c['district']}"


def main() -> int:
    for f in (SEED, MINES, XWALK, COMPANIES, AREAS, GEM, MSG):
        if not f.exists():
            print(f"ERROR: {f.relative_to(DATA)} missing", file=sys.stderr)
            return 1
    seed = list(csv.DictReader(SEED.open(encoding="utf-8")))
    assert len(seed) == N
    matched = {m["code"]: m["gem_id"] for m in csv.DictReader(MINES.open(encoding="utf-8")) if m["gem_id"]}
    xwalk = {(r["seed_district"], r["seed_state"]): r for r in csv.DictReader(XWALK.open(encoding="utf-8"))}
    companies = list(csv.DictReader(COMPANIES.open(encoding="utf-8")))
    by_key = {company_key(c["name"]): c["company_id"] for c in companies}
    by_key["north eastern coalfields"] = "CIL"
    seed_cid = {c["name"]: c["company_id"] for c in companies}
    areas = list(csv.DictReader(AREAS.open(encoding="utf-8")))
    prefix = {m["state"]: m["code"].split("-")[0] for m in seed}
    region = {m["state"]: m["region"] for m in seed}

    ytd, ytd26 = moc_ytd(-3), moc_ytd(-2)
    # GEM's production figure may be from an earlier year, so the larger of FY27 and FY26 is the bound
    annual = {cid: max(ytd[cid], ytd26[cid]) * 12 / 5 for cid in ["ECL", "BCCL", "CCL", "NCL", "WCL", "SECL", "MCL", "SCCL"]}
    annual["CIL"] = max(ytd["NEC"], ytd26["NEC"]) * 12 / 5   # CIL's own seat is NEC's

    g = pd.concat([pd.read_excel(GEM, sheet_name=s) for s in ("Non-closed mines", "Closed mines")], ignore_index=True)
    g = g[(g["Country / Area"] == "India") & (g["Status"] == "Operating")].dropna(subset=["Latitude", "Longitude"])
    # Every operating GEM mine per company (first-listed owner), for the D4 production split: a
    # roster mine's share of its company's output = its capacity / the company's operating capacity.
    ops = g.drop_duplicates("GEM Mine ID").copy()
    ops["cid"] = ops["Owners"].astype(str).str.split(";").str[0].map(lambda o: by_key.get(company_key(o)))
    ops = ops.dropna(subset=["cid"])
    # NLC's production basis in D4 is its coal (Talabira; Coal Directory Table 3.11), so its lignite
    # mines are left out. Not applied to other companies: GEM labels many CIL mines "Lignite".
    ops = ops[~((ops["cid"] == "NLC") & (ops["Coal Type"] == "Lignite"))]
    cap_rows = {}
    for cid, grp in ops.groupby("cid"):
        caps = pd.to_numeric(grp["Capacity (Mtpa)"], errors="coerce")
        med = float(caps.median()) if caps.notna().any() else float("nan")
        cap_rows[cid] = {"company_id": cid, "gem_operating_mines": int(len(grp)),
                         "gem_mines_capacity_known": int(caps.notna().sum()),
                         "gem_capacity_mtpa": round(float(caps.fillna(med).sum()), 3) if caps.notna().any() else ""}
    cands, dropped = [], []
    for _, r in g.sort_values("GEM Mine ID").iterrows():
        first_owner = str(r["Owners"]).split(";")[0]
        cid = by_key.get(company_key(first_owner))
        if not cid:
            continue
        prod = r.get("Production (Mtpa)")
        if cid in annual and pd.notna(prod) and float(prod) > annual[cid]:
            dropped.append(f"{r['GEM Mine ID']} {r['Mine Name']} ({cid}): GEM production {float(prod):g} Mt > "
                           f"{cid if cid != 'CIL' else 'NEC'} annualised {annual[cid]:.2f} Mt "
                           f"(Ministry of Coal msg-Aug26.pdf p.1, larger of Apr-Aug FY27 and FY26, x 12/5)")
            continue
        loc = locate(r)
        if "drop" in loc:
            dropped.append(f"{r['GEM Mine ID']} {r['Mine Name']} ({cid}): {loc['drop']}")
            continue
        if loc["state"] not in prefix:
            dropped.append(f"{r['GEM Mine ID']} {r['Mine Name']} ({cid}): state {loc['state']} has no seed code prefix")
            continue
        cap = r.get("Capacity (Mtpa)")
        cands.append({"cid": cid, "r": r, **loc, "exact": r["Location Accuracy"] == "Exact",
                      "cap": float(cap) if pd.notna(cap) else math.nan, "ug": r["Mine Type"] in UG})
    cands.sort(key=lambda c: (not c["exact"], math.isnan(c["cap"]), -(0 if math.isnan(c["cap"]) else c["cap"]),
                              c["r"]["GEM Mine ID"]))
    per_co = {c["company_id"]: [x for x in cands if x["cid"] == c["company_id"]] for c in companies}
    missing = [k for k, v in per_co.items() if not v]
    if missing:
        print(f"ERROR: no operating GEM candidate for {missing}", file=sys.stderr)
        return 1

    # seats
    weights = {cid: ytd[cid] for cid in ["ECL", "BCCL", "CCL", "NCL", "WCL", "SECL", "MCL", "SCCL"]}
    seats = largest_remainder(weights, N - 2, {k: len(per_co[k]) for k in weights})
    seats.update({"CIL": 1, "NLC": 1})

    # demo slots first
    chosen: dict[str, dict] = {}   # seed code -> candidate
    for code, field in DEMO.items():
        sm = next(m for m in seed if m["code"] == code)
        cid = seed_cid[sm["operator"]]
        pool = [c for c in per_co[cid] if str(c["r"]["Coalfield"]) == field]
        pick = next((c for c in pool if c["r"]["GEM Mine ID"] == matched.get(code)), None)
        why = f"already matched to {code} in reference/mines.csv" if pick else ""
        if not pick:
            xw = xwalk[(sm["district"], sm["state"])]
            same = [c for c in pool if c["district"] == xw["current_district"]]
            pick = (same or pool)[0]
            why = (f"best-ranked {cid} mine in the {field} coalfield" +
                   (f" and the seed district ({xw['current_district']})" if same else ""))
        pick["selection"] = f"demo slot {code}: {why}"
        chosen[code] = pick

    # the rest, per company, with a type mix
    picked_ids = {c["r"]["GEM Mine ID"] for c in chosen.values()}
    roster = list(chosen.values())
    for cid, n in sorted(seats.items()):
        have = [c for c in roster if c["cid"] == cid]
        pool = [c for c in per_co[cid] if c["r"]["GEM Mine ID"] not in picked_ids]
        ug_share = sum(c["ug"] for c in per_co[cid]) / len(per_co[cid])
        n_ug = round(n * ug_share)
        if n >= 2 and n_ug == 0 and any(c["ug"] for c in per_co[cid]):
            n_ug = 1
        n_ug = max(0, n_ug - sum(c["ug"] for c in have))
        n_sf = n - len(have) - n_ug
        ug = [c for c in pool if c["ug"]][:n_ug]
        sf = [c for c in pool if not c["ug"]][:max(0, n_sf)]
        taken = {c["r"]["GEM Mine ID"] for c in ug + sf}
        extra = [c for c in pool if c["r"]["GEM Mine ID"] not in taken][: max(0, n - len(have) - len(ug) - len(sf))]
        for c in ug + sf + extra:
            c["selection"] = (f"{cid}: {'underground' if c['ug'] else 'surface'} seat "
                              f"({seats[cid]} seats; ranked by location accuracy, then capacity)")
            roster.append(c)
            picked_ids.add(c["r"]["GEM Mine ID"])
    if len(roster) != N:
        print(f"ERROR: roster has {len(roster)} mines, not {N}", file=sys.stderr)
        return 1

    # pair seed slots with real mines
    slot_of: dict[int, dict] = {}
    for code, c in chosen.items():
        slot_of[int(next(m["id"] for m in seed if m["code"] == code))] = c
    demo_ids = {c["r"]["GEM Mine ID"] for c in slot_of.values()}
    free = [c for c in roster if c["r"]["GEM Mine ID"] not in demo_ids]
    rest_seed = [m for m in seed if int(m["id"]) not in slot_of]
    stages = [("same company and state", lambda m, c: seed_cid[m["operator"]] == c["cid"] and m["state"] == c["state"]),
              ("same company", lambda m, c: seed_cid[m["operator"]] == c["cid"]),
              ("same state", lambda m, c: m["state"] == c["state"]),
              ("remaining slot", lambda m, c: True)]
    reason: dict[int, str] = {}
    for label, ok in stages:
        for m in rest_seed:
            i = int(m["id"])
            if i in slot_of:
                continue
            k = next((k for k, c in enumerate(free) if ok(m, c)), None)
            if k is not None:
                slot_of[i], reason[i] = free.pop(k), label

    out, mapping = [], []
    for m in seed:
        i = int(m["id"])
        c = slot_of[i]
        r = c["r"]
        area_id, area_method = area_for(c, areas)
        a = next((a for a in areas if a["area_id"] == area_id), None)
        if a and a["district"] and c["district_basis"] == "2011":   # the area page names the current district
            c["district_method"] += (f"; current district {a['district']} from the {a['company_id']} area page "
                                     f"({a['area_id']}: '{a['district_as_published']}')")
            c["district"], c["district_basis"] = a["district"], "current"
        code = m["code"] if m["code"] in DEMO else f"{prefix[c['state']]}-{re.sub('[^A-Z]', '', c['district'].upper())[:3]}-{i:02d}"
        prod = r.get("Production (Mtpa)")
        out.append({
            "id": i, "code": code, "name": r["Mine Name"], "company_id": c["cid"],
            "area_id": area_id, "area_method": area_method,
            "state": c["state"], "district": c["district"], "district_basis": c["district_basis"],
            "district_method": c["district_method"],
            "region": region[c["state"]], "coalfield": "" if pd.isna(r["Coalfield"]) else r["Coalfield"],
            "lat": f"{float(r['Latitude']):.6f}", "lon": f"{float(r['Longitude']):.6f}",
            "location_quality": "exact_gem" if c["exact"] else "approx_gem", "gem_id": r["GEM Mine ID"],
            "gem_owner": str(r["Owners"]), "type": TYPE.get(r["Mine Type"], ""), "coal_type": r["Coal Type"],
            "capacity_mtpa": "" if math.isnan(c["cap"]) else f"{c['cap']:g}",
            "production_mtpa": "" if pd.isna(prod) else f"{float(prod):g}",
            "production_year": "" if pd.isna(r.get("Year of Production")) else str(r["Year of Production"]).split(".")[0],
            "workforce": "" if pd.isna(r.get("Workforce Size")) else str(int(float(r["Workforce Size"]))),
            "workforce_accuracy": "" if pd.isna(r.get("Workforce Accuracy")) else str(r["Workforce Accuracy"]),
            "status": "operating", "head_email": f"head.{code.lower()}@coalmine.in",
            "demo_named": m["demo_named"], "demo_score": m["demo_score"], "demo_risk_level": m["demo_risk_level"],
            "seed_violations": m["seed_violations"],
            "score_note": f"demo value carried from seed slot {m['code']}; not an assessment of the real mine",
            "selection": c["selection"], "replaces_code": m["code"]})
        mapping.append({"old_code": m["code"], "new_code": code, "old_name": m["name"], "new_name": r["Mine Name"],
                        "reason": (f"demo slot kept (code, login and score unchanged); {c['selection']}" if m["code"] in DEMO
                                   else f"slot replaced by a real {c['cid']} mine ({reason[i]}); score carried over")})

    codes = [o["code"] for o in out]
    assert len(set(codes)) == N, "duplicate codes"
    for path, cols, rows in ((OUT, COLUMNS, out), (OUT_MAP, MAP_COLUMNS, mapping)):
        with path.open("w", encoding="utf-8", newline="") as fh:
            w = csv.DictWriter(fh, fieldnames=cols, lineterminator="\n")
            w.writeheader()
            w.writerows(rows)

    for cid, row in cap_rows.items():
        rc = [float(o["capacity_mtpa"]) for o in out if o["company_id"] == cid and o["capacity_mtpa"] != ""]
        row["roster_mines"] = sum(o["company_id"] == cid for o in out)
        row["roster_capacity_mtpa"] = round(sum(rc), 3)
        row["roster_share_of_capacity"] = round(sum(rc) / row["gem_capacity_mtpa"], 4) if row["gem_capacity_mtpa"] not in ("", 0) else ""
        row["source"] = ("GEM Global Coal Mine Tracker Aug 2026, operating India rows by first-listed owner "
                         "(NLC: coal mines only); unknown capacities filled with the company median")
    with OUT_CAP.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=list(next(iter(cap_rows.values()))), lineterminator="\n")
        w.writeheader()
        w.writerows(sorted(cap_rows.values(), key=lambda r: r["company_id"]))

    per = Counter(o["company_id"] for o in out)
    bands = Counter(o["demo_risk_level"] for o in out)
    types = Counter(o["type"] for o in out)
    print(f"Wrote reference/mines_real.csv: {N} mines, sha256 {sha256_file(OUT)[:16]}")
    print(f"  per company {dict(sorted(per.items()))}")
    print(f"  bands {dict(bands)}; types {dict(types)}; location {dict(Counter(o['location_quality'] for o in out))}; "
          f"with area {sum(bool(o['area_id']) for o in out)}")
    print(f"  dropped candidates: {len(dropped)}")
    for d in dropped:
        print(f"    {d}")
    print(f"Wrote reference/mine_code_mapping.csv: {len(mapping)} rows, "
          f"{sum(r['old_code'] == r['new_code'] for r in mapping)} codes unchanged")
    return 0


if __name__ == "__main__":
    sys.exit(main())
