"""Stage D3: reference/companies.csv - the operating companies of the 74 mines, and CIL as parent.

Every parent link and company type carries the source it came from; nothing is asserted from
memory. Sources:
  S06  Ministry of Coal, Monthly Coal Statistics Aug'2026 (Provisional), page 1: rows 1-8 (ECL,
       BCCL, CCL, NCL, WCL, SECL, MCL, NEC) are followed by a "CIL" row that equals their sum,
       and SCCL (row 9) is listed separately. The sum is re-checked here, not assumed.
  S05  The companies' own pages, where they describe themselves.
The seed's operator "Coal India Ltd" is kept as the company CIL itself (five mines); it is not
re-assigned to NEC or anyone else.
"""

from __future__ import annotations

import csv
import re
import sys

import html as h

import pdfplumber

from common import DATA, sha256_file

MSG = DATA / "raw/moc/monthly/msg-Aug26.pdf"
MSG_CITE = "Ministry of Coal, Monthly Coal Statistics Aug'2026 (Provisional), p.1 (raw/moc/monthly/msg-Aug26.pdf)"
OUT = DATA / "reference/companies.csv"

# seed operator name -> (company_id, short name)
SEED_OPERATORS = {
    "Coal India Ltd": "CIL", "Eastern Coalfields Ltd": "ECL", "Bharat Coking Coal Ltd": "BCCL",
    "Central Coalfields Ltd": "CCL", "Northern Coalfields Ltd": "NCL", "Western Coalfields Ltd": "WCL",
    "South Eastern Coalfields Ltd": "SECL", "Mahanadi Coalfields Ltd": "MCL",
    "Singareni Collieries Company Ltd": "SCCL", "NLC India Ltd": "NLC",
}
SELF_DESCRIPTIONS = {  # company_id -> (quote from its own page, file)
    "WCL": ("A Miniratna Company A Subsidiary of Coal India Limited", "raw/company_sites/wcl/wcl_areas_page.html"),
    "MCL": ("A Miniratna Subsidiary Company of Coal India Limited", "raw/company_sites/mcl/mcl_coalfields_areas_page.html"),
    "BCCL": ("Bharat Coking Coal Limited (A Mini Ratna Company)", "raw/company_sites/bccl/bccl_areas_page.html"),
    "NCL": ("A mini ratna company A Government of India Undertaking", "raw/company_sites/ncl/ncl_overview_projects.html"),
    "SCCL": ("The Singareni Collieries Company Limited (A Government Company)", "raw/company_sites/sccl/sccl_contact_page_lists_areas.html"),
}
OWNERSHIP = {  # company_id -> (sentence that must appear on the saved page, file, url)
    "SCCL": ("jointly owned by the Government of Telangana and Government of India on a 51:49 equity basis",
             "raw/company_sites/sccl/sccl_about_us_page_ownership.html", "https://scclmines.com/scclnew/company_about-us.asp"),
    "NLC": ("NLCIL is a Navratna Government of India Enterprise, under the administrative control of Ministry of Coal",
            "raw/company_sites/nlc/nlc_india_corporate_profile_ownership.html",
            "https://www.nlcindia.in/website/en/aboutus/corporateprofile.html"),
}
COLUMNS = ["company_id", "name", "parent_id", "type", "type_source", "self_description", "notes"]


def msg_table() -> dict[str, float]:
    """Monthly target column of page 1, keyed by company short name."""
    text = pdfplumber.open(MSG).pages[0].extract_text()
    targets = {}
    for line in text.splitlines():
        m = re.match(r"^(?:\d+\s+)?(ECL|BCCL|CCL|NCL|WCL|SECL|MCL|NEC|CIL|SCCL)\s+([\d.]+)", line.strip())
        if m:
            targets[m.group(1)] = float(m.group(2))
    return targets


def ownership(cid: str) -> str:
    """The ownership sentence, checked against the saved page; "" if the page or sentence is missing."""
    quote, rel, url = OWNERSHIP[cid]
    path = DATA / rel
    if not path.exists():
        return ""
    t = re.sub(r"(?is)<(script|style|noscript).*?</>", " ", path.read_text(encoding="utf-8", errors="ignore"))
    text = " ".join(h.unescape(re.sub(r"<[^>]+>", " ", t)).split())
    return f"\"{quote}\" ({url}; {rel})" if quote in text else ""


def main() -> int:
    for f in (MSG,):
        if not f.exists():
            print(f"ERROR: {f} missing - run data\\run_data.bat download first", file=sys.stderr)
            return 1
    t = msg_table()
    members = ["ECL", "BCCL", "CCL", "NCL", "WCL", "SECL", "MCL", "NEC"]
    missing = [c for c in members + ["CIL", "SCCL"] if c not in t]
    if missing:
        print(f"ERROR: could not read rows {missing} from {MSG_CITE}", file=sys.stderr)
        return 1
    total = round(sum(t[c] for c in members), 2)
    if abs(total - t["CIL"]) > 0.015:
        print(f"ERROR: CIL row {t['CIL']} != sum of rows 1-8 {total}; the parent link would be unsupported", file=sys.stderr)
        return 1
    grouping = (f"{MSG_CITE}: the CIL row (target {t['CIL']} Mt) equals the sum of rows ECL, BCCL, CCL, NCL, WCL, "
                f"SECL, MCL and NEC ({total} Mt); SCCL is listed separately, outside CIL")

    names = {v: k for k, v in SEED_OPERATORS.items()}
    rows = [{"company_id": "CIL", "name": names["CIL"], "parent_id": "", "type": "holding",
             "type_source": grouping, "self_description": "", "notes":
             "Also the seed operator of 5 mines, kept as CIL itself. NEC (North Eastern Coalfields) is a CIL row in "
             "the same table but is not a seed operator, so it is not listed as a company."}]
    for cid in ["ECL", "BCCL", "CCL", "NCL", "WCL", "SECL", "MCL"]:
        quote, file = SELF_DESCRIPTIONS.get(cid, ("", ""))
        rows.append({"company_id": cid, "name": names[cid], "parent_id": "CIL", "type": "subsidiary",
                     "type_source": grouping + (f"; and its own page: \"{quote}\" ({file})" if "Subsidiary" in quote else ""),
                     "self_description": f"\"{quote}\" ({file})" if quote else "", "notes": ""})
    q, f = SELF_DESCRIPTIONS["SCCL"]
    own = ownership("SCCL")
    rows.append({"company_id": "SCCL", "name": names["SCCL"], "parent_id": "", "type": "state_jv" if own else "psu",
                 "type_source": (f"Own about-us page: {own}; " if own else "") +
                                f"{MSG_CITE}: listed separately from CIL",
                 "self_description": f"\"{q}\" ({f})",
                 "notes": "" if own else "TODO-VERIFY: ownership page not downloaded; 'psu' reflects only its self-description."})
    own = ownership("NLC")
    rows.append({"company_id": "NLC", "name": names["NLC"], "parent_id": "", "type": "psu" if own else "TODO-VERIFY",
                 "type_source": (f"Own corporate profile: {own}" if own else
                                 "No downloaded source describes NLC India's ownership (run_data.bat download fetches it)."),
                 "self_description": own,
                 "notes": "Not a row in the Ministry of Coal coal-production table, which covers coal, not lignite."}
                )

    OUT.parent.mkdir(parents=True, exist_ok=True)
    with OUT.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    print(f"Wrote reference/companies.csv: {len(rows)} companies (CIL row {t['CIL']} = sum of 8 rows {total}) "
          f"sha256 {sha256_file(OUT)[:16]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
