"""Stage D3: reference/legal_instruments.csv - the instruments the app cites, and their status on
2026-09-25, each backed by a passage the script finds in the downloaded official text.

Status values: in_force, repealed, superseded (rules replaced by new rules), saved (still in force
under a savings clause), draft, TODO-VERIFY. A row whose evidence passage is not found in its file
is written with status TODO-VERIFY and says so, instead of the status it would have had.

"In force" for the environmental Acts and the CMPF Act rests on absence of repeal: they are not in
the repeal lists of OSH Code s.143(1) or Code on Social Security s.164(1) (both quoted). Their own
texts are in the CPCB Pollution Control Law Series (2021) for the three environmental Acts.
"""

from __future__ import annotations

import csv
import sys

from common import DATA, sha256_file
from legal_text import find

OUT = DATA / "reference/legal_instruments.csv"
OSH = "raw/legal/codes/36fcfa5d8e6b9145e282bf7b950d6c47.pdf"
SS = "raw/legal/codes/b0620548445580767b5c0d18c95c26f7.pdf"
RULES = "raw/legal/rules/CentralRule_12052026.pdf"
CMR = "raw/legal/repealed_or_saved/Coal_Mines_Regulation_2017_Noti.pdf"
DRAFT = "raw/legal/drafts/CMR_GN_31012026.pdf"
SO = {"SS": "raw/legal/notifications/267882.pdf", "IR": "raw/legal/notifications/267883.pdf",
      "OSH": "raw/legal/notifications/267884.pdf", "W": "raw/legal/notifications/267885.pdf",
      "SS-corr": "raw/legal/notifications/639a9f531898f5a767b1e45e762c2d87.pdf"}
CPCB = "raw/legal/environment/7thEditionPollutionControlLawSeries2021.pdf"
REPEAL_143 = ("(c) The Mines Act, 1952;", "(h) The Contract Labour (Regulation and Abolition) Act, 1970;")
SAVINGS_143 = ("shall remain in force to the extent they are not contrary to the provisions of this Code till they "
               "are repealed by the Central Government")
SUPERSEDED = ("3. Mines Rules, 1955; 4. Mines Rescue Rules, 1985; 5. Mines Vocational Training Rules, 1966;")
COLUMNS = ["instrument", "short_name", "year", "type", "status", "effective_date", "status_source", "evidence_quote",
           "source_file", "source_page", "notes"]

# instrument, short, year, type, status, effective date, status source, [(file, quote)], notes
ROWS = [
    ("Occupational Safety, Health and Working Conditions Code, 2020", "OSH Code 2020", 2020, "code", "in_force",
     "2025-11-21", "S.O. 5321(E), 21.11.2025 (all provisions)",
     [(SO["OSH"], "the Central Government hereby appoints the 21st day of November, 2025 as the date on which the "
                  "provisions of the said Code, shall come into force")], ""),
    ("Industrial Relations Code, 2020", "IR Code 2020", 2020, "code", "in_force", "2025-11-21",
     "S.O. 5320(E), 21.11.2025 (all provisions)",
     [(SO["IR"], "S.O. 5320(E).-In exercise of the powers conferred by sub-section (3) of section 1 of the Industrial "
                 "Relations Code, 2020")], ""),
    ("Code on Social Security, 2020", "SS Code 2020", 2020, "code", "in_force", "2025-11-21",
     "S.O. 5319(E), 21.11.2025, as corrected by S.O. 5936(E), 19.12.2025; some provisions commenced earlier under "
     "S.O. 2060(E), 03.05.2023 (not downloaded)",
     [(SO["SS"], "S.O. 5319(E).-In exercise of the powers conferred by sub-section (3) of section 1 of the Code on "
                 "Social Security, 2020"),
      (SO["SS-corr"], "sub-section (1) of section 164 except the provisions of the Code specified at serial number (vi) "
                      "of S.O.2060(E), dated the 3rd May, 2023")],
     "Commenced provision by provision; see the notification's schedule."),
    ("Code on Wages, 2019", "Wages Code 2019", 2019, "code", "in_force", "2025-11-21",
     "S.O. 5322(E), 21.11.2025 (listed provisions)",
     [(SO["W"], "S.O. 5322(E).-In exercise of the powers conferred by sub-section (3) of section 1 of the Code on "
                "Wages, 2019")], "A few sub-provisions are left out of the schedule. The Code's own text was not located (S10)."),
    ("Mines Act, 1952", "Mines Act 1952", 1952, "act", "repealed", "2025-11-21", "OSH Code 2020 s.143(1)(c)",
     [(OSH, REPEAL_143[0])], "Rules and regulations made under it survive only as saved by s.143(3)."),
    ("Contract Labour (Regulation and Abolition) Act, 1970", "CLRA 1970", 1970, "act", "repealed", "2025-11-21",
     "OSH Code 2020 s.143(1)(h)", [(OSH, REPEAL_143[1])], ""),
    ("Occupational Safety, Health and Working Conditions (Central) Rules, 2026", "OSH (Central) Rules 2026", 2026,
     "rules", "in_force", "2026-05-08", "G.S.R. 345(E), 08.05.2026; rule 1(3): in force on publication",
     [(RULES, "New Delhi, the 8th May, 2026 G.S.R. 345(E)"),
      (RULES, "(3) They shall come into force on the date of their publication in the Official Gazette.")], ""),
    ("Mines Rules, 1955", "Mines Rules 1955", 1955, "rules", "superseded", "2026-05-08",
     "G.S.R. 345(E), 08.05.2026, supersession list item 3", [(RULES, SUPERSEDED)], ""),
    ("Mines Vocational Training Rules, 1966", "MVT Rules 1966", 1966, "rules", "superseded", "2026-05-08",
     "G.S.R. 345(E), 08.05.2026, supersession list item 5", [(RULES, SUPERSEDED)], ""),
    ("Contract Labour (Regulation and Abolition) Central Rules, 1971", "CLRA Central Rules 1971", 1971, "rules",
     "superseded", "2026-05-08", "G.S.R. 345(E), 08.05.2026, supersession list item 8",
     [(RULES, "8. Contract Labour (Regulation and Abolition) Central Rules, 1971;")], ""),
    ("Coal Mines Regulations, 2017", "CMR 2017", 2017, "regulations", "saved", "2017-11-27",
     "G.S.R. 1449(E), 27.11.2017; saved by OSH Code 2020 s.143(3)",
     [(CMR, "New Delhi, the 27th November, 2017. G.S.R. 1449(E)"), (OSH, SAVINGS_143)],
     "Made under the repealed Mines Act; in force to the extent not contrary to the OSH Code, until repealed."),
    ("Occupational Safety, Health and Working Conditions (Coal Mines) Regulations, 2026 - draft", "CMR 2026 (draft)",
     2026, "regulations", "draft", "", "Draft G.S.R. 67(E), 28.01.2026 (DGMS copy dated 31.01.2026); no final notification found",
     [(DRAFT, "New Delhi, the 28th January, 2026 G.S.R. 67(E)")], "Not in force. Do not cite as law."),
    ("Coal Mines Provident Fund and Miscellaneous Provisions Act, 1948", "CMPF Act 1948", 1948, "act", "in_force", "",
     "Not in the SS Code 2020 s.164(1) repeal list (quoted: the list's item 3 is the EPF Act)",
     [(SS, "3. The Employees' Provident Funds and Miscellaneous Provisions Act, 1952;")],
     "Absence of repeal; the Act's own text is not downloaded."),
    ("Employees' Provident Funds and Miscellaneous Provisions Act, 1952", "EPF Act 1952", 1952, "act", "TODO-VERIFY", "",
     "SS Code s.164(1) item 3; S.O. 5319(E) as corrected by S.O. 5936(E) commences s.164(1) except what S.O. 2060(E) "
     "serial (vi) specifies - S.O. 2060(E) not downloaded (manual step 6)",
     [(SS, "3. The Employees' Provident Funds and Miscellaneous Provisions Act, 1952;"),
      (SO["SS-corr"], "sub-section (1) of section 164 except the provisions of the Code specified at serial number (vi) "
                      "of S.O.2060(E), dated the 3rd May, 2023")],
     "Neither repealed nor in force can be stated until S.O. 2060(E) serial (vi) is read."),
    ("Water (Prevention and Control of Pollution) Act, 1974", "Water Act 1974", 1974, "act", "in_force", "",
     "Not repealed by the labour codes; text in CPCB Pollution Control Law Series (2021)",
     [(CPCB, "THE WATER (PREVENTION AND CONTROL OF POLLUTION) ACT, 1974")],
     "The 2021 compilation predates later amendments, which are not reflected."),
    ("Air (Prevention and Control of Pollution) Act, 1981", "Air Act 1981", 1981, "act", "in_force", "",
     "Not repealed by the labour codes; text in CPCB Pollution Control Law Series (2021)",
     [(CPCB, "THE AIR (PREVENTION AND CONTROL OF POLLUTION) ACT, 1981")],
     "The 2021 compilation predates later amendments, which are not reflected."),
    ("Environment (Protection) Act, 1986", "EP Act 1986", 1986, "act", "in_force", "",
     "Not repealed by the labour codes; text in CPCB Pollution Control Law Series (2021)",
     [(CPCB, "THE ENVIRONMENT (PROTECTION) ACT, 1986")],
     "The 2021 compilation predates later amendments, which are not reflected."),
]


def main() -> int:
    out, bad = [], []
    for inst, short, year, typ, status, eff, src, evidence, notes in ROWS:
        found, quotes, files = [], [], []
        for rel, quote in evidence:
            page = find(rel, quote) if (DATA / rel).exists() else None
            found.append(page)
            quotes.append(quote)
            files.append(f"{rel} p.{page}" if page else f"{rel} (NOT FOUND)")
        ok = all(found)
        if not ok:
            bad.append(short)
        out.append({"instrument": inst, "short_name": short, "year": year, "type": typ,
                    "status": status if ok else "TODO-VERIFY", "effective_date": eff, "status_source": src,
                    "evidence_quote": " | ".join(quotes), "source_file": "; ".join(f.split(" p.")[0] for f in files),
                    "source_page": "; ".join(str(p or "") for p in found),
                    "notes": notes if ok else f"Evidence not found in the downloaded text; claimed status: {status}. {notes}"})
    with OUT.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(out)
    print(f"Wrote reference/legal_instruments.csv: {len(out)} instruments; evidence found for {len(out) - len(bad)}; "
          f"sha256 {sha256_file(OUT)[:16]}")
    if bad:
        print(f"  evidence NOT found (status set to TODO-VERIFY): {bad}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
