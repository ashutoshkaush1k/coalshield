"""Stage D3: reference/crosswalk_msha_india.csv - hand-built mapping to our Indian violation categories.

Two kinds of rows:
  MSHA  30 CFR section ranges (part, subpart, or single sections) -> Indian category. Used by
        clean_msha_rates.py to count MSHA violations by category.
  DGMS  DGMS accident causes (reference/accident_causes_dgms.csv) -> Indian category. Used in D4
        to weight the category mix by Indian accident causes.

Categories: the brief's ten (roof/strata, ventilation/gas, electrical, transport/haulage,
explosives, PPE, fire, environment, welfare, documentation) plus **machinery**, added in D3.
Machinery other than transport is DGMS's third-largest fatal cause (Table 2.9) and a large
MSHA group (Part 56/57 Subpart M, 77 Subpart E), and none of the ten fits it.

Subpart titles and section ranges were checked against the Cornell LII copy of 30 CFR parts 56,
57, 71, 75 and 77 on 2026-09-26 (eCFR refuses automated clients). Part titles for the other parts
and the single-section titles in 75 Subpart R and 77 Subpart R are written by hand.
`fit` says how well the US standard corresponds: strong, partial, or weak.
"""

from __future__ import annotations

import csv
import sys

from common import DATA, sha256_file

OUT = DATA / "reference/crosswalk_msha_india.csv"
CATEGORIES = ["roof/strata", "ventilation/gas", "electrical", "transport/haulage", "explosives", "PPE", "fire",
              "environment", "welfare", "documentation", "machinery"]
COLUMNS = ["source_system", "cfr_part", "section_from", "section_to", "source_code", "source_label", "indian_category",
           "fit", "rationale"]

# (part, from, to, label, category, fit, rationale). Narrower ranges are listed before the wider
# ones they sit in; the first match wins.
MSHA = [
    # Part 75 - underground coal. Subpart R section by section.
    (75, 1700, 1700, "Oil and gas wells", "ventilation/gas", "strong", "Gas inflow from wells; Indian gas-hazard provisions"),
    (75, 1702, 1703, "Smoking prohibition; portable lamps", "fire", "strong", "Ignition sources underground"),
    (75, 1704, 1708, "Escapeways", "fire", "partial", "Emergency egress; nearest Indian heading is fire and emergency"),
    (75, 1709, 1709, "Methane accumulation; firedamp detectors", "ventilation/gas", "strong", "Gas detection"),
    (75, 1710, 1710, "Canopies or cabs", "machinery", "partial", "Operator protection on mobile machinery"),
    (75, 1711, 1711, "Sealing of mines", "ventilation/gas", "strong", "Seals isolate gas and fire areas"),
    (75, 1712, 1713, "Bath houses, toilets; first aid, emergency medical", "welfare", "strong", "Amenities and first aid are welfare duties in Indian law"),
    (75, 1714, 1714, "Self-rescue devices", "PPE", "strong", "Personal escape apparatus carried by each miner"),
    (75, 1715, 1716, "Identification check; operations under water", "documentation", "partial", "Check-in records and notifications"),
    (75, 1718, 1719, "Drinking water; illumination", "welfare", "strong", "Amenities"),
    (75, 1720, 1720, "Protective clothing", "PPE", "strong", "Goggles, footwear, helmets, gloves"),
    (75, 1721, 1721, "Opening new mines; notifications", "documentation", "strong", "Notices to the regulator"),
    (75, 1722, 1728, "Guards, machinery operation and maintenance, raised work", "machinery", "strong", "Machinery safety"),
    (75, 1729, 1729, "Welding operations", "fire", "strong", "Hot work ignition risk"),
    (75, 1730, 1730, "Compressed air systems", "machinery", "strong", "Pressure equipment"),
    (75, 1731, 1731, "Belt conveyor maintenance", "transport/haulage", "strong", "Conveyors are haulage"),
    (75, 1732, 1732, "Proximity detection systems", "machinery", "strong", "Collision protection on mobile machinery"),
    (75, 1, 2, "Subpart A - General", "documentation", "weak", "Scope provisions"),
    (75, 100, 161, "Subpart B - Qualified and Certified Persons", "documentation", "strong", "Competency certificates and records"),
    (75, 200, 223, "Subpart C - Roof Support", "roof/strata", "strong", "Roof and side support"),
    (75, 300, 389, "Subpart D - Ventilation", "ventilation/gas", "strong", "Ventilation, examinations, gas"),
    (75, 400, 404, "Subpart E - Combustible Materials and Rock Dusting", "ventilation/gas", "strong", "Coal dust explosion prevention (DGMS groups dust with gas)"),
    (75, 500, 1003, "Subparts F-K - Electrical equipment, cables, grounding, HV/LV circuits, trolley wires", "electrical", "strong", "Electrical safety"),
    (75, 1100, 1108, "Subpart L - Fire Protection", "fire", "strong", "Fire protection"),
    (75, 1200, 1204, "Subpart M - Maps", "documentation", "strong", "Mine plans and maps"),
    (75, 1300, 1328, "Subpart N - Explosives and Blasting", "explosives", "strong", "Explosives"),
    (75, 1400, 1438, "Subpart O - Hoisting and Mantrips", "transport/haulage", "strong", "Winding and man-riding"),
    (75, 1500, 1508, "Subpart P - Mine Emergencies", "fire", "partial", "Emergency response and refuge; nearest heading fire and emergency"),
    (75, 1600, 1600, "Subpart Q - Communications", "fire", "partial", "Emergency communication and tracking"),
    (75, 1700, 1732, "Subpart R - Miscellaneous (other sections)", "documentation", "weak", "Remaining miscellaneous duties"),
    (75, 1900, 1916, "Subpart T - Diesel-Powered Equipment", "machinery", "partial", "Diesel machines; exhaust aspects are ventilation"),
    # Part 77 - surface coal. Subpart R section by section.
    (77, 1700, 1709, "Communications, emergency medical, first aid", "welfare", "partial", "First aid and emergency medical arrangements"),
    (77, 1710, 1710, "Protective clothing", "PPE", "strong", "Personal protective equipment"),
    (77, 1711, 1711, "Smoking prohibition", "fire", "strong", "Ignition sources"),
    (77, 1712, 1713, "Reopening mines; daily inspection", "documentation", "partial", "Notifications and inspection records"),
    (77, 1, 2, "Subpart A - General", "documentation", "weak", "Scope provisions"),
    (77, 100, 107, "Subpart B - Qualified and Certified Persons", "documentation", "strong", "Competency certificates"),
    (77, 200, 217, "Subpart C - Surface Installations", "welfare", "weak", "Safe access, walkways, structures; no close Indian heading"),
    (77, 300, 315, "Subpart D - Thermal Dryers", "fire", "partial", "Dryer fires and explosions"),
    (77, 400, 413, "Subpart E - Safeguards for Mechanical Equipment", "machinery", "strong", "Machinery guarding"),
    (77, 500, 906, "Subparts F-J - Electrical equipment, cables, grounding, HV/LV circuits", "electrical", "strong", "Electrical safety"),
    (77, 1000, 1013, "Subpart K - Ground Control", "roof/strata", "strong", "Highwall and bench stability (strata control at surface)"),
    (77, 1100, 1112, "Subpart L - Fire Protection", "fire", "strong", "Fire protection"),
    (77, 1200, 1202, "Subpart M - Maps", "documentation", "strong", "Plans and maps"),
    (77, 1300, 1304, "Subpart N - Explosives and Blasting", "explosives", "strong", "Explosives"),
    (77, 1400, 1438, "Subpart O - Personnel Hoisting", "transport/haulage", "strong", "Hoisting"),
    (77, 1500, 1505, "Subpart P - Auger Mining", "machinery", "partial", "Mining machinery"),
    (77, 1600, 1608, "Subpart Q - Loading and Haulage", "transport/haulage", "strong", "Dumpers, haul roads, loading"),
    (77, 1800, 1802, "Subpart S - Trolley Wires", "electrical", "strong", "Electrical"),
    (77, 1900, 1916, "Subpart T - Slope and Shaft Sinking", "roof/strata", "partial", "Shaft and slope ground control"),
    (77, 2100, 2104, "Subpart V - Safety Program for Surface Mobile Equipment", "transport/haulage", "partial", "Mobile equipment programme"),
    # Parts 56 (surface) and 57 (underground) metal/nonmetal standards, cited at some coal operations.
    *[(p, lo, hi, f"Part {p} {lab}", cat, fit, why) for p in (56, 57) for lo, hi, lab, cat, fit, why in [
        (1, 1000, "Subpart A - General", "documentation", "weak", "Scope provisions"),
        (3000, 3461, "Subpart B - Ground Control", "roof/strata", "strong", "Ground control"),
        (4000, 4761, "Subpart C - Fire Prevention and Control", "fire", "strong", "Fire"),
        (5001, 5075, "Subpart D - Air Quality and Physical Agents", "environment", "strong", "Workplace dust, noise, radiation"),
        (6000, 6960, "Subpart E - Explosives", "explosives", "strong", "Explosives"),
        (7002, 7807, "Subpart F - Drilling", "machinery", "strong", "Drilling machinery"),
        (8518, 8535, "Subpart G - Ventilation (Part 57)", "ventilation/gas", "strong", "Ventilation"),
        (9100, 9362, "Subpart H - Loading, Hauling, and Dumping", "transport/haulage", "strong", "Haulage"),
        (10001, 10010, "Subpart I - Aerial Tramways", "transport/haulage", "strong", "Ropeways"),
        (11001, 11059, "Subpart J - Travelways (and Escapeways, Part 57)", "welfare", "weak", "Safe access; no close Indian heading"),
        (12001, 12088, "Subpart K - Electricity", "electrical", "strong", "Electrical"),
        (13001, 13030, "Subpart L - Compressed Air and Boilers", "machinery", "strong", "Pressure equipment"),
        (14000, 14219, "Subpart M - Machinery and Equipment", "machinery", "strong", "Machinery"),
        (15001, 15031, "Subpart N - Personal Protection", "PPE", "strong", "PPE"),
        (16001, 16017, "Subpart O - Materials Storage and Handling", "machinery", "partial", "Material handling"),
        (17001, 17010, "Subpart P - Illumination", "welfare", "strong", "Amenities"),
        (18002, 18028, "Subpart Q - Safety Programs", "documentation", "partial", "Examinations, training, first aid records"),
        (19000, 19135, "Subpart R - Personnel Hoisting", "transport/haulage", "strong", "Winding"),
        (20001, 20032, "Subpart S - Miscellaneous", "welfare", "weak", "Housekeeping, sanitation and other items"),
        (22001, 22608, "Subpart T - Methane (Part 57)", "ventilation/gas", "strong", "Gas"),
        (23000, 23004, "Subpart T/U - Surface Mobile Equipment programme", "transport/haulage", "partial", "Mobile equipment")]],
    # Part 71 - surface coal health standards.
    (71, 100, 301, "Part 71 Subparts B-D - Dust standards, sampling, dust control plans", "environment", "strong", "Workplace respirable dust"),
    (71, 400, 603, "Part 71 Subparts E-G - Bathing, toilets, drinking water", "welfare", "strong", "Amenities"),
    (71, 700, 702, "Part 71 Subpart H - Airborne Contaminants", "environment", "strong", "Workplace air quality"),
    # Whole parts.
    (40, 0, 99999, "Part 40 - Representative of miners", "documentation", "partial", "Designation notices"),
    (41, 0, 99999, "Part 41 - Notification of legal identity", "documentation", "strong", "Owner and operator notices"),
    (45, 0, 99999, "Part 45 - Independent contractors", "documentation", "strong", "Contractor identification and registers (Indian contractor-register duties)"),
    (58, 0, 99999, "Part 58 - Health standards, metal and nonmetal mines", "environment", "strong", "Workplace health exposure"),
    (46, 0, 99999, "Part 46 - Training (surface operations)", "documentation", "partial", "Training records (Indian vocational training duties)"),
    (47, 0, 99999, "Part 47 - Hazard communication", "documentation", "partial", "Labels, data sheets, hazard information"),
    (48, 0, 99999, "Part 48 - Training and retraining of miners", "documentation", "partial", "Training records (Indian vocational training duties)"),
    (49, 0, 99999, "Part 49 - Mine rescue teams", "fire", "partial", "Rescue readiness; nearest heading fire and emergency"),
    (50, 0, 99999, "Part 50 - Accident, injury, illness, employment reports", "documentation", "strong", "Statutory returns and notices"),
    (62, 0, 99999, "Part 62 - Occupational noise exposure", "environment", "strong", "Workplace noise"),
    (70, 0, 99999, "Part 70 - Health standards, underground coal (respirable dust)", "environment", "strong", "Workplace respirable dust"),
    (71, 0, 99999, "Part 71 - Health standards, surface coal (other sections)", "environment", "partial", "Workplace health"),
    (72, 0, 99999, "Part 72 - Health standards for coal mines (dust, diesel particulate)", "environment", "strong", "Workplace air quality"),
    (75, 0, 99999, "Part 75 - other sections", "documentation", "weak", "Not in a listed subpart"),
    (77, 0, 99999, "Part 77 - other sections", "documentation", "weak", "Not in a listed subpart"),
    (90, 0, 99999, "Part 90 - Miners with evidence of pneumoconiosis", "environment", "partial", "Dust exposure limits for affected miners"),
]
ACT = ("Mine Act section only (e.g. 316(b) emergency response plan, 103, 104, 109)", "documentation", "partial",
       "Violations of the Mine Act itself are mostly plans, notices and records; 316(b) plans are emergency plans")

DGMS = [
    ("Fall of Roof", "roof/strata", "strong", "Ground movement"), ("Fall of Sides", "roof/strata", "strong", "Ground movement"),
    ("Other Ground Movement", "roof/strata", "strong", "Ground movement"),
    ("Transportation machinery (winding in shaft)", "transport/haulage", "strong", "Winding"),
    ("Rope Haulage", "transport/haulage", "strong", "Haulage"),
    ("Wheeled Trackless Transp", "transport/haulage", "strong", "Dumpers, trucks, other trackless transport"),
    ("Other Transp. Machinery", "transport/haulage", "strong", "Conveyors and other transport"),
    ("Machinery other than transportation machinery", "machinery", "strong", "Machinery"),
    ("Explosives", "explosives", "strong", "Explosives"), ("Electricity", "electrical", "strong", "Electrical"),
    ("Gas, dust and other combustible material", "ventilation/gas", "strong", "Gas, dust and fire"),
    ("Fall of person", "PPE", "partial", "Fall protection (safety belts) and footwear; also housekeeping"),
    ("Fall of Object", "PPE", "partial", "Helmets; also secure stacking"),
    ("Other falls", "PPE", "weak", "Falls not otherwise classified"),
    ("Irruption of Water", "documentation", "partial", "Prevented through plans, surveys and danger-zone notices"),
    ("Flying Pieces", "PPE", "partial", "Eye and face protection"),
    ("Miscellaneous", "", "none", "No single category; left unmapped"),
]


def main() -> int:
    rows = []
    for part, lo, hi, label, cat, fit, why in MSHA:
        assert cat in CATEGORIES, cat
        code = f"30 CFR Part {part}" if (lo, hi) == (0, 99999) else (
            f"30 CFR {part}.{lo}" if lo == hi else f"30 CFR {part}.{lo}-{part}.{hi}")
        rows.append({"source_system": "MSHA", "cfr_part": part, "section_from": lo, "section_to": hi,
                     "source_code": code, "source_label": label, "indian_category": cat, "fit": fit, "rationale": why})
    rows.append({"source_system": "MSHA", "cfr_part": "", "section_from": "", "section_to": "",
                 "source_code": "Mine Act section", "source_label": ACT[0], "indian_category": ACT[1], "fit": ACT[2],
                 "rationale": ACT[3]})
    for cause, cat, fit, why in DGMS:
        assert cat in CATEGORIES or cat == "", cat
        rows.append({"source_system": "DGMS", "cfr_part": "", "section_from": "", "section_to": "",
                     "source_code": f"DGMS cause: {cause}", "source_label": cause, "indian_category": cat, "fit": fit,
                     "rationale": why})
    with OUT.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    print(f"Wrote reference/crosswalk_msha_india.csv: {len(rows)} rows "
          f"({sum(r['source_system'] == 'MSHA' for r in rows)} MSHA, {sum(r['source_system'] == 'DGMS' for r in rows)} DGMS), "
          f"sha256 {sha256_file(OUT)[:16]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
