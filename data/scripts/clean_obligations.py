"""Stage D3: reference/obligations.csv - statutory obligations the app tracks, each with its clause,
page and a verbatim passage the script finds in the downloaded official text.

Instruments cited (per the D1 legal findings, reference/legal_instruments.csv):
  OSH Code 2020; OSH (Central) Rules 2026 (G.S.R. 345(E)); Coal Mines Regulations 2017 (saved by
  OSH Code s.143(3)); Water Act 1974, Air Act 1981 and Environment (Protection) Rules 1986 as
  printed in the CPCB Pollution Control Law Series (2021). Never the repealed Mines Act 1952, CLRA
  1970 or Mines Rules 1955.

The candidate passages were located by searching the downloaded texts, and are checked here:
verified = "yes" only when the quote is found in the stated file (after whitespace and dash
normalisation, see legal_text.py). A quote found on another page is accepted with that page. A
quote not found makes the row TODO-VERIFY; so does an obligation with no downloaded source at all.
"""

from __future__ import annotations

import csv
import html
import sys

from common import DATA, sha256_file
from legal_text import find

OUT = DATA / "reference/obligations.csv"
FILES = {
    "OSH Code 2020": "raw/legal/codes/36fcfa5d8e6b9145e282bf7b950d6c47.pdf",
    "OSH (Central) Rules 2026": "raw/legal/rules/CentralRule_12052026.pdf",
    "Coal Mines Regulations 2017": "raw/legal/repealed_or_saved/Coal_Mines_Regulation_2017_Noti.pdf",
    "Water (Prevention and Control of Pollution) Act 1974": "raw/legal/environment/7thEditionPollutionControlLawSeries2021.pdf",
    "Air (Prevention and Control of Pollution) Act 1981": "raw/legal/environment/7thEditionPollutionControlLawSeries2021.pdf",
    "Environment (Protection) Rules 1986": "raw/legal/environment/7thEditionPollutionControlLawSeries2021.pdf",
}
PREFIX = {"safety": "SAF", "health": "HLT", "labour": "LAB", "environment": "ENV", "reporting": "RPT"}
COLUMNS = ["obligation_code", "domain", "title", "instrument", "clause", "applies_to", "frequency", "due_rule",
           "responsible_role", "evidence_type", "citation_page", "citation_file", "citation_quote", "verified", "note"]
CPCB_NOTE = "Text as printed in the CPCB Pollution Control Law Series, 7th ed. (2021); later amendments not reflected."

# domain, title, instrument, clause, applies_to, frequency, due_rule, responsible_role, evidence_type, page, quote, note
O = [
    # --- OSH Code 2020 and OSH (Central) Rules 2026 --------------------------------------------------
    ("reporting", "Register the mine as an establishment", "OSH Code 2020", "s.3(1)", "mine", "once",
     "within sixty days from the date the Code applies", "employer (owner/agent)", "certificate of registration", 15,
     "shall, within sixty days from the date of such applicability of this Code, make an application electronically to the registering officer appointed by the appropriate Government",
     "The application form (FORM-I, Shram Suvidha Portal) is in OSH (Central) Rules r.3(1)."),
    ("reporting", "Prior notice of commencement, reopening, cessation or closure of a mine", "OSH (Central) Rules 2026",
     "r.4(2), proviso", "mine", "on event", "not less than thirty days before", "employer of the mine",
     "notice in FORM-VII", 142,
     "in the case of mines, the employer of every mine shall give not less than thirty days’ prior notice of the commencement, reopening, cessation, discontinuation or abandonment of operations or closing of mines in FORM- VII",
     "Printed as a proviso after sub-rule (2); the sub-rule label is inferred."),
    ("reporting", "Notify a fatal accident", "OSH (Central) Rules 2026", "r.7(1)", "mine", "on event", "forthwith",
     "employer (owner/agent/manager)", "accident notice in FORM-XI", 143,
     "an accident occurs resulting to death, the employer of the establishment shall inform to the Inspector-cum-Facilitator forthwith in a notice in FORM-XI",
     "The rule also requires notice to the District Magistrate/SDO, the police and the victim's family."),
    ("reporting", "Report an injury that disables for 48 hours or more", "OSH (Central) Rules 2026", "r.7(2)", "mine",
     "on event", "within twelve hours after the completion of forty-eight hours", "employer (owner/agent/manager)",
     "accident report in FORM-XI", 144,
     "FORM-XI within twelve hours after the completion of forty-eight hours, electronically to the Inspector-cum-Facilitator(s).",
     ""),
    ("reporting", "Notify a dangerous occurrence", "OSH (Central) Rules 2026", "r.7(3)", "mine", "on event",
     "within twelve hours", "employer (owner/agent/manager)", "intimation to the Inspector-cum-Facilitator and DM/SDO", 144,
     "dangerous occurrence as specified in sub-rule (4), whether causing any bodily injury or disability or not, the employer shall within twelve hours send an intimation to- (i) the Inspector-cum-Facilitator",
     "r.7(4) lists the classes, including mine-specific ones (inrush of water, fire, explosion)."),
    ("safety", "Safety committee meets at least monthly", "OSH (Central) Rules 2026", "r.14(3)", "mine", "monthly",
     "at least once a month; committee tenure three years", "owner/agent/manager", "meeting minutes", 150,
     "The tenure of the Safety Committee shall be for three years and it shall meet at least once in every quarter. Provided that in the case of mines, the Safety Committee shall meet at least once in a month.",
     "r.14(1): required where 500 or more workers are employed."),
    ("safety", "Appoint safety officers where 100 or more workers are employed", "OSH (Central) Rules 2026", "r.20(1)",
     "mine", "continuous", "one safety officer up to five hundred workers", "employer (owner/agent)",
     "appointment letter", 155,
     "At every mine, wherein one hundred or more workers are ordinarily employed, the employer shall appoint safety officer on a scale of one up to five hundred workers",
     "One more for each further 500 workers or part thereof."),
    ("safety", "Quarterly emergency mock drills", "OSH (Central) Rules 2026", "r.59", "mine", "quarterly", "quarterly",
     "employer (owner/agent/manager)", "mock drill record", 184,
     "shall ensure quarterly conduct of Mock drills to check emergency preparedness to deal with various emergencies.",
     "The form of record is not stated."),
    ("health", "Initial and annual medical examination of mine workers", "OSH (Central) Rules 2026", "r.109(1)", "mine",
     "annual", "initial examination before employment; periodical examination annually", "employer of the mine",
     "medical certificate (FORM-IX)", 208,
     "The employer of every mine shall make arrangements for – (i) initial medical examination of every person seeking employment in a mine; (ii) periodical medical examination of person employed in a mine annually;",
     "r.60(i) extends medical examination duties to contract labour."),
    ("health", "Keep medical examination records for five years after employment", "OSH (Central) Rules 2026",
     "r.114(1)", "mine", "continuous", "while employed and for five years thereafter", "manager",
     "medical records", 209,
     "shall be retained in the possession of the manager of the mine so long as the person is employed in the mine and for a period of five years thereafter",
     ""),
    ("reporting", "Upload the annual return", "OSH (Central) Rules 2026", "r.72(5)", "mine", "annual",
     "on or before 28/29 February following each calendar year", "employer (owner/agent/manager)",
     "return in FORM-XVII and XVIII", 193,
     "on or before the 28th or 29th day of February following the end of each Calendar year, upload a return in FORM-XVII and XVIII on the designated portal",
     "r.74 also requires FORM-XVII to reach the Inspector-cum-Facilitator by the last day of February."),
    ("labour", "Keep registers and preserve them for five years", "OSH (Central) Rules 2026", "r.72(1)(vii)", "mine",
     "continuous", "preserve for five calendar years from the last entry", "employer (owner/agent/manager)",
     "registers (FORM XIII, XIV, XV)", 193,
     "all the registers and other records shall be preserved in original for a period of five calendar years from the date of last entry made therein.",
     "Employee register, attendance-cum-muster roll and wage register: r.72(1)(i)-(iii)."),
    ("labour", "Contractor licence is valid for five years", "OSH Code 2020", "s.48(3)", "contractor", "every 5 years",
     "valid for five years", "contractor", "licence copy", 41,
     "the licence issued for the purposes of sub-section (1) of section 47 shall be valid for a period of five years in respect of the number of contract labour specified therein",
     "Under r.90(4), no licence is needed for up to 49 contract labour."),
    ("labour", "Apply for contractor licence renewal in time", "OSH (Central) Rules 2026", "r.91(2)", "contractor",
     "every 5 years", "at least 30 days, and not more than 90 days, before expiry", "contractor",
     "renewal application", 200,
     "Every such application shall be submitted on the Portal referred to in sub-rule (1) at least thirty days prior to expiry of licence period but not before ninety days of such expiry of licence.",
     "r.91(3): a late application attracts 25% extra fee."),
    ("labour", "Intimate each contract work order", "OSH (Central) Rules 2026", "r.94(1)", "contractor", "on event",
     "within fifteen days of receiving the work order", "contractor", "portal intimation", 201,
     "Every contractor shall within fifteen days of the receipt of a contract work order shall intimate about the contract work order",
     ""),
    ("labour", "Pay contract labour wages by the seventh day", "OSH (Central) Rules 2026", "r.98(2)", "contractor",
     "monthly", "before the expiry of the seventh day after the last day of the wage period", "contractor",
     "wage register / payment record", 202,
     "The wages of every person employed as contract labour in an establishment or by a contractor shall be paid before the expiry of seventh day after the last day of the wage period",
     ""),
    ("reporting", "Contractor half-yearly return", "OSH (Central) Rules 2026", "r.98(7)", "contractor", "half-yearly",
     "within thirty days of the close of each half year", "contractor", "return in FORM-XVIII", 203,
     "Every contractor shall send half-yearly return in FORM-XVIII electronically to the Deputy Chief Labour Commissioner (Central) concerned not later than thirty days from the close of the half year",
     ""),
    ("safety", "Refresher training at least once in four years", "OSH (Central) Rules 2026", "r.159", "worker",
     "every 4 years", "at least once in four years", "worker (arranged by the owner/agent/manager)",
     "training certificate", 223,
     "Every person in employment in a mine shall undergo the refresher training at least once in four years, as per training scheme",
     "r.158: initial training before employment; r.162: retraining after long absence, a serious accident or a job change."),
    ("safety", "Report unsafe or unhealthy situations", "OSH Code 2020", "s.13(d)", "worker", "on event",
     "as soon as practicable", "worker", "hazard report", 20,
     "if any situation which is unsafe or unhealthy comes to his attention, as soon as practicable, report such situation to his employer or to the health and safety representative",
     ""),
    # --- Environment (CPCB Pollution Control Law Series, 2021) ----------------------------------------
    ("environment", "Consent of the State Board for water discharges (Water Act)",
     "Water (Prevention and Control of Pollution) Act 1974", "s.25(1)", "mine", "once; renewed as the consent order states",
     "before establishing the operation or a new discharge; valid for the period in the consent order", "occupier",
     "consent order (CTE/CTO)", 39,
     "no person shall, without the previous consent of the State Board, — (a) establish or take any steps to establish any industry, operation or process, or any treatment and disposal system",
     CPCB_NOTE),
    ("environment", "Consent of the State Board to operate in an air pollution control area (Air Act)",
     "Air (Prevention and Control of Pollution) Act 1981", "s.21(1)", "mine", "once; renewed as the consent order states",
     "before establishing or operating; valid for the period in the consent order", "occupier",
     "consent order (CTE/CTO)", 138,
     "no person shall, without the previous consent of the State Board, establish or operate any industrial plant in an air pollution control area",
     CPCB_NOTE),
    ("environment", "Annual environmental statement (Form V)", "Environment (Protection) Rules 1986", "r.14", "mine",
     "annual", "on or before 30 September, for the financial year ending 31 March", "occupier",
     "environmental statement (Form V)", 307,
     "shall submit an environmental 1 [statement] for the financial year ending the 31st March in Form V to the concerned State Pollution Control Board on or before the 1 [thirtieth day of September] every year",
     "'1 [' marks are amendment references printed in the text. " + CPCB_NOTE),
    ("environment", "Ambient air limits for coal mines, 500 m downwind of dust sources", "Environment (Protection) Rules 1986",
     "Sch. I, entry 90 (Standards for Coal Mines), para 1", "mine", "continuous",
     "new mines, 24 h: RPM 250, SO2 120, NOx 120 µg/m3 (Tables II-III give other values for existing coalfields)",
     "owner/agent/manager", "ambient air monitoring report", 388,
     "(RPM) Annual Average* 24 Hours ** 180 µg/m3 250 µg/m3 Respirable Particulate Matter sampling and analysis Sulphur Dioxide (SO2) Annual Average* 24 Hours ** 80 µg/m3 120 µg/m3",
     "Table columns are flattened in the text. Jharia, Raniganj and Bokaro have their own table. " + CPCB_NOTE),
    ("environment", "Air quality monitoring at a coal mine every fortnight", "Environment (Protection) Rules 1986",
     "Sch. I, entry 90, para 2", "mine", "fortnightly", "once in a fortnight at the dust generating sources",
     "owner/agent/manager", "monitoring report", 389,
     "Air Quality monitoring at a frequency of once in a fortnight at the dust generating sources given in clause 1 shall be carried out.",
     "Relaxation and escalation rules follow on the same pages. " + CPCB_NOTE),
    ("environment", "Mine effluent discharge limits", "Environment (Protection) Rules 1986", "Sch. I, entry 90, para 3",
     "mine", "fortnightly", "pH 5.5-9.0; COD 250 mg/l; TSS 100 mg/l (200 on land for irrigation); oil and grease 10 mg/l",
     "owner/agent/manager", "effluent monitoring report", 390,
     "pH - 5.5 to 9.0 Chemical Oxygen Demand (COD) - 250 mg/l Total Suspended Solids (TSS) - 100 mg/l, 200 mg/l (Land for irrigation) Oil & Grease (O & G) - 10 mg/l",
     "Monitoring once in a fortnight (same page). " + CPCB_NOTE),
    ("environment", "Mine noise limits", "Environment (Protection) Rules 1986", "Sch. I, entry 90, noise para", "mine",
     "fortnightly", "Leq 75 dB(A) 6 am-10 pm; Leq 70 dB(A) 10 pm-6 am; monitored once a fortnight",
     "owner/agent/manager", "noise monitoring report", 390,
     "2. Noise Level Standards 6.00 AM – 10.00 PM 10.00 PM – 6.00 AM Noise level Leq 75 dB(A) Leq 70 dB(A) (Monitoring frequency for noise level shall be once in a fortnight)",
     "Printed as para '2.' after para 3. " + CPCB_NOTE),
    ("environment", "National ambient air quality standards (PM10, PM2.5, SO2, NO2)", "Environment (Protection) Rules 1986",
     "Sch. VII", "mine", "continuous", "24 h: PM10 100, PM2.5 60, SO2 80, NO2 80 µg/m3 (industrial/residential areas)",
     "owner/agent/manager", "ambient air monitoring report", 454,
     "3 Particulate Matter (size less than 10µm) or PM10 µg/m3 Annual* 24 hours** 60 100 60 100 - Gravimetric - TOEM - Beta attenuation 4 Particulate Matter (size less than 2.5µm) or PM2.5 µg/m3 Annual* 40 40",
     "Applies at a coal mine where a residential, commercial or industrial place is within 500 m of a dust source (Sch. I entry 90). " + CPCB_NOTE),
    ("environment", "Report an accidental discharge or emission above standards", "Environment (Protection) Rules 1986",
     "r.12", "mine", "on event", "forthwith", "person in charge of the place", "intimation to the authorities", 305,
     "the person in charge of the place at which such discharge occurs or is apprehended to occur shall forth with intimate the fact of such occurrence or apprehension of such occurrence",
     CPCB_NOTE),
]

# Obligations with no downloaded source: listed so the gap is visible, never presented as verified.
UNSOURCED = [
    ("reporting", "Monthly coal production return", "TODO-VERIFY", "TODO-VERIFY", "mine", "monthly", "TODO-VERIFY",
     "owner/agent/manager", "production return",
     "No downloaded source states a coal production reporting duty. The Ministry of Coal statistics (S06) are the "
     "published result of such returns, not the rule requiring them."),
]


def main() -> int:
    rows, counters = [], {}
    for dom, title, inst, clause, applies, freq, due, role, evid, page, quote, note in O + CMR:
        rel = FILES[inst]
        quote = html.unescape(quote)
        got = find(rel, quote, page) if (DATA / rel).exists() else None
        counters[dom] = counters.get(dom, 0) + 1
        extra = ""
        if got and got != page:
            extra = f" Quote found on PDF page {got}, not {page}."
        rows.append({"obligation_code": f"{PREFIX[dom]}-{counters[dom]:02d}", "domain": dom, "title": title,
                     "instrument": inst, "clause": clause, "applies_to": applies, "frequency": freq, "due_rule": due,
                     "responsible_role": role, "evidence_type": evid, "citation_page": got or page,
                     "citation_file": rel, "citation_quote": quote, "verified": "yes" if got else "TODO-VERIFY",
                     "note": (note + extra if got else f"Quote NOT found in {rel}. {note}").strip()})
    for dom, title, inst, clause, applies, freq, due, role, evid, note in UNSOURCED:
        counters[dom] = counters.get(dom, 0) + 1
        rows.append({"obligation_code": f"{PREFIX[dom]}-{counters[dom]:02d}", "domain": dom, "title": title,
                     "instrument": inst, "clause": clause, "applies_to": applies, "frequency": freq, "due_rule": due,
                     "responsible_role": role, "evidence_type": evid, "citation_page": "", "citation_file": "",
                     "citation_quote": "", "verified": "TODO-VERIFY", "note": note})
    with OUT.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    ok = sum(r["verified"] == "yes" for r in rows)
    by = {}
    for r in rows:
        by[r["domain"]] = by.get(r["domain"], 0) + 1
    print(f"Wrote reference/obligations.csv: {len(rows)} obligations {by}; verified {ok}, TODO-VERIFY {len(rows) - ok}; "
          f"sha256 {sha256_file(OUT)[:16]}")
    for r in rows:
        if r["verified"] != "yes":
            print(f"    TODO-VERIFY {r['obligation_code']} {r['title']}")
    return 0


# Coal Mines Regulations 2017 (saved by OSH Code s.143(3)). Left out on purpose, to avoid overlap
# with the OSH (Central) Rules 2026 or because no interval is stated: reg.5 closure notice, reg.8
# accident notice, reg.252 drills, reg.179 safety lamps, reg.46 ventilator efficiency.
CMR = [
    ("safety", "Keep mine plans and sections up to date", "Coal Mines Regulations 2017", "reg.64(4)", "mine",
     "every 3 months", "corrected up to a date not earlier than three months", "owner/agent/manager (surveyor)",
     "updated plan", 194,
     "(4) The plans and sections required by these regulations shall be maintained corrected up-to-date which is not earlier than three months",
     "Plans must also be brought up to date before abandonment or closure (proviso)."),
    ("safety", "Weekly examination of winding ropes", "Coal Mines Regulations 2017", "reg.88(1)(b)", "mine", "weekly",
     "at least once every seven days, at no more than 1 m/s", "engineer or competent person",
     "examination report in bound paged book", 209,
     "(b) once at least in every seven days, – (i) each winding rope by passing the rope at a speed not exceeding one meter per second;",
     "reg.88(1) also sets 24-hour, 30-day and 12-month checks."),
    ("safety", "Sirdar inspection before and during each shift", "Coal Mines Regulations 2017", "reg.129(5)", "mine",
     "every shift", "within two hours before work starts, then at least every four hours", "sirdar or competent person",
     "inspection report in bound paged book", 227,
     "shall, within two hours before the commencement of work in a shift, inspect every part of the mine or district assigned to him, in which persons have to work or pass during the shift",
     "The four-hour repeat is later in the same sub-regulation; reg.129(8) requires the bound paged book."),
    ("safety", "Carbon monoxide testing of depillaring districts", "Coal Mines Regulations 2017", "reg.137(12)", "mine",
     "weekly", "CO tested at least every seven days; full analysis at least every thirty days", "competent person",
     "gas test record in bound paged book", 232,
     "(a) tested for percentage of carbon monoxide once at least in every seven days with an automatic detector of a type approved by the Chief Inspector; and (b) completely analysed once at least in every thirty days",
     ""),
    ("safety", "Monthly examination of fire-fighting equipment", "Coal Mines Regulations 2017", "reg.139(6)", "mine",
     "monthly", "at least once every month", "competent person", "examination report in bound paged book", 234,
     "(6) A competent person shall, once at least in every month, examine all the equipment, material and arrangements provided for fire-fighting",
     ""),
    ("health", "Monthly airborne respirable dust sampling", "Coal Mines Regulations 2017", "reg.143(3)", "mine",
     "monthly", "at least once every month, or when the Regional Inspector orders", "owner/agent/manager",
     "dust sampling record", 236,
     "once at least in every month thereafter or whenever the Regional Inspector so requires by an order in writing, cause the air at every work place where airborne dust is generated, to be sampled",
     ""),
    ("health", "Respirable dust limit: 2 mg/m3 (8-hour average)", "Coal Mines Regulations 2017", "reg.143(2)", "mine",
     "continuous", "8-hour TWA not above 2 mg/m3 in coal (free silica below 5%); otherwise 10 / free silica %",
     "owner/agent/manager", "dust sampling results", 236,
     "in milligrams per cubic meter of air sampled by dust sampler of a type approved by and determined in accordance with the procedure as specified by the Chief Inspector by a general or special order, exceeds two",
     "The silica condition and the 10/silica formula follow on the same page. Threshold source for D4 sensors."),
    ("safety", "Inflammable gas limits: 0.75% in return air, 1.25% anywhere", "Coal Mines Regulations 2017",
     "reg.153(2)(c)", "mine", "continuous", "0.75% in the return air of a district; 1.25% in any place",
     "owner/agent/manager", "air sample results in bound paged book", 245,
     "(c) the percentage of inflammable gas does not exceed 0.75 in the general body of the return air of any ventilating district and 1.25 in any place in the mine;",
     "reg.153(2)(e): air samples at least every thirty days. Threshold source for D4 sensors."),
    ("health", "Wet bulb temperature limit: 33.5 °C", "Coal Mines Regulations 2017", "reg.153(2)(d)", "mine",
     "continuous", "not above 33.5 °C; above 30.5 °C air must move at least 1 m/s", "owner/agent/manager",
     "temperature readings in bound paged book", 245,
     "(d) the wet bulb temperature in any working place does not exceed 33.5 degrees centigrade, and where the wet bulb temperature exceeds 30.5 degrees centigrade",
     "Threshold source for D4 sensors."),
    ("safety", "Weekly inflammable gas check where electricity is used", "Coal Mines Regulations 2017", "reg.169(1)(c)",
     "mine", "weekly", "at least every seven days; every 24 hours while above 0.8%", "competent person",
     "gas test record in bound paged book", 252,
     "(c) the determination shall be made or samples of air taken, as the case may be, once at least in every seven days, so however that – (i) if any determination shows the percentage of inflammable gas to exceed 0.8",
     "reg.169(1)(e): above 1.25%, cut off power in the district and report to the Regional Inspector forthwith."),
    ("safety", "Supply protective footwear at least every six months", "Coal Mines Regulations 2017", "reg.240(2)", "mine",
     "every 6 months", "free of charge, at intervals not exceeding six months", "owner/agent/manager",
     "issue register", 273,
     "(2) The protective footwear referred to in sub-regulation (1) shall be supplied free of charge, at intervals not exceeding six months, by the owner, agent or manager of a mine",
     "reg.241: approved helmets; reg.243: self-rescuers - no interval stated for either."),
]

if __name__ == "__main__":
    sys.exit(main())
