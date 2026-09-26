"""District names from the two downloaded district lists, for matching by data rather than memory.

  S03  DataMeet Census 2011 districts (raw/datameet/Districts/2011_Dist.dbf): DISTRICT, ST_NM.
       2011 boundaries, so districts created later are absent, and today's Telangana districts
       appear under Andhra Pradesh (the state did not exist yet).
  S04  Wikidata districts of India (raw/wikidata/districts_india.csv): current districts, their
       parent entity (a state, or often an administrative division), and "separated from".
State names are compared after '&' -> 'and'. A Wikidata district's state is its parent when the
parent is a state; when the parent is an administrative division (e.g. Angul -> "Northern
division"), the state is the 2011 state polygon that contains the district's Wikidata coordinate -
except Andhra Pradesh, which in 2011 still included Telangana, so that case stays unknown ("").
"""

from __future__ import annotations

import csv
import re
from functools import lru_cache

import geopandas as gpd
from rapidfuzz import fuzz
from shapely.geometry import Point

from common import DATA

DATAMEET = DATA / "raw/datameet/Districts/2011_Dist.shp"
WIKIDATA = DATA / "raw/wikidata/districts_india.csv"


def norm(name: str) -> str:
    """Lower-case letters only, without a trailing 'district'."""
    s = re.sub(r"\bdistrict\b", "", (name or "").lower())
    return re.sub(r"[^a-z]", "", s)


def norm_state(state: str) -> str:
    return " ".join((state or "").replace("&", "and").split())


@lru_cache(maxsize=1)
def datameet() -> gpd.GeoDataFrame:
    return gpd.read_file(DATAMEET)


@lru_cache(maxsize=1)
def wikidata_rows() -> list[dict]:
    if not WIKIDATA.exists():
        return []
    rows = []
    for r in csv.DictReader(WIKIDATA.open(encoding="utf-8-sig")):
        label = re.sub(r"\s+district$", "", r["districtLabel"].strip(), flags=re.I)
        if re.fullmatch(r"Q\d+", label):
            continue  # no English label on Wikidata - unusable for name matching
        r = dict(r)
        r["label"] = label
        r["qid"] = r["district"].rsplit("/", 1)[-1]
        rows.append(r)
    return rows


@lru_cache(maxsize=1)
def state_names() -> frozenset[str]:
    s = {norm_state(x) for x in datameet()["ST_NM"].unique()}
    # States named as a parent in Wikidata are states too (e.g. Telangana, absent from 2011).
    s |= {norm_state(r["parentLabel"]) for r in wikidata_rows()
          if norm_state(r["parentLabel"]) and not re.search(r"division|region|zone", r["parentLabel"], re.I)
          and sum(1 for x in wikidata_rows() if x["parentLabel"] == r["parentLabel"]) >= 2}
    return frozenset(s)


@lru_cache(maxsize=1)
def district_index() -> dict[tuple[str, str], str]:
    """(state, normalised name) -> display label, from both lists."""
    idx: dict[tuple[str, str], str] = {}
    for _, r in datameet().iterrows():
        idx[(norm_state(r["ST_NM"]), norm(r["DISTRICT"]))] = r["DISTRICT"]
    states = state_names()
    for r in wikidata_rows():
        st = norm_state(r["parentLabel"])
        idx[(st if st in states else coord_state(r["coord"]), norm(r["label"]))] = r["label"]
    return idx


@lru_cache(maxsize=1)
def _state_shapes():
    dm = datameet()
    return [(norm_state(st), g.unary_union) for st, g in dm.groupby("ST_NM").geometry]


def coord_state(coord: str) -> str:
    """2011 state containing a Wikidata 'Point(lon lat)', or "" (see module note on Andhra Pradesh)."""
    m = re.match(r"Point\(([-\d.]+) ([-\d.]+)\)", coord or "")
    if not m:
        return ""
    pt = Point(float(m.group(1)), float(m.group(2)))
    hit = [st for st, geom in _state_shapes() if geom.contains(pt)]
    return hit[0] if len(hit) == 1 and hit[0] != "Andhra Pradesh" else ""


@lru_cache(maxsize=1)
def wikidata_keys() -> frozenset[tuple[str, str]]:
    """(state, normalised name) keys that come from Wikidata (current districts)."""
    states = state_names()
    return frozenset((norm_state(r["parentLabel"]) if norm_state(r["parentLabel"]) in states else coord_state(r["coord"]),
                      norm(r["label"])) for r in wikidata_rows())


def lookup(name: str) -> list[tuple[str, str]]:
    """[(label, state)] for a district name: exact first, else a unique prefix match."""
    idx, n = district_index(), norm(name)
    hits = [(label, st) for (st, dn), label in idx.items() if dn == n]
    if not hits and len(n) >= 5:
        hits = [(label, st) for (st, dn), label in idx.items() if dn.startswith(n)]
    current = wikidata_keys()
    by_label: dict[str, str] = {}
    # The same district from both lists collapses to one answer. Wikidata's (current) state wins
    # over DataMeet's 2011 state - Khammam is in Telangana today, in Andhra Pradesh in 2011.
    for label, st in sorted(hits, key=lambda x: (x[1], norm(x[0])) in current, reverse=True):
        if label not in by_label or (st and not by_label[label]):
            by_label[label] = st
    return sorted(by_label.items())


def resolve(name: str, fuzzy_min: float = 88) -> tuple[str, str, str]:
    """(label, state, how) when the name identifies exactly one district, else ("", "", reason).

    Exact name, else unique prefix ("Jayashankar" -> "Jayashankar Bhupalpally"), else a unique
    spelling variant of a prefix ("Komaram Bheem" ~ "Kumaram Bheem Asifabad"), which is flagged."""
    hits = lookup(name)
    n = norm(name)
    if len(hits) == 1:
        label, st = hits[0]
        return label, st, "exact" if norm(label) == n else f"prefix of '{label}'"
    if hits:
        return "", "", f"ambiguous: {'; '.join(l for l, _ in hits)}"
    if len(n) >= 6:
        by_label: dict[str, tuple[str, float]] = {}
        for (st, dn), label in district_index().items():
            score = fuzz.ratio(n, dn[: len(n)])
            if score >= fuzzy_min and (label not in by_label or (st and not by_label[label][0])):
                by_label[label] = (st, score)
        if len(by_label) == 1:
            label, (st, score) = next(iter(by_label.items()))
            return label, st, f"fuzzy {score:.0f}: source spelling '{name}' ~ '{label}' (TODO-VERIFY)"
        if by_label:
            return "", "", f"ambiguous: {'; '.join(sorted(by_label))}"
    return "", "", "not in district lists (TODO-VERIFY)"


def wikidata_point(label: str, state: str) -> tuple[str, Point | None]:
    """(qid, point) for a Wikidata district label; several coordinate statements -> lowest (qid, coord)."""
    rows = sorted((r["qid"], r["coord"]) for r in wikidata_rows()
                  if r["label"] == label and re.match(r"Point\(", r["coord"] or ""))
    if not rows:
        return "", None
    qid, coord = rows[0]
    lon, lat = map(float, re.findall(r"[-\d.]+", coord)[:2])
    return qid, Point(lon, lat)


@lru_cache(maxsize=1)
def _dm_indexed():
    dm = datameet().copy()
    dm["_st"] = dm["ST_NM"].map(norm_state)
    dm["_dn"] = dm["DISTRICT"].map(norm)
    return dm


def district_2011_at(lon: float, lat: float):
    """The DataMeet 2011 district row containing a point, or None."""
    dm = _dm_indexed()
    hit = dm[dm.contains(Point(lon, lat))]
    return hit.iloc[0] if len(hit) == 1 else None


def same_2011_district(label: str, state: str, row) -> bool:
    """Is the named current district the 2011 district `row`, a respelling of it, or carved out of it?"""
    if norm(label) == row["_dn"] or fuzz.ratio(norm(label), row["_dn"]) >= 80:
        return True
    _, pt = wikidata_point(label, state)
    return pt is not None and row.geometry.contains(pt)


@lru_cache(maxsize=1)
def _dm_lcc():
    return _dm_indexed().to_crs("EPSG:7755")


@lru_cache(maxsize=1)
def _wikidata_points() -> list[tuple[str, Point]]:
    out = []
    for r in wikidata_rows():
        m = re.match(r"Point\(([-\d.]+) ([-\d.]+)\)", r["coord"] or "")
        if m:
            out.append((r["label"], Point(float(m.group(1)), float(m.group(2)))))
    return out


def splits_of(row) -> list[str]:
    """Wikidata districts with a different name whose coordinate lies in this 2011 polygon: the
    districts carved out of it after 2011 (or before 2011 but missing from DataMeet)."""
    return sorted({label for label, pt in _wikidata_points()
                   if fuzz.ratio(norm(label), row["_dn"]) < 80 and row.geometry.contains(pt)})


def _km(idx, lon: float, lat: float) -> float:
    p = gpd.GeoSeries([Point(lon, lat)], crs="EPSG:4326").to_crs("EPSG:7755").iloc[0]
    return float(_dm_lcc().loc[idx].distance(p).min()) / 1000


def resolve_in_state(name: str, state: str) -> str:
    """The district label for a name within one state (exact, prefix, or a unique spelling variant),
    or "" - so "Balrampur" in Chhattisgarh is not taken for Balrampur in Uttar Pradesh."""
    st = norm_state(state)
    ok = {st, ""} | ({"Andhra Pradesh"} if st == "Telangana" else set())
    hits = [label for label, s in lookup(name) if s in ok]
    if len(hits) == 1:
        return hits[0]
    if not hits and len(norm(name)) >= 6:
        n = norm(name)
        near = {label for (s, dn), label in district_index().items() if s in ok and fuzz.ratio(n, dn[: len(n)]) >= 88}
        if len(near) == 1:
            return near.pop()
    return ""


def km_between(label: str, state: str, row) -> float | None:
    """Distance (km) between a named district's 2011 polygon (its own, or the one containing its
    Wikidata coordinate) and a 2011 district row; 0 when they touch. None without a polygon."""
    dm = _dm_indexed()
    idx = dm.index[(dm["_dn"] == norm(label)) & (dm["_st"] == norm_state(state))]
    if not len(idx):
        _, pt = wikidata_point(label, state)
        if pt is None:
            return None
        idx = dm.index[dm.contains(pt)]
    if not len(idx):
        return None
    lcc = _dm_lcc()
    return float(lcc.loc[idx].distance(lcc.loc[row.name].geometry).min()) / 1000


def km_to_district(label: str, state: str, lon: float, lat: float) -> float | None:
    """Distance (km) from a point to the 2011 polygon of a named district (its own polygon, or the
    one containing its Wikidata coordinate); None if the district has no polygon."""
    dm = _dm_indexed()
    idx = dm.index[(dm["_dn"] == norm(label)) & (dm["_st"] == norm_state(state))]
    if not len(idx):
        _, pt = wikidata_point(label, state)
        if pt is None:
            return None
        idx = dm.index[dm.contains(pt)]
    return _km(idx, lon, lat) if len(idx) else None


def km_to_state(state: str, lon: float, lat: float) -> float | None:
    """Distance (km) from a point to a state's 2011 districts (Telangana -> Andhra Pradesh 2011)."""
    dm = _dm_indexed()
    st = "Andhra Pradesh" if norm_state(state) == "Telangana" else norm_state(state)
    idx = dm.index[dm["_st"].map(lambda s: fuzz.ratio(norm(s), norm(st)) >= 90)]
    return _km(idx, lon, lat) if len(idx) else None
