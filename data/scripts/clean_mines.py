"""Stage D3: reference/mines.csv - the 74 seed mines with company, area and location.

Follows the mine-location rule in DATASETS.md (decided 2026-09-25):
  - a mine matched with good confidence to a real mine gets that mine's coordinates;
  - every other mine is a demo mine (is_demo_mine = true) placed at district level, rounded to
    2 decimal places, so a fictitious mine never gets real-looking exact coordinates.

Matching (all inputs in data/raw; nothing from memory)
  The seed names are templates ("<District> <suffix>"), so the name's distinctive words are what
  is left after removing generic mining words, the district and the state. Each distinctive word
  is looked up in:
    1. GEM Global Coal Mine Tracker Aug 2026 (S02), India rows of the same state: Mine Name and
       Mine Name AKAs, word by word, fuzzy >= 90 (rapidfuzz ratio).
    2. Wikidata coal mines of India (S04), label word by word, same threshold - only if GEM gives
       no candidate.
  A word that is a GEM "Coalfield" value in the same state names a coalfield, not a mine; it is
  kept as evidence but cannot give a match on its own (Jharia, Talcher).

  match_confidence = name_score/100 x 1/candidates x owner_factor x district_factor
    owner_factor     1.0 if the seed operator is a GEM owner (or, for "Coal India Ltd", the GEM
                     parent company) of the candidate, else 0.5
    district_factor  1.0 if the candidate's GEM district is the mine's current district (from
                     reference/district_crosswalk.csv), 0.9 if GEM gives none, else 0.6
  Accepted when >= ACCEPT (0.85): a unique name hit whose owner and district both agree. Every
  candidate, accepted or not, is written to reference/mine_match_candidates.csv for review.

location_quality
  exact_gem          GEM coordinates, GEM "Location Accuracy" = Exact
  approx_gem         GEM coordinates, GEM "Location Accuracy" = Approximate (added in D3: the rule
                     names exact_gem only, and GEM marks 185 of its India rows approximate)
  wikidata           Wikidata coordinates
  district_centroid  the district point from reference/district_crosswalk.csv (see its method)
"""

from __future__ import annotations

import csv
import re
import sys

import pandas as pd
from rapidfuzz import fuzz

from common import DATA, sha256_file
from district_names import norm, norm_state

SEED = DATA / "reference/mines_base.csv"
COMPANIES = DATA / "reference/companies.csv"
AREAS = DATA / "reference/areas.csv"
XWALK = DATA / "reference/district_crosswalk.csv"
GEM = DATA / "raw/gem_gcmt/Global Coal Mine Tracker, August 2026.xlsx"
WIKI = DATA / "raw/wikidata/coal_mines_india.csv"
OUT = DATA / "reference/mines.csv"
OUT_CAND = DATA / "reference/mine_match_candidates.csv"

ACCEPT = 0.85
WORD_MIN = 90
GENERIC = {"block", "deep", "shaft", "washery", "expansion", "mine", "mines", "underground", "coalfield",
           "opencast", "colliery", "project", "unit", "coal"}
GEM_TYPE = {"Surface": "opencast", "Underground": "underground", "Underground & Surface": "mixed"}
NAME_TYPE = [("opencast", "opencast"), ("underground", "underground"), ("deep shaft", "underground")]
COLUMNS = ["id", "code", "name", "company_id", "area_id", "area_method", "state", "district", "current_district",
           "lat", "lon", "location_quality", "location_method", "is_demo_mine", "gem_id", "gem_name",
           "match_confidence", "match_note", "type", "type_source", "capacity_mtpa", "status",
           "operator_in_state_per_gem"]
CAND_COLUMNS = ["code", "word", "source", "candidate_id", "candidate_name", "candidate_state", "candidate_district",
                "candidate_owner", "name_score", "candidates_for_word", "owner_factor", "district_factor",
                "match_confidence", "accepted", "note"]


def company_key(name: str) -> str:
    s = re.sub(r"\[.*?\]", "", str(name or "")).lower().replace("&", " and ")
    words = [w for w in re.findall(r"[a-z]+", s) if w not in {"the", "ltd", "limited", "co", "company", "corp",
                                                               "corporation", "pvt", "private", "inc"}]
    return " ".join(words)


def owners(row) -> set[str]:
    return {company_key(o) for o in str(row.get("Owners", "")).split(";") if company_key(o)}


def load_gem() -> pd.DataFrame:
    if not GEM.exists():
        return pd.DataFrame()
    g = pd.concat([pd.read_excel(GEM, sheet_name=s).assign(_sheet=s) for s in ("Non-closed mines", "Closed mines")],
                  ignore_index=True)
    g = g[g["Country / Area"] == "India"].copy()
    g["_state"] = g["State, Province"].fillna("").map(norm_state)
    return g


def same_state(a: str, b: str) -> bool:
    return fuzz.ratio(norm_state(a).lower(), norm_state(b).lower()) >= 90   # GEM also spells "Chattisgarh"


def word_hit(word: str, text: str) -> float:
    return max((fuzz.ratio(word, w) for w in re.findall(r"[a-z]+", str(text).lower()) if len(w) >= 3), default=0)


def distinctive_words(m: dict) -> list[str]:
    drop = GENERIC | {norm(m["district"])} | {w.lower() for w in re.findall(r"[A-Za-z]+", m["state"])}
    return [w for w in re.findall(r"[a-z]+", m["name"].lower()) if w not in drop and len(w) >= 3]


def main() -> int:
    for f in (SEED, COMPANIES, AREAS, XWALK):
        if not f.exists():
            print(f"ERROR: {f.relative_to(DATA)} missing - run the earlier clean scripts first", file=sys.stderr)
            return 1
    seed = list(csv.DictReader(SEED.open(encoding="utf-8")))
    companies = {c["name"]: c["company_id"] for c in csv.DictReader(COMPANIES.open(encoding="utf-8"))}
    areas = list(csv.DictReader(AREAS.open(encoding="utf-8")))
    xwalk = {(r["seed_district"], r["seed_state"]): r for r in csv.DictReader(XWALK.open(encoding="utf-8"))}
    gem = load_gem()
    wiki = []
    if WIKI.exists():
        wiki = [r for r in csv.DictReader(WIKI.open(encoding="utf-8-sig"))
                if not re.fullmatch(r"Q\d+", r["mineLabel"]) and re.match(r"Point\(", r["coord"] or "")]
        wiki = list({r["mine"]: r for r in wiki}.values())   # one row per item

    out, cands = [], []
    for m in seed:
        cid = companies.get(m["operator"], "")
        xw = xwalk.get((m["district"], m["state"]), {})
        cur = xw.get("current_district", "")
        g_state = gem[gem["State, Province"].fillna("").map(lambda s: same_state(s, m["state"]))] if len(gem) else gem
        op_key = company_key(m["operator"])
        if gem.empty:
            op_note = "GEM not downloaded"
        elif not len(g_state):
            op_note = f"0 - GEM lists no mines in {m['state']}"
        else:
            if cid == "CIL":
                in_state = g_state["Parent Company"].fillna("").map(lambda p: "coal india" in company_key(p)).sum()
            else:
                in_state = g_state.apply(lambda r: op_key in owners(r), axis=1).sum()
            op_note = f"{in_state} GEM {m['state']} mines list {m['operator']} as {'parent' if cid == 'CIL' else 'owner'}"

        best = None
        words = distinctive_words(m)
        coalfields = {norm(c) for c in g_state["Coalfield"].dropna()} if len(g_state) else set()
        for w in words:
            hits = []
            if len(g_state):
                for gid, grp in g_state.groupby("GEM Mine ID", sort=True):
                    r = grp.sort_values("Status", key=lambda s: s.ne("Operating")).iloc[0]
                    score = max(word_hit(w, r["Mine Name"]), word_hit(w, r.get("Mine Name AKAs", "")))
                    if score >= WORD_MIN:
                        phases = "; ".join(f"{s} {c} Mtpa" for s, c in zip(grp["Status"].fillna("closed"), grp["Capacity (Mtpa)"]))
                        hits.append(("GEM", gid, r, score, phases))
            if not hits:
                for r in wiki:
                    score = word_hit(w, r["mineLabel"])
                    if score >= WORD_MIN and same_state(r.get("placeLabel", ""), m["state"]):
                        hits.append(("Wikidata", r["mine"].rsplit("/", 1)[-1], r, score, ""))
            is_cf = w in coalfields
            if not hits:
                cands.append({"code": m["code"], "word": w, "source": "GEM, Wikidata", "candidates_for_word": 0,
                              "match_confidence": 0, "accepted": "false",
                              "note": ("a GEM coalfield name in this state, " if is_cf else "") + "no mine of this name in the same state"})
                continue
            for src, hid, r, score, phases in hits:
                if src == "GEM":
                    own = ("coal india" in company_key(r.get("Parent Company", ""))) if cid == "CIL" else (op_key in owners(r))
                    gd = str(r.get("Prefecture, District") or "")
                    dist_f = 0.9 if not gd or gd == "nan" else (1.0 if cur and fuzz.ratio(norm(gd), norm(cur)) >= 90 else 0.6)
                    owner_txt, dist_txt, name_txt = str(r.get("Owners", "")), ("" if gd == "nan" else gd), r["Mine Name"]
                else:
                    own = company_key(r.get("operatorLabel", "")) == op_key or (cid == "CIL" and "coal india" in company_key(r.get("operatorLabel", "")))
                    dist_f, owner_txt, dist_txt, name_txt = 0.9, r.get("operatorLabel", ""), "", r["mineLabel"]
                conf = round(score / 100 / len(hits) * (1.0 if own else 0.5) * dist_f, 3)
                if is_cf:
                    conf = min(conf, 0.5)
                row = {"code": m["code"], "word": w, "source": src, "candidate_id": hid, "candidate_name": name_txt,
                       "candidate_state": m["state"], "candidate_district": dist_txt, "candidate_owner": owner_txt,
                       "name_score": f"{score:.0f}", "candidates_for_word": len(hits), "owner_factor": 1.0 if own else 0.5,
                       "district_factor": dist_f, "match_confidence": conf, "accepted": "false",
                       "note": "; ".join(x for x in [("word is a GEM coalfield name in this state - capped at 0.5" if is_cf else ""),
                                                     (f"GEM phases: {phases}" if phases and ";" in phases else "")] if x)}
                cands.append(row)
                if conf >= ACCEPT and (best is None or conf > best[0]):
                    best = (conf, src, hid, r, row)

        rec = {"id": m["id"], "code": m["code"], "name": m["name"], "company_id": cid, "state": m["state"],
               "district": m["district"], "current_district": cur, "operator_in_state_per_gem": op_note}
        if best:
            conf, src, hid, r, row = best
            row["accepted"] = "true"
            if src == "GEM":
                acc = str(r.get("Location Accuracy", ""))
                rec.update({"lat": f"{float(r['Latitude']):.6f}", "lon": f"{float(r['Longitude']):.6f}",
                            "location_quality": "exact_gem" if acc == "Exact" else "approx_gem",
                            "location_method": f"GEM {hid} coordinates (Location Accuracy: {acc})",
                            "gem_id": hid, "gem_name": r["Mine Name"],
                            "type": GEM_TYPE.get(r.get("Mine Type"), ""), "type_source": f"GEM Mine Type '{r.get('Mine Type')}'",
                            "capacity_mtpa": "" if pd.isna(r.get("Capacity (Mtpa)")) else f"{float(r['Capacity (Mtpa)']):g}",
                            "status": str(r.get("Status") or "").lower()})
            else:
                lon, lat = map(float, re.findall(r"[-\d.]+", r["coord"])[:2])
                rec.update({"lat": f"{lat:.6f}", "lon": f"{lon:.6f}", "location_quality": "wikidata",
                            "location_method": f"Wikidata {hid} coordinates", "gem_name": r["mineLabel"]})
            rec.update({"is_demo_mine": "false", "match_confidence": conf,
                        "match_note": f"'{row['word']}' -> {src} {hid} '{row['candidate_name']}' (name {row['name_score']}, "
                                      f"owner {'agrees' if row['owner_factor'] == 1.0 else 'differs'}, district factor {row['district_factor']})"})
        else:
            top = max((c for c in cands if c["code"] == m["code"]), key=lambda c: c["match_confidence"], default=None)
            rec.update({"lat": xw.get("point_lat", ""), "lon": xw.get("point_lon", ""),
                        "location_quality": "district_centroid" if xw.get("point_lat") else "",
                        "location_method": xw.get("point_method", "TODO-VERIFY: district not resolved"),
                        "is_demo_mine": "true", "match_confidence": top["match_confidence"] if top else 0,
                        "match_note": ("no distinctive word in the name" if not words else
                                       f"best candidate below {ACCEPT}: {top['candidate_name'] or '-'} ({top['note'] or top['source']})" if top and top["candidate_id"] else
                                       f"distinctive word(s) {words} found no mine")})
        if not rec.get("type"):
            kw = next((t for k, t in NAME_TYPE if k in m["name"].lower()), "")
            rec.update({"type": kw, "type_source": f"seed name contains '{next(k for k, t in NAME_TYPE if t == kw and k in m['name'].lower())}'" if kw else ""})

        own_areas = [a for a in areas if a["company_id"] == cid and a["district"] and a["district"] == cur]
        if len(own_areas) == 1:
            rec.update({"area_id": own_areas[0]["area_id"],
                        "area_method": f"the only {cid} area whose published district is {cur}"})
        else:
            rec.update({"area_id": "", "area_method": (
                f"{len(own_areas)} {cid} areas in {cur}: {' '.join(a['area_id'] for a in own_areas)}" if own_areas else
                "company publishes no areas (ECL/CCL/SECL skipped, NCL page empty, NLC by mines; CIL is the holding)"
                if cid not in {a["company_id"] for a in areas} else
                f"no {cid} area has a published district of {cur or '-'}")})
        out.append(rec)

    for path, cols, rows in ((OUT, COLUMNS, out), (OUT_CAND, CAND_COLUMNS, cands)):
        with path.open("w", encoding="utf-8", newline="") as fh:
            w = csv.DictWriter(fh, fieldnames=cols, lineterminator="\n", restval="")
            w.writeheader()
            w.writerows(rows)
    q = pd.Series([r["location_quality"] for r in out]).value_counts().to_dict()
    print(f"Wrote reference/mines.csv: {len(out)} mines, location_quality {q}, "
          f"with area_id {sum(bool(r['area_id']) for r in out)}, sha256 {sha256_file(OUT)[:16]}")
    print(f"Wrote reference/mine_match_candidates.csv: {len(cands)} rows, "
          f"{sum(c['accepted'] == 'true' for c in cands)} accepted")
    return 0


if __name__ == "__main__":
    sys.exit(main())
