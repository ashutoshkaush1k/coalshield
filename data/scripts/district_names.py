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


def lookup(name: str) -> list[tuple[str, str]]:
    """[(label, state)] for a district name: exact first, else a unique prefix match."""
    idx, n = district_index(), norm(name)
    hits = [(label, st) for (st, dn), label in idx.items() if dn == n]
    if not hits and len(n) >= 5:
        hits = [(label, st) for (st, dn), label in idx.items() if dn.startswith(n)]
    by_label: dict[str, str] = {}
    for label, st in hits:  # the same district from both lists collapses to one answer
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
