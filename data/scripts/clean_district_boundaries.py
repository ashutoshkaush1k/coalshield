"""Stage D3: reference/district_crosswalk.csv and reference/district_boundaries.geojson.

The seed's 38 (district, state) pairs are tied to a Census 2011 district polygon (DataMeet, S03)
and, where it differs, to the current district (Wikidata, S04). Every link is made from data in
data/raw, and the evidence is written next to it. Order of methods, first that succeeds:

  1. same         - the name is a 2011 district of the same state (DataMeet).
  2. respelled    - a Wikidata district of that name has a coordinate inside a 2011 district of the
                    same state whose name is a spelling variant (Angul ~ Anugul); fuzzy >= 80.
  3. split        - a Wikidata district of that name has a coordinate inside a 2011 district with a
                    different name (Mancherial -> Adilabad): the district was created after 2011.
  4. not_a_district - the name is in neither list (a town or coalfield: Asansol, Raniganj,
                    Ramagundam). Its district is taken from, in order:
                    a. the SCCL areas page, when an area of that name is in a named district
                       (reference/areas.csv);
                    b. GEM rows whose Location, else whose Coalfield, is that name, in the same
                       state - the district most of them give, if it is at least 2/3 of them
                       (spelling variants grouped, fuzzy >= 90);
                    then steps 1-3 run on that district.
  Anything else is written with method TODO-VERIFY and no polygon.

Point for a mine placed at "district level" (used by clean_mines.py):
  same / respelled   centroid of the 2011 polygon (computed in EPSG:7755, India LCC).
  split              the current district's Wikidata coordinate - the 2011 polygon is the old,
                     larger district, and its centroid can fall outside the new one.
Both are rounded to 2 decimal places (about 1 km), per the mine-location rule in DATASETS.md.
"""

from __future__ import annotations

import csv
import json
import re
import sys
from collections import Counter

import geopandas as gpd
import pandas as pd
from rapidfuzz import fuzz
from shapely.geometry import Point, mapping

from common import DATA, sha256_file
from district_names import datameet, norm, norm_state, resolve, wikidata_rows

SEED = DATA / "reference/mines_base.csv"
AREAS = DATA / "reference/areas.csv"
GEM = DATA / "raw/gem_gcmt/Global Coal Mine Tracker, August 2026.xlsx"
OUT_CSV = DATA / "reference/district_crosswalk.csv"
OUT_GEO = DATA / "reference/district_boundaries.geojson"
LCC = "EPSG:7755"
SIMPLIFY_DEG = 0.005   # about 500 m; enough for a district outline on a dashboard map
COLUMNS = ["seed_district", "seed_state", "mines", "relation", "current_district", "current_district_qid",
           "district_2011", "state_2011", "censuscode_2011", "point_lon", "point_lat", "point_method",
           "method", "evidence"]


def gem_india() -> pd.DataFrame:
    if not GEM.exists():
        return pd.DataFrame()
    frames = [pd.read_excel(GEM, sheet_name=s) for s in ("Non-closed mines", "Closed mines")]
    g = pd.concat(frames, ignore_index=True)
    return g[g["Country / Area"] == "India"]


def wikidata_point(label: str, state: str) -> tuple[str, Point | None]:
    """(qid, point) for a Wikidata district label; several coordinate statements -> lowest (qid, coord)."""
    rows = sorted((r["qid"], r["coord"]) for r in wikidata_rows()
                  if r["label"] == label and re.match(r"Point\(", r["coord"] or ""))
    if not rows:
        return "", None
    qid, coord = rows[0]
    lon, lat = map(float, re.findall(r"[-\d.]+", coord)[:2])
    return qid, Point(lon, lat)


def majority_district(values: list[str]) -> tuple[str, int, int]:
    """Most common value after grouping spelling variants (fuzzy >= 90); (value, count, total)."""
    vals = [v for v in values if isinstance(v, str) and v.strip()]
    groups: list[list[str]] = []
    for v in sorted(vals, key=lambda x: -Counter(vals)[x]):
        for grp in groups:
            if fuzz.ratio(norm(v), norm(grp[0])) >= 90:
                grp.append(v)
                break
        else:
            groups.append([v])
    if not groups:
        return "", 0, 0
    best = max(groups, key=len)
    return Counter(best).most_common(1)[0][0], len(best), len(vals)


def locate(name: str, state: str, dm: gpd.GeoDataFrame) -> dict:
    """Steps 1-3 for a district name. Returns the crosswalk fields, or {} if not a known district."""
    same = dm[(dm["_st"] == norm_state(state)) & (dm["_dn"] == norm(name))]
    if len(same) == 1:
        r = same.iloc[0]
        return {"relation": "same", "current_district": name, "current_district_qid": wikidata_point(name, state)[0],
                "idx": same.index[0], "method": "exact name in DataMeet 2011, same state",
                "evidence": f"DataMeet 2011_Dist: DISTRICT='{r['DISTRICT']}', ST_NM='{r['ST_NM']}'"}
    label, st, how = resolve(name)
    if not label:
        return {}
    qid, pt = wikidata_point(label, st)
    if pt is None:
        return {}
    inside = dm[dm.contains(pt)]
    if len(inside) != 1:
        return {}
    r = inside.iloc[0]
    # Telangana did not exist in 2011: its districts lie in Andhra Pradesh polygons.
    state_ok = r["_st"] == norm_state(state) or (norm_state(state) == "Telangana" and r["_st"] == "Andhra Pradesh")
    if not state_ok:
        return {}
    score = fuzz.ratio(norm(label), r["_dn"])
    rel = "respelled" if score >= 80 else "split"
    return {"relation": rel, "current_district": label, "current_district_qid": qid, "idx": inside.index[0], "pt": pt,
            "method": f"Wikidata district coordinate inside a 2011 polygon ({'name similarity ' + format(score, '.0f') if rel == 'respelled' else 'different name: created after 2011'})",
            "evidence": f"Wikidata {qid} '{label}' {pt.wkt} ({how}) lies in DataMeet 2011 '{r['DISTRICT']}', {r['ST_NM']}"}


def main() -> int:
    for f in (SEED, AREAS):
        if not f.exists():
            print(f"ERROR: {f.relative_to(DATA)} missing - run clean_areas.py / run_data.bat setup first", file=sys.stderr)
            return 1
    dm = datameet().copy()
    dm["_st"] = dm["ST_NM"].map(norm_state)
    dm["_dn"] = dm["DISTRICT"].map(norm)
    dm_lcc = dm.to_crs(LCC)
    gem = gem_india()
    areas = list(csv.DictReader(AREAS.open(encoding="utf-8")))

    seed = list(csv.DictReader(SEED.open(encoding="utf-8")))
    pairs = sorted({(m["district"], m["state"]) for m in seed})
    rows, used = [], {}
    for d, s in pairs:
        codes = sorted(m["code"] for m in seed if (m["district"], m["state"]) == (d, s))
        res, prefix = locate(d, s, dm), ""
        if not res:   # step 4: not a district - find the district it belongs to
            via, ev = "", ""
            a = [x for x in areas if x["state"] == s and x["district"] and norm(x["area_name"]).startswith(norm(d))]
            if a and len({x["district"] for x in a}) == 1:
                via = a[0]["district"]
                ev = (f"SCCL areas page ({a[0]['source_file']}): " + "; ".join(x["source_text"] for x in a))
            elif not gem.empty:
                for col in ("Location", "Coalfield"):
                    g = gem[(gem["State, Province"] == s) & (gem[col].astype(str).str.fullmatch(re.escape(d), case=False))]
                    g = g.drop_duplicates("GEM Mine ID")
                    if len(g):
                        best, n, tot = majority_district(g["Prefecture, District"].tolist())
                        ids = ", ".join(sorted(g["GEM Mine ID"]))
                        if tot and n * 3 >= tot * 2:
                            via = best
                            ev = (f"GEM Global Coal Mine Tracker Aug 2026: {tot} {s} mines with {col} = '{d}' "
                                  f"({ids}); {n} of {tot} give 'Prefecture, District' = '{best}' or a spelling variant")
                        else:
                            ev = f"GEM rows with {col} = '{d}' ({ids}) do not agree on a district ({n} of {tot})"
                        break
            if via:
                res = locate(via, s, dm)
                prefix = f"'{d}' is not a district in DataMeet 2011 or Wikidata; its district '{via}' is from {ev}. Then: "
            if res:
                res["relation"] = "not_a_district -> " + res["relation"]
            else:
                rows.append({"seed_district": d, "seed_state": s, "mines": " ".join(codes), "relation": "unresolved",
                             "method": "TODO-VERIFY", "evidence": ev or "in no district list, SCCL area or GEM Location/Coalfield"})
                continue
        r = dm.loc[res["idx"]]
        if res["relation"].endswith("split"):
            pt, how = res["pt"], f"Wikidata coordinate of {res['current_district']} ({res['current_district_qid']})"
        else:
            c = gpd.GeoSeries([dm_lcc.loc[res["idx"]].geometry], crs=LCC).centroid.to_crs("EPSG:4326").iloc[0]
            pt, how = c, f"centroid of the 2011 polygon of {r['DISTRICT']}"
        rows.append({"seed_district": d, "seed_state": s, "mines": " ".join(codes), "relation": res["relation"],
                     "current_district": res["current_district"], "current_district_qid": res["current_district_qid"],
                     "district_2011": r["DISTRICT"], "state_2011": r["ST_NM"], "censuscode_2011": int(r["censuscode"]),
                     "point_lon": f"{pt.x:.2f}", "point_lat": f"{pt.y:.2f}", "point_method": how,
                     "method": res["method"], "evidence": prefix + res["evidence"]})
        used.setdefault(res["idx"], []).append(d)

    OUT_CSV.parent.mkdir(parents=True, exist_ok=True)
    with OUT_CSV.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)

    feats = []
    for idx in sorted(used, key=lambda i: int(dm.loc[i, "censuscode"])):
        r = dm.loc[idx]
        geom = r.geometry.simplify(SIMPLIFY_DEG, preserve_topology=True)
        feats.append({"type": "Feature", "properties": {
            "district_2011": r["DISTRICT"], "state_2011": r["ST_NM"], "censuscode_2011": int(r["censuscode"]),
            "seed_districts": sorted(used[idx])}, "geometry": mapping(geom)})
    geo = {"type": "FeatureCollection",
           "name": "Census 2011 districts containing the 74 seed mines",
           "attribution": "DataMeet India, Census 2011 district boundaries (CC BY 2.5 IN), "
                          "https://github.com/datameet/maps; simplified to 0.005 degrees",
           "features": feats}
    txt = json.dumps(geo, separators=(",", ":"), ensure_ascii=False)
    txt = re.sub(r"(-?\d+\.\d{5})\d+", r"\1", txt)   # 5 dp (about 1 m) is below the simplification error
    OUT_GEO.write_text(txt + "\n", encoding="utf-8")

    rel = Counter(r["relation"] for r in rows)
    print(f"Wrote reference/district_crosswalk.csv: {len(rows)} seed districts {dict(rel)}")
    print(f"Wrote reference/district_boundaries.geojson: {len(feats)} polygons, "
          f"{OUT_GEO.stat().st_size / 1024:.0f} KB, sha256 {sha256_file(OUT_GEO)[:16]}")
    todo = [r["seed_district"] for r in rows if r["method"] == "TODO-VERIFY"]
    if todo:
        print(f"  TODO-VERIFY (no polygon; their mines get no location): {todo}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
