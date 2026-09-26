"""Stage D3: reference/accident_causes_dgms.csv - fatal and serious accidents in Indian coal mines by
cause, 2013-2022.

Source (S07): DGMS, "Key Evaluation of Trends in Coal Mine Accidents" (raw/dgms/sanket0404_2024.pdf):
  Table 2.9  "Causewise trend of fatal accidents in coal mines"   (PDF page 33, printed page 19)
  Table 2.10 "Causewise trend of serious accidents in coal mines" (PDF page 34, printed page 20)
Each cell is "accidents(persons)": persons killed in Table 2.9; persons seriously injured in
Table 2.10, which per its note also counts serious injuries in fatal accidents.

The PDF text breaks numbers with stray spaces ("8(1 3)"), so spaces are removed before reading the
ten year cells. A label may sit on the lines above its values. Checks, all enforced: ten cells per
row; per year, the cause rows sum to TOTAL, and below ground + opencast + above ground = TOTAL.

The DGMS 2025 Bulletin (Jan-Jun comparisons) and the 2014 Annual Report (2010-2014) are not used:
the first covers part-years only, the second overlaps 2013-2014 and adds older years.
"""

from __future__ import annotations

import csv
import re
import sys

import pdfplumber

from common import DATA, sha256_file

SRC = "raw/dgms/sanket0404_2024.pdf"
TABLES = {"fatal": (33, "Table 2.9"), "serious": (34, "Table 2.10")}
YEARS = list(range(2013, 2023))
OUT = DATA / "reference/accident_causes_dgms.csv"
GROUPS = {  # DGMS cause group number -> name, as the tables print them
    "1": "Ground movement", "2": "Transportation machinery (winding in shaft)",
    "3": "Transportation machinery (other than winding in shaft)", "4": "Machinery other than transportation machinery",
    "5": "Explosives", "6": "Electricity", "7": "Gas, dust and other combustible material",
    "8": "Fall (other than falls of ground)", "9": "Other causes",
}
PLACES = {"BELOWGROUND": "below ground", "OPENCAST": "opencast", "ABOVEGROUND": "above ground"}
COLUMNS = ["year", "row_type", "cause_group", "cause", "fatal_accidents", "persons_killed", "serious_accidents",
           "persons_seriously_injured", "source_file", "source_pages", "source_tables"]


def read_table(page_no: int) -> list[tuple[str, str, list[tuple[int, int]]]]:
    """[(group, cause, [(accidents, persons) x 10])] in table order; group '' for totals/places.

    Group headers ("1. GROUND MOVEMENT") are upper case and may wrap over lines; a group without
    sub-causes has its values after the header. Sub-causes ("Fall of Roof") are title case and may
    also wrap ("Wheeled Trackless" / "Transp 22(24) ...")."""
    text = pdfplumber.open(DATA / SRC).pages[page_no - 1].extract_text()
    rows, group, header_open, pending = [], "", False, ""
    for line in text.splitlines():
        line = line.strip()
        cells = re.findall(r"(\d+)\((\d+)\)", line.replace(" ", ""))
        name = re.sub(r"[\d()\s]+$", "", line) if cells else line
        g = re.match(r"^(\d)\.\s*(.*)$", name)
        if not cells:
            if re.match(r"^(Cause|NOTE|Table|TABLE)", line) or re.fullmatch(r"[\d\s]+", line):
                continue
            if g:
                group, header_open, pending = g.group(1), True, ""
            elif not header_open or line != line.upper() or "T O T A L" in line:
                pending = (pending + " " + line).strip()
            continue
        if len(cells) != len(YEARS):
            raise ValueError(f"page {page_no}: {len(cells)} cells, expected {len(YEARS)}, in '{line}'")
        vals = [(int(a), int(b)) for a, b in cells]
        full = (pending + " " + (g.group(2) if g else name)).strip()
        compact = full.replace(" ", "").upper()
        if g:
            group = g.group(1)
        if compact.startswith("TOTAL"):
            rows.append(("", "TOTAL", vals))
        elif compact in PLACES:
            rows.append(("", PLACES[compact], vals))
        elif g or (header_open and full == full.upper()):
            rows.append((group, GROUPS[group], vals))   # a group without sub-causes
        else:
            rows.append((group, full, vals))
        header_open, pending = False, ""
    return rows


def main() -> int:
    if not (DATA / SRC).exists():
        print(f"ERROR: {SRC} missing - run data\\run_data.bat download", file=sys.stderr)
        return 1
    parsed = {kind: read_table(page) for kind, (page, _) in TABLES.items()}
    out = []
    for kind, rows in parsed.items():
        causes = [r for r in rows if r[0]]
        total = next(r for r in rows if r[1] == "TOTAL")
        places = [r for r in rows if r[1] in PLACES.values()]
        for i, y in enumerate(YEARS):
            for k in (0, 1):
                s = sum(r[2][i][k] for r in causes)
                if s != total[2][i][k]:
                    raise SystemExit(f"ERROR: {TABLES[kind][1]} {y}: causes sum to {s}, TOTAL says {total[2][i][k]}")
                sp = sum(r[2][i][k] for r in places)
                if len(places) == 3 and sp != total[2][i][k]:
                    raise SystemExit(f"ERROR: {TABLES[kind][1]} {y}: places sum to {sp}, TOTAL says {total[2][i][k]}")
    keys = []
    for kind in ("fatal", "serious"):
        for grp, cause, _ in parsed[kind]:
            if (grp, cause) not in keys:
                keys.append((grp, cause))
    for i, y in enumerate(YEARS):
        for grp, cause in keys:
            f = next((r[2][i] for r in parsed["fatal"] if (r[0], r[1]) == (grp, cause)), None)
            s = next((r[2][i] for r in parsed["serious"] if (r[0], r[1]) == (grp, cause)), None)
            out.append({"year": y,
                        "row_type": "total" if cause == "TOTAL" else "place" if cause in PLACES.values() else "cause",
                        "cause_group": f"{grp}. {GROUPS[grp]}" if grp else "", "cause": cause,
                        "fatal_accidents": "" if f is None else f[0], "persons_killed": "" if f is None else f[1],
                        "serious_accidents": "" if s is None else s[0],
                        "persons_seriously_injured": "" if s is None else s[1],
                        "source_file": SRC, "source_pages": "PDF p.33 (printed 19); PDF p.34 (printed 20)",
                        "source_tables": "Table 2.9 (fatal); Table 2.10 (serious)"})
    with OUT.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(out)
    print(f"Wrote reference/accident_causes_dgms.csv: {len(out)} rows ({len(keys)} causes/totals x {len(YEARS)} years, "
          f"2013-2022); sums checked; sha256 {sha256_file(OUT)[:16]}")
    for grp, cause in keys:
        print(f"    {grp or '-'} | {cause}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
