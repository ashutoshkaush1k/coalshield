"""Stage D3: reference/areas.csv - operating areas of the coal companies, as each company publishes them.

Sources (S05), all saved pages:
  BCCL  raw/company_sites/bccl/bccl_areas_page.html        list of "X Area" names
  WCL   raw/company_sites/wcl/wcl_areas_page.html          area + office address
  MCL   raw/company_sites/mcl/mcl_coalfields_areas_page.html  area + General Manager
  SCCL  raw/company_sites/sccl/sccl_contact_page_lists_areas.html  "Area General Managers" table:
        area + "<district> Dist."
Not available: ECL, CCL, SECL (sites unreachable - skipped by the user), NCL (its saved page is a
JavaScript shell with no content), NLC India (organised by mines, not areas). The user asked for
the Coal Directory 2024-25 (S06) as a fallback for ECL/CCL/SECL; all 11 chapters were checked in
D3 and none has an area-wise table, so those companies have no areas here.

A district is recorded for an area only when the source names it:
  SCCL  explicitly ("Peddapalli Dist."), resolved against the district lists (exact, unique prefix,
        or a flagged spelling variant). The page numbers its rows 1-10, 12, 13 - there is no row 11,
        so SCCL has 12 areas. If the published district matches no district list ("Kothagudem
        Dist." for Sathupally), the district is taken from GEM rows of an SCCL mine named after the
        area's place (name or AKA), cited by GEM ID.
  WCL   when the office address contains the name of a district of the same state as listed in
        the DataMeet 2011 district file (S03) or Wikidata's district list (S04) - matched by data,
        not by memory. A one-letter misspelling in the source ("Chinndwara") is accepted as a fuzzy
        match and flagged. Town names that are not districts (Ballarpur, Kuchna, Welhala) give none.
BCCL and MCL pages name no districts.
"""

from __future__ import annotations

import csv
import html as h
import re
import sys
from pathlib import Path

import pandas as pd
from rapidfuzz import fuzz

from common import DATA, sha256_file
from district_names import district_index, norm, resolve

OUT = DATA / "reference/areas.csv"
GEM = DATA / "raw/gem_gcmt/Global Coal Mine Tracker, August 2026.xlsx"
PAGES = {
    "BCCL": ("raw/company_sites/bccl/bccl_areas_page.html", "https://bcclweb.in/?page_id=6102", "Jharkhand"),
    "WCL": ("raw/company_sites/wcl/wcl_areas_page.html", "https://www.westerncoal.in/en/areas", None),
    "MCL": ("raw/company_sites/mcl/mcl_coalfields_areas_page.html", "https://www.mahanadicoal.in/About/eareas.php", "Odisha"),
    "SCCL": ("raw/company_sites/sccl/sccl_contact_page_lists_areas.html", "https://scclmines.com/scclnew/contact-us.asp", None),
}
# Fuzzy district matches reviewed and accepted by the user (area_id -> date)
ACCEPTED = {"SCCL-BELLAMPALLI": "2026-09-26"}
NOT_AREAS = {"Block-E OCP", "CWS IB Valley"}   # a mine and a central workshop listed among the areas
COLUMNS = ["area_id", "company_id", "area_name", "district", "state", "district_as_published",
           "district_match", "source_url", "source_file", "source_sha256", "source_text"]


def page_text(path: Path) -> str:
    t = path.read_text(encoding="utf-8", errors="ignore")
    t = re.sub(r"(?is)<(script|style|noscript).*?</\1>", " ", t)
    return " ".join(h.unescape(re.sub(r"<[^>]+>", " | ", t)).split())


def area_id(company: str, name: str) -> str:
    return f"{company}-" + re.sub(r"[^A-Z0-9]+", "-", name.upper()).strip("-")


def district_in_address(address: str, index: dict, states: set[str]) -> tuple[str, str, str]:
    """(district, state, how) for the first district of the given states named in an address."""
    words = re.findall(r"[A-Za-z]+", address)
    for wd in words:
        for (state, dnorm), label in index.items():
            if state not in states or len(dnorm) < 4:
                continue
            if norm(wd) == dnorm:
                return label, state, "exact"
    for wd in words:
        for (state, dnorm), label in index.items():
            if state in states and len(dnorm) >= 6 and len(wd) >= 6:
                s = fuzz.ratio(norm(wd), dnorm)
                if s >= 88:
                    return label, state, f"fuzzy {s:.0f} (source spells it '{wd}')"
    return "", "", ""


def gem_district(place: str, owner: str) -> tuple[str, str]:
    """(district, evidence) from GEM rows of that owner whose name, AKAs or Location name the place.

    Used only when the company's own district name matches no district list. The GEM rows must all
    give the same 'Prefecture, District'."""
    if not GEM.exists():
        return "", ""
    g = pd.concat([pd.read_excel(GEM, sheet_name=s) for s in ("Non-closed mines", "Closed mines")])
    g = g[(g["Country / Area"] == "India") & g["Owners"].astype(str).str.contains(owner, case=False)]
    p = norm(place)
    hit = g[g.apply(lambda r: any(fuzz.ratio(p, norm(w)) >= 90 for col in ("Mine Name", "Mine Name AKAs", "Location")
                                  for w in re.split(r"[,;/]", str(r[col]))), axis=1)].drop_duplicates("GEM Mine ID")
    dists = set(hit["Prefecture, District"].dropna())
    if len(dists) != 1:
        return "", ""
    d = dists.pop()
    ids = "; ".join(f"{r['GEM Mine ID']} '{r['Mine Name']}' (AKAs: {r['Mine Name AKAs']})" for _, r in hit.iterrows())
    return d, f"GEM Global Coal Mine Tracker Aug 2026: {ids} - Prefecture, District = '{d}'"


def main() -> int:
    index = district_index()
    rows = []
    for company, (rel, url, default_state) in PAGES.items():
        path = DATA / rel
        if not path.exists():
            print(f"  skip {company}: {rel} not downloaded")
            continue
        t, sha = page_text(path), sha256_file(path)
        if company == "SCCL":
            for m in re.finditer(r"\|\s*([A-Z][A-Za-z\-]+(?:[ -][A-Za-z\-]+)?(?:-I{1,3})?) Area,\s*([A-Za-z ]+?)\s*Dist\.", t):
                name, dist = m.group(1).strip(), m.group(2).strip()
                label, state, how = resolve(dist)
                if not label:   # e.g. "Kothagudem Dist." - no district of that name; ask GEM where the place is
                    gd, ev = gem_district(name, "Singareni")
                    if gd:
                        label, state, _ = resolve(gd)
                        how = (f"published '{dist} Dist.' matches no district; district from {ev}" if label else how)
                rows.append({"company_id": company, "area_name": f"{name} Area", "district": label, "state": state,
                             "district_as_published": f"{dist} Dist.",
                             "district_match": how,
                             "source_text": m.group(0).strip(" |")})
        else:
            for m in re.finditer(r"([A-Z][A-Za-z\.\-]+(?: [A-Z][A-Za-z\.\-]+){0,2}) Area\b", t):
                name = m.group(1).strip()
                if f"{name} Area" in NOT_AREAS or name in NOT_AREAS:
                    continue
                if any(r["company_id"] == company and r["area_name"] == f"{name} Area" for r in rows):
                    continue   # MCL lists Jagannath Area twice
                label = state = how = published = ""
                if company == "WCL":
                    published = t[m.end(): m.end() + 90].split("| | |")[0].strip(" |")
                    label, state, how = district_in_address(published, index, {"Maharashtra", "Madhya Pradesh"})
                rows.append({"company_id": company, "area_name": f"{name} Area", "district": label,
                             "state": state or (default_state if default_state and not label else state),
                             "district_as_published": published, "district_match": how or ("" if company != "WCL" else "none named"),
                             "source_text": f"{name} Area" + (f" | {published}" if published else "")})
        for r in rows:
            if r["company_id"] == company:
                r.update({"source_url": url, "source_file": rel, "source_sha256": sha,
                          "area_id": area_id(company, r["area_name"].removesuffix(" Area"))})
                if r["area_id"] in ACCEPTED:
                    r["district_match"] = r["district_match"].replace("(TODO-VERIFY)", f"(accepted by the user {ACCEPTED[r['area_id']]})")

    rows.sort(key=lambda r: (r["company_id"], r["area_name"]))
    OUT.parent.mkdir(parents=True, exist_ok=True)
    with OUT.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    per = {c: sum(r["company_id"] == c for r in rows) for c in PAGES}
    print(f"Wrote reference/areas.csv: {len(rows)} areas {per}; with a district: {sum(bool(r['district']) for r in rows)}")
    return 0


if __name__ == "__main__":
    sys.path.insert(0, str(Path(__file__).parent))
    sys.exit(main())
