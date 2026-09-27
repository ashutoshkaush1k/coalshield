"""Stage D3: the outlines of the offline map (Phase 5B).

reference/state_boundaries.geojson - India's state and union-territory outlines.
  Source: DataMeet maps, States/Admin2 (S03; the repository's non-district data is CC BY 4.0 -
  data/SOURCES.md). 36 features, one per state / UT, EPSG:4326. Topology-preserving
  simplification in EPSG:7755 (India LCC) at SIMPLIFY_M metres, coordinates rounded to 3 decimals
  (about 100 m). Properties: `state` (DataMeet's ST_NM) and `mines` - how many mines of the real
  roster lie in the state.

reference/map_districts.geojson - the Census 2011 districts that contain a mine of the real roster
  (reference/mines_real.csv, the roster the generator uses), found by point in polygon.
  reference/district_boundaries.geojson is built earlier, from the prototype seed's district
  names, for clean_mines.py; it misses the districts of the real mines that replaced seed mines
  (Giridih, Ranchi, Chhindwara, ...), so the map uses this file. Source: DataMeet Districts
  2011_Dist (CC BY 2.5 IN). Simplified at DISTRICT_SIMPLIFY_M, 4 decimals (about 10 m).
  Properties: `district_2011`, `state_2011`, `censuscode_2011`, `mines` (the roster codes inside -
  the API limits the outlines to the districts of the mines in the user's scope).

Runs after clean_mines_real.py. Features are sorted and numbers formatted the same way every time,
so both files are byte-identical across runs.
"""

from __future__ import annotations

import json

import geopandas as gpd
import pandas as pd
from shapely.geometry import mapping

from common import DATA, sha256_file

SRC = DATA / "raw/datameet/States/Admin2.shp"
DISTRICTS = DATA / "raw/datameet/Districts/2011_Dist.shp"
ROSTER = DATA / "reference/mines_real.csv"
OUT = DATA / "reference/state_boundaries.geojson"
OUT_DISTRICTS = DATA / "reference/map_districts.geojson"
LCC = "EPSG:7755"
SIMPLIFY_M = 2500      # about 2.5 km; enough for state outlines on a national dashboard map
DECIMALS = 3
DISTRICT_SIMPLIFY_M = 300   # district outlines are seen closer (a mine head's map opens at zoom 9)
DISTRICT_DECIMALS = 4
NEAREST_M = 5000       # a mine point just outside every polygon (coastline, simplification) joins the nearest within 5 km


def rounded(geom: dict, decimals: int = DECIMALS) -> dict:
    def r(coords):
        if isinstance(coords[0], (int, float)):
            return [round(coords[0], decimals), round(coords[1], decimals)]
        return [r(c) for c in coords]
    return {"type": geom["type"], "coordinates": r(geom["coordinates"])}


def map_districts(roster: pd.DataFrame) -> int:
    """reference/map_districts.geojson: the 2011 districts containing a roster mine."""
    if not DISTRICTS.exists():
        raise SystemExit(f"{DISTRICTS} missing - run data\\run_data.bat download")
    dist = gpd.read_file(DISTRICTS).to_crs(LCC)
    pts = gpd.GeoDataFrame(roster[["code"]], geometry=gpd.points_from_xy(roster["lon"], roster["lat"]), crs="EPSG:4326").to_crs(LCC)
    joined = gpd.sjoin(pts, dist[["censuscode", "geometry"]], how="left", predicate="within")
    missing = joined[joined["censuscode"].isna()]
    if len(missing):
        near = gpd.sjoin_nearest(pts.loc[missing.index], dist[["censuscode", "geometry"]], how="left", max_distance=NEAREST_M)
        joined.loc[near.index, "censuscode"] = near["censuscode"]
    unplaced = sorted(joined.loc[joined["censuscode"].isna(), "code"])
    codes: dict[int, list[str]] = {}
    for row in joined.dropna(subset=["censuscode"]).itertuples():
        codes.setdefault(int(row.censuscode), []).append(row.code)
    used = dist[dist["censuscode"].astype(int).isin(codes)].copy()
    used["geometry"] = used.geometry.simplify(DISTRICT_SIMPLIFY_M, preserve_topology=True)
    used = used.to_crs("EPSG:4326")
    features = []
    for row in used.sort_values("censuscode").itertuples():
        cc = int(row.censuscode)
        features.append({"type": "Feature", "properties": {
            "district_2011": row.DISTRICT, "state_2011": row.ST_NM, "censuscode_2011": cc, "mines": sorted(codes[cc])},
            "geometry": rounded(mapping(row.geometry), DISTRICT_DECIMALS)})
    fc = {"type": "FeatureCollection",
          "source": "DataMeet maps, Districts/2011_Dist (https://github.com/datameet/maps), CC BY 2.5 IN; simplified",
          "features": features}
    OUT_DISTRICTS.write_text(json.dumps(fc, separators=(",", ":"), ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"wrote {OUT_DISTRICTS} - {len(features)} districts with {sum(map(len, codes.values()))} mines, "
          f"{OUT_DISTRICTS.stat().st_size / 1024:.0f} KB, sha256 {sha256_file(OUT_DISTRICTS)[:12]}")
    if unplaced:
        print(f"  roster mines in no district: {unplaced}")
        return 1
    return 0


def main() -> int:
    if not SRC.exists():
        raise SystemExit(f"{SRC} missing - run data\\run_data.bat download")
    states = gpd.read_file(SRC).to_crs(LCC)
    states["geometry"] = states.geometry.simplify(SIMPLIFY_M, preserve_topology=True)
    states = states.to_crs("EPSG:4326")
    roster = pd.read_csv(ROSTER)
    per_state = roster["state"].value_counts().to_dict()
    features = []
    for row in states.sort_values("ST_NM").itertuples():
        if row.geometry is None or row.geometry.is_empty:
            continue
        features.append({"type": "Feature", "properties": {"state": row.ST_NM, "mines": int(per_state.get(row.ST_NM, 0))},
                         "geometry": rounded(mapping(row.geometry))})
    fc = {"type": "FeatureCollection",
          "source": "DataMeet maps, States/Admin2 (https://github.com/datameet/maps), CC BY 4.0; simplified",
          "features": features}
    OUT.write_text(json.dumps(fc, separators=(",", ":"), ensure_ascii=False) + "\n", encoding="utf-8")
    unmatched = sorted(set(per_state) - {f["properties"]["state"] for f in features})
    print(f"wrote {OUT} - {len(features)} states/UTs, {OUT.stat().st_size / 1024:.0f} KB, sha256 {sha256_file(OUT)[:12]}")
    if unmatched:
        print(f"  roster states without an outline: {unmatched}")
        return 1
    return map_districts(roster)


if __name__ == "__main__":
    raise SystemExit(main())
