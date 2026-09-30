# Data cards

One card per dataset in `data/reference/` and `data/out/`. Each states whether the data is
**real** (taken from a published source), **calibrated-synthetic** (generated, but tuned to real
statistics) or **synthetic** (generated from documented assumptions), plus where it came from, how
it was made, and what it cannot be trusted for.

The set is complete as of stage D6 (2026-09-26). The first section is a one-page summary; the
cards below give the detail.

---

## Data provenance for judges (one page)

**In one sentence:** the mines, companies, production totals, air quality, laws and accident
statistics are real and cited. The day-to-day operations (sensor readings, inspections,
contractors, grievances) are generated to look like them, and every generated row is labelled
as demo data.

**Real - taken from published sources, each cited to file and page**

| What | Source |
|---|---|
| The 74 mines: names, companies, locations, type, capacity, workforce | Global Energy Monitor, Global Coal Mine Tracker (Aug 2026, CC BY 4.0) |
| Coal companies and how they are grouped; published operating areas | Ministry of Coal monthly statistics; the companies' own web pages |
| Monthly coal production per company; overburden; output per person | Ministry of Coal (Monthly Coal Statistics Jun–Aug 2026, Sep 2025; Coal Directory of India 2024-25) |
| Accidents in Indian coal mines by cause, 2013–2022 | DGMS, Key Evaluation of Trends in Coal Mine Accidents (2024) |
| Air quality near coalfields (PM10, PM2.5, SO2, NO2), Jun–Sep 2026 | CPCB stations, via OpenAQ |
| District boundaries and names | DataMeet (Census 2011), Wikidata |
| Which laws apply, and 40 legal duties with clause and page | Gazette notifications; OSH Code 2020; OSH (Central) Rules 2026; Coal Mines Regulations 2017; CPCB law compilation (2021) |
| US mine inspection statistics (used only as a benchmark) | US MSHA open data |

**Calibrated - generated, but the totals and mixes follow the real figures**
- **Daily production per mine and shift.** Each company's month adds up to the Ministry figure
  (within ±3 %, checked). Seasonality comes from the published monthly profile.
- **Inspections and violations.** Rates come from US MSHA data scaled to mine size; the category
  mix blends MSHA with Indian DGMS accident causes.
- **Incidents.** Counts follow DGMS fatal, serious and dangerous-occurrence rates, scaled to our
  mines' share of national output.
- **Air-quality readings per mine.** The nearest CPCB station's daily values plus small noise,
  labelled `calibrated`; `synthetic` where no station is within 50 km.
- **Sensor alarms.** Only the legal limits count: methane 0.75 % / 1.25 %, dust 2 mg/m³ (8 hours),
  wet bulb 33.5 °C.

**Synthetic - generated for the demo from documented assumptions**
- **Minute-level signals:** sensor readings (shapes are modelling choices).
- **People and firms:** contractor companies, workers and their names (fictitious; IDs masked).
- **Grievances:** texts drafted in six languages, unreviewed.
- **Compliance scores and risk bands:** chosen to keep the demo script's story (100 / 80 / 70 /
  60 / 45); they are not assessments of the real mines.
- **Injected scenarios and decoys:** labelled test patterns for the AI features.

**Known limitations, in plain words**
- **Scores are not real.** A real mine shown as "HIGH risk" is a demo value, not a finding about
  that mine.
- **Per-mine production is estimated.** It follows each mine's share of its company's capacity,
  not its own reported output.
- **Inspection rates are American.** No Indian dataset of inspection outcomes was available.
- **Minor accidents are assumed.** They are 3 × serious ones: DGMS publishes no count we could
  download.
- **Some laws are still open.** The EPF Act's status, a coal production reporting duty and a
  carbon monoxide limit are marked TODO-VERIFY.
- **Translations are unreviewed.** The glossary and grievance texts need a native speaker's review.
- **Air-quality redistribution is unconfirmed.** OpenAQ gives no licence for these stations, so
  the daily values are kept out of the repository.

**Suggested dashboard footer:** "Demo data: synthetic, calibrated to public statistics — see DATASETS.md".

---

## Rule: mine locations (decided 2026-09-25, applies from stage D3)

The 74 mines come from the prototype's synthetic seed, so a mine name is not evidence that a real
mine exists. `reference/mines.csv` (stage D3) therefore places mines as follows:

1. **Matched with good confidence to a real mine** — by fuzzy name + state against the Global
   Coal Mine Tracker, or failing that Wikidata — gets that source's coordinates,
   `location_quality = exact_gem` or `wikidata`, and `is_demo_mine = false`.
2. **Anything else** gets `location_quality = district_centroid` and `is_demo_mine = true`.
3. **A fictitious mine is never given real-looking exact coordinates.** A district-centroid point
   is the centroid of the mine's district polygon, rounded to 2 decimal places (about 1 km), so it
   cannot be mistaken for a surveyed mine location.

"Good confidence" is a `match_confidence` threshold chosen and justified in stage D3. Every match
below it is reviewed and listed at the end of that stage, not silently accepted or dropped.

**How D3 applied it** (see the `mines.csv` card): the threshold is 0.85, which only a unique name
hit with the same owner and district reaches. Two additions: `approx_gem` for GEM rows that GEM
itself marks "Approximate", and, for a district created after 2011, the district-level point is
Wikidata's coordinate for that district (rounded to 2 dp), because the only polygon available is
the older, larger 2011 district, whose centroid can fall outside the new one.

Not to be confused with `demo_named` in `mines_base.csv`, which marks the five mines the demo
script is built around. The two are independent: a named demo mine may still turn out to have
no real counterpart (`is_demo_mine = true`).

---

## `reference/mines_base.csv`

| | |
|---|---|
| **Kind** | Repo seed — the prototype's own **synthetic** demo data, copied as-is |
| **Source** | S01, the existing repo seed (`data/reference/prototype_seed/`), read-only |
| **Produced by** | `data/scripts/extract_mines_base.py` (stage D0; `run_data.bat setup`) |
| **Rows** | 74 — one per seeded mine |
| **Checksum** | `9ac697e189e00847d267be3401801b0cac48b6a7293ad2261bbd06c7cae3de51` (SHA-256) |

**Inputs** (SHA-256, commit `2509d16`):

| File | SHA-256 |
|---|---|
| `data/reference/prototype_seed/mines.json` | `951ce401c70a25fbcfe5c976514241997846f9f5cfeb2fb115738b9beb3d113e` |
| `data/reference/prototype_seed/users.json` | `3a74a2328805ae26667f6c24ebe4895da0c11136aa5d5a80a49ea8ff3e309723` |
| `data/reference/prototype_seed/violations.json` | `ce6f2c9775025c2446deead7bd6d9d6896230bae8bd44adc93335e279feaabe4` |
| `data/reference/prototype_seed/sensor_readings.csv` | `49f58cfe9e3ab5e3a5dd146e1614da53c561bb619b5b8eecab6e53da6b1df2c0` |

**Columns**

| Column | Meaning |
|---|---|
| `id`, `code`, `name`, `location`, `district`, `state`, `region`, `operator` | Every field of `mines.json`, unchanged. `operator` is the operating company as a name string. |
| `head_email` | The mine head's login from `users.json`. Passwords are deliberately not copied. |
| `demo_named` | `true` for the five mines the demo script is built around (JH-DHN-01, MP-SGR-02, CG-KRB-03, WB-RNG-04, OD-TLC-05). |
| `seed_violations` | Open PPE violations at seed time (the seed has no resolved flag, so all are open). |
| `seed_readings` | Seeded sensor readings for the mine. |
| `seed_breaches` | Seeded readings strictly above the backend's demo thresholds (gas 50 ppm, dust 10 mg/m³, temperature 45 °C). Historical; none count toward today's score. |
| `demo_score` | The score on the demo board today: `100 − 5 × seed_violations`. Breaches count only inside the backend's 12-second window and every seeded reading is days old, so none are in it. |
| `demo_risk_level` | LOW ≥ 80, MEDIUM ≥ 50, else HIGH — from the rounded score, as the backend does it. |

**How it was checked.** The extraction refuses to write unless its result matches the baseline
in `docs/demo-script.md`: 74 mines, average 83.2, 6 High / 21 Medium / 47 Low, and the named mines
at 100 / 80 / 70 / 60 / 45. It was also compared field by field against the backend's database
(the prototype's `backend/smartmine.db`, opened read-only, pre-flight clean; the prototype was removed in Phase 8): all 74 mines match on every column,
the head login, the open-violation count, the score and the band. Two runs produce byte-identical
files.

**Limitations**

- **Not real-world data.** The mine names, violation counts and scores were created for the
  prototype demo with a fixed random seed. A name resembling a real mine does not make it one.
  Which of the 74 correspond to real mines is established in stage D3 by matching against the
  Global Coal Mine Tracker, not assumed here.
- **No coordinates, no areas.** The seed has neither; stage D3 adds both, each with a stated
  quality level.
- **`operator` is a company name, not a key.** Stage D3 turns it into `companies.csv` IDs. Three
  values are not Coal India subsidiaries: NLC India Ltd, Singareni Collieries Company Ltd, and
  "Coal India Ltd" itself (five mines).
- **The demo weights and thresholds are prototype settings, not legal limits.** They are recorded
  in `config.yaml` under `repo_demo_baseline` only to reproduce the board, and must not be used as
  regulatory values (brief rule 7).
- **A snapshot of commit `2509d16`.** If the backend seed or scoring changes, re-run
  `run_data.bat setup`; the extraction stops rather than writing a wrong file if the documented
  baseline no longer matches.

---

## `reference/companies.csv`

| | |
|---|---|
| **Kind** | **Real** (company structure from published sources) |
| **Sources** | S06 Ministry of Coal, Monthly Coal Statistics Aug'2026, page 1; S05 the companies' own pages |
| **Produced by** | `data/scripts/clean_companies.py` (stage D3; `run_data.bat clean`) |
| **Rows** | 10: CIL, its 7 subsidiaries in the seed (ECL, BCCL, CCL, NCL, WCL, SECL, MCL), SCCL, NLC |
| **Checksum** | `f4ac86e4c123046a611c27d5ae9080de96df58d9722d5f98ea57b2bd7e855d72` |

**Method.** The CIL → subsidiary links come from one table: on page 1 of the monthly statistics
the "CIL" row equals the sum of the ECL, BCCL, CCL, NCL, WCL, SECL, MCL and NEC rows (52.54 Mt in
August 2026). The script re-adds the rows and refuses to write if they do not sum. SCCL is listed
outside that total. Where a company's own page describes it ("A Subsidiary of Coal India
Limited", "A Government Company"), the quote and the file are in `self_description`.

Types that are not CIL subsidiaries are quoted from the company's own page, and the script checks
the sentence is still on the saved page:
- **SCCL `state_jv`**: "jointly owned by the Government of Telangana and Government of India on a
  51:49 equity basis" (scclmines.com, company_about-us.asp).
- **NLC `psu`**: "NLCIL is a Navratna Government of India Enterprise, under the administrative
  control of Ministry of Coal" (nlcindia.in, corporateprofile.html).

**Limitations**
- NEC is in the CIL total but is not a seed operator, so it has no row. The seed operator
  "Coal India Ltd" (5 mines) is kept as CIL itself.

---

## `reference/areas.csv`

| | |
|---|---|
| **Kind** | **Real** (as each company publishes its areas) |
| **Sources** | S05 saved pages: BCCL, WCL, MCL, SCCL; district names checked against S03 and S04 |
| **Produced by** | `data/scripts/clean_areas.py` (uses `district_names.py`) |
| **Rows** | 43: BCCL 12, WCL 10, MCL 9, SCCL 12; 19 with a district |
| **Checksum** | `a4347a575ef433763fbe91ab309dc049d411a58308b14813a9c214bc1430e587` |

**Method.** Area names are read from the saved pages; `source_text` keeps the exact words. A
district is recorded only when the source names it: SCCL writes "<district> Dist."; for WCL a
district name of Maharashtra or Madhya Pradesh must appear in the office address. Names are
matched against DataMeet 2011 and Wikidata's district list, not from memory: exact, then a unique
prefix ("Jayashankar" → Jayashankar Bhupalpally), then a flagged spelling variant. When an SCCL
district name matches no district, the district is taken from GEM rows of an SCCL mine named after
the area's place, cited by GEM ID.

**Limitations**
- **No ECL, CCL or SECL areas.** Their sites were unreachable, so manual step 4 was skipped. The
  fallback, the Coal Directory 2024-25 (S06), was checked chapter by chapter: none of the 11 has an
  area-wise table (the royalty sheets are company × state only). NCL's saved page is an empty
  JavaScript shell; NLC India publishes mines, not areas.
- **No district for BCCL and MCL areas** (their pages name none), nor for WCL Ballarpur, Majri and
  Wani North (their addresses name towns only).
- **Two SCCL districts rest on secondary evidence.** "Komaram Bheem Dist." is taken as Kumaram
  Bheem Asifabad (spelling variant, fuzzy 92; accepted by the user 2026-09-26). For Sathupally,
  the page says "Kothagudem Dist.", which is not a district name. The district, Khammam, comes from
  GEM M0546 "JVR I Coal Mine" (AKA "Sathupalli", an SCCL mine), whose Prefecture, District is
  Khammam. It is not assumed to be Bhadradri Kothagudem.
- The SCCL page numbers its rows 1–10, 12, 13 (there is no row 11), so there are 12 SCCL areas, not 13.
- Excluded because they are not areas: "Block-E OCP" (a mine) and "CWS IB Valley" (a workshop).

---

## `reference/district_crosswalk.csv` and `reference/district_boundaries.geojson`

| | |
|---|---|
| **Kind** | **Real** (published boundaries and district lists) |
| **Sources** | S03 DataMeet Census 2011 districts (CC BY 2.5 IN); S04 Wikidata districts; S02 GEM; S05 SCCL page |
| **Produced by** | `data/scripts/clean_district_boundaries.py` |
| **Rows** | Crosswalk: 38 seed (district, state) pairs, all resolved. GeoJSON: 35 polygons, 116 KB |
| **Checksums** | crosswalk `3de294527091bb20020ca1dea4f7f543528717eb34d2bcd7b869461e3d4e2c01`; geojson `39be74f2d9f1b50810012c66e14e10b885fb1b27e187752d29abb1635da71b3f` |

**Method.** Each pair is linked to one 2011 polygon; the `evidence` column says how:

| `relation` | Count | Meaning |
|---|---|---|
| `same` | 29 | The name is a 2011 district of the same state |
| `respelled` | 3 | Angul → Anugul, Bardhaman → Barddhaman, Purulia → Puruliya: the Wikidata district's coordinate lies in a 2011 district with a similar name |
| `split` | 3 | Bhadradri (Kothagudem), Mancherial, Peddapalli: created after 2011; the Wikidata coordinate lies in Khammam, Adilabad and Karimnagar |
| `not_a_district → split` | 3 | Asansol and Raniganj → Paschim Bardhaman (GEM: 5 of 6 mines with Location "Asansol"; 21 of 28 West Bengal mines with Coalfield "Raniganj"); Ramagundam → Peddapalli (SCCL: Ramagundam-I/II/III Areas are in "Peddapalli Dist.") |

`point_lon`/`point_lat` is the district-level point used for demo mines: the 2011 polygon's
centroid (computed in EPSG:7755), or for a split district Wikidata's coordinate of the current
district. Both are rounded to 2 dp. All 74 mine points were checked to lie inside their 2011
polygon. The GeoJSON holds only the 35 polygons that contain seed mines, simplified to 0.005°
(about 500 m), with coordinates cut to 5 dp.

**Limitations**
- **2011 boundaries.** Districts created later (Telangana 2016, Paschim Bardhaman) appear inside
  their larger 2011 parent, and the parent's `state_2011` for Telangana is Andhra Pradesh.
- **A split district's point is a Wikidata coordinate**, usually its headquarters town, not a
  centroid. At 2 dp and labelled `district_centroid`, it does not pose as a mine location.
- Asansol and Raniganj are placed by where most GEM mines of that name lie, not by an
  administrative record. Raniganj is a GEM *coalfield* spanning more than one district.
- Bardhaman is kept as the undivided 2011 district (Wikidata still lists Q808023 beside Purba and
  Paschim Bardhaman).

---

## `reference/mines.csv` (and `reference/mine_match_candidates.csv`)

| | |
|---|---|
| **Kind** | Seed mines (**synthetic**) with **real** reference fields where a real match exists |
| **Sources** | `mines_base.csv` (S01), GEM Global Coal Mine Tracker Aug 2026 (S02, CC BY 4.0), Wikidata coal mines (S04), the three files above |
| **Produced by** | `data/scripts/clean_mines.py` |
| **Rows** | 74 mines; 5 candidate rows |
| **Checksums** | mines `ccee4180e6152599275b205ee98bb6191118eba2ab1982921d7a87ed02b894d2`; candidates `31f6b1c95edded8faa2d5042fb656a0901eec19c71c4754318688a2289154065` |

**Method.** Seed names are templates ("<District> <suffix>"), so only words left after removing
generic mining words, the district and the state are looked up. They are matched in GEM (same
state, Mine Name and AKAs, word fuzzy ≥ 90), then in Wikidata if GEM gives no candidate.

`match_confidence` = name score × 1/candidates × owner factor (1.0 when the seed operator is a GEM
owner or, for CIL, the parent; else 0.5) × district factor (1.0 same district, 0.9 not given,
0.6 different). A match is accepted at **≥ 0.85**: a unique hit whose owner and district both agree.
A word that is a GEM coalfield name in that state (Jharia, Talcher) is capped at 0.5, because it
names a coalfield, not a mine. Every candidate is in `mine_match_candidates.csv`.

| Result | Mines |
|---|---|
| `exact_gem`, `is_demo_mine = false` | 1: CG-KRB-03 "Korba Gevra Expansion" → GEM M0527 Gevra Coal Mine (SECL, Korba), confidence 1.0 |
| `district_centroid`, `is_demo_mine = true` | 73 |

`type` comes from GEM Mine Type for the matched mine. Otherwise it comes from the seed name
("Opencast"; "Underground" or "Deep Shaft" → underground; `type_source` says which), or it is
empty. `capacity_mtpa` and `status` come from GEM only, so they are empty for demo mines. `area_id`
is set only when exactly one area of the mine's company has the mine's current district as its
published district: 1 mine (TS-MAN-62 → SCCL-SRIRAMPUR). `operator_in_state_per_gem` counts GEM
mines of the seed operator in the seed state.

**Limitations**
- **73 of 74 mines are demo mines.** Their names carry no real mine name, so no match is possible.
  Mines in the same district share one point; a map should cluster them, not scatter them with
  invented offsets.
- **The seed's operator–state pairs are mostly implausible.** For 46 of 74 mines, GEM lists no mine
  of that operator in that state (e.g. SCCL in Jharkhand, NCL in Assam, BCCL in Jammu and Kashmir;
  GEM has no mine in J&K at all). The operator is kept as the seed has it, because the demo depends
  on it. It must not be presented as real.
- Close calls that were not accepted: JH-DHN-01 ("Jharia": Rajapur-South Jharia, M2513, BCCL,
  Dhanbad, 0.5) and OD-TLC-05 ("Talcher": Talcher UG M1746 or Nandira M2695, both MCL, Angul, 0.5).
- The Gevra match takes GEM's operating capacity (70 Mtpa). The seed's word "Expansion" is not
  matched to any GEM expansion phase.

---

## `reference/mines_real.csv` and `reference/mine_code_mapping.csv`

| | |
|---|---|
| **Kind** | **Real** mines (names, companies, places, coordinates, type, capacity) with **synthetic** demo scores |
| **Sources** | S02 GEM Global Coal Mine Tracker Aug 2026 (CC BY 4.0); S06 Ministry of Coal msg-Aug26.pdf p.1; S03/S04 district lists; `companies.csv`, `areas.csv`, `mines_base.csv`, `mines.csv` |
| **Produced by** | `data/scripts/clean_mines_real.py` |
| **Rows** | 74 mines; 74 mapping rows |
| **Checksums** | mines_real `241c905cf1f309b0b17db567e1b584ea162cb03e682d88515535fed0f549446d`; mapping `4328244b2eb8a2528a94258980fa60c74dff6bcc46a8caf519324eeadb39771f` |
| **Status** | Proposed replacement for the backend seed, **pending team approval** (see `data/HANDOFF.md`). `mines.csv` stays as the fallback. |

**Which mines.** Candidates are GEM India rows that meet all of these:
- status "Operating", with coordinates;
- the first-listed owner is a company in `companies.csv` (North Eastern Coalfields counts as CIL:
  NEC has no row there, and the Ministry table sums it inside CIL).

A candidate is dropped when GEM contradicts itself or another source:
- its production exceeds its company's annualised Ministry figure (Kakri: 2.7 Mt as NEC, whose
  whole output is about 0.24 Mt a year);
- its point is over 20 km outside the state GEM names (Barsingsar);
- the district GEM names does not even touch the 2011 district containing the point (Kondapuram,
  Vakilpalli).

Candidates rank by exact location first, then capacity.

**How many per company.** Seats follow Apr–Aug 2026 production in the Ministry of Coal
company-wise table (largest remainder), with at least one per company. A company never gets more
seats than it has candidates:

| Company | Apr–Aug 2026 (Mt) | Seats | Underground or mixed |
|---|---|---|---|
| MCL | 69.89 | 18 | 4 |
| SECL | 65.46 | 17 | 12 |
| NCL | 49.47 | 9 (all its candidates) | 0 (GEM lists none) |
| CCL | 29.78 | 8 | 1 |
| WCL | 22.92 | 6 | 2 |
| SCCL | 21.18 | 6 | 3 |
| ECL | 18.75 | 5 | 4 |
| BCCL | 11.21 | 3 | 1 |
| CIL (its own row, NEC) | 0.03 | 1 | 0 |
| NLC (not in the table) | – | 1 | 0 |

Each company's underground share follows GEM's mix among its candidates. Overall: 47 opencast,
21 underground, 6 mixed. All 74 have `location_quality = exact_gem`.

**Demo slots.** The five named demo mines keep their code, login, score and band. Each gets a real
mine of the same operator in the named coalfield: the one D3 already matched (Gevra), else the
best-ranked in the seed district:

| Code | Coalfield | Real mine | Score | Band |
|---|---|---|---|---|
| JH-DHN-01 | Jharia | Moonidih (BCCL, Dhanbad, underground) | 100 | LOW |
| MP-SGR-02 | Singrauli | Jayant (NCL, Singrauli) | 80 | LOW |
| CG-KRB-03 | Korba | Gevra (SECL, Korba) | 70 | MEDIUM |
| WB-RNG-04 | Raniganj | Sonepur Bazari (ECL, Paschim Bardhaman) | 60 | MEDIUM |
| OD-TLC-05 | Talcher | Bhubaneswari (MCL, Angul) | 45 | HIGH |

**Codes, logins, scores.** Every seed slot (id 1–74) is paired with one real mine, in this order:
demo slots, then same company and state, same company, same state, then the rest. The real mine
takes the slot's id, `demo_score`, `demo_risk_level` and `seed_violations`. That keeps the band
split at **6 High / 21 Medium / 47 Low** and the average at 83.2, and `100 − 5 × seed_violations`
still gives the score. `score_note` says on every row that the score is a demo value, not an
assessment. Codes follow the seed format `<state>-<first 3 letters of district>-<id>` with the
seed's state prefixes. Logins follow `head.<code>@coalmine.in`; 10 codes, including the 5 demo
codes, are unchanged. `mine_code_mapping.csv` lists every old → new code with the reason.

**District and area.**
- **District.** It comes from the 2011 polygon that contains the GEM point. GEM's own district
  text is used when it names that district or one carved out of it. When the 2011 district has
  since been split and nothing names the current one, `district_basis = 2011` (12 mines, in
  Barddhaman, Chhindwara, Karimnagar, Khammam, Koriya and Surguja).
- **Area.** It is set in two ways (`area_method`), 11 mines in total (10 by name, 1 by district):
  - **name** (an addition to the requested rule): the mine's name contains exactly one area name
    of its company, e.g. Lingaraj → MCL-LINGARAJ or Ramagundam III → SCCL-RAMAGUNDAM-III, and that
    area's published district, if any, is consistent with the point;
  - **district** (the requested rule): the only area of its company whose published district is
    the mine's current district.

**Limitations**
- **Scores are not real.** A real mine shown with a HIGH demo band is not a finding about that mine;
  the UI must label scores as demo values (HANDOFF.md).
- **GEM is the only source for each mine.** Capacity and production are GEM's figures and years
  (`production_year`), not the Ministry's. Coordinates are GEM's "Exact" points, not surveyed
  boundaries.
- **Excluded by design.**
  - NLC's Neyveli lignite mines (Tamil Nadu) have no seed state prefix; NLC's seat is its Odisha
    coal mine, Talabira II & III.
  - ECL/CCL/SECL mines get no area (no area pages), apart from any that were name-matched.
- Border cases are noted in `district_method`, and the point's state or district is used. GEM names
  a neighbouring district for 8 mines, often the one the current district was carved from
  (Amlohri: "Sidhi", Rajnagar: "Shahdol"). GEM names a state across the border for 3 mines
  (Dudhichua, Bina, Haldibari).
- NCL has 9 seats rather than 12 because GEM lists only 9 operating NCL mines with usable rows.

---

## `reference/production_company_monthly.csv` and `reference/production_company_annual.csv`

| | |
|---|---|
| **Kind** | **Real** (Ministry of Coal published figures) |
| **Sources** | S06: Monthly Coal Statistics (Jun, Jul, Aug 2026; Sep 2025), page 1 "Coal Production" and the "Lignite Production" page; Coal Directory of India 2024-25, cdchap2.xlsx Tables 3.11, 3.20, 3.22 |
| **Produced by** | `data/scripts/clean_production.py` |
| **Rows** | Monthly: 94 rows. Annual: 33 rows (FY 2022-23 to 2024-25) |
| **Checksums** | monthly `0ab7bda6a903ea14d7481336c11ce34ad09d03137ed9deca6daf09c47a19d416`; annual `a86ec6fd510e38da8ab5ac870ed9b2b90f51a2f1a6969a1b185bb1d59662776a` |

**Method.** Each monthly PDF gives the report month and the same month a year earlier. So the
four PDFs give eight months: Sep 2024; Jun, Jul, Aug and Sep 2025; Jun, Jul and Aug 2026. Coverage
is every CIL company, NEC (kept as CIL's own row), the CIL total, SCCL, captives/others and NLC
lignite. Every row carries its file, page, table and column. The parser removes the printed
growth figures (each follows an arrow), then reads the remaining cells by count. It refuses to
write unless all three checks pass:
- achievement % matches month ÷ target;
- the eight CIL rows sum to the CIL total, in both months of every report;
- July and August 2026 equal the difference between consecutive year-to-date figures.

Company overburden is published only yearly (Coal Directory Table 3.22), so it is in the annual
file, with opencast/underground splits (Table 3.20) and totals (Table 3.11).

**Limitations**
- **September 2026 is not published yet.** September 2025 is the seasonal stand-in for the end of
  the D4 window (2026-06-28 to 2026-09-25) and must be labelled as such.
- Monthly figures for the current year are provisional; the year-earlier figures are as reported a
  year later.
- Overburden in Table 3.22 is labelled "Qty. in MT" as published (often reported elsewhere in
  million cubic metres). NLC's 2024-25 value, 0.020 with a stripping ratio of 0.00, is almost
  certainly a unit slip in the source and is kept as published.
- The top-35 mine pages (production and OB per mine) are not parsed yet.

---

## `reference/accident_causes_dgms.csv`

| | |
|---|---|
| **Kind** | **Real** (DGMS statistics) |
| **Source** | S07: DGMS, "Key Evaluation of Trends in Coal Mine Accidents" (sanket0404_2024.pdf), Table 2.9 (PDF p.33) and Table 2.10 (PDF p.34) |
| **Produced by** | `data/scripts/clean_accidents.py` |
| **Rows** | 210: 21 rows (17 causes, total, 3 places) × 10 years (2013–2022) |
| **Also** | `reference/dangerous_occurrences_dgms.csv`: Table 2.6 (PDF p.30), dangerous occurrences by 18 causes × 10 years; causes checked to sum to the Total row (added for the incident table) |
| **Checksum** | `1f94fab6a24664e4be247e6d368a238d773794a49538736f217da212b50b0997` |

**Method.** Each cell is "accidents(persons)". The PDF text breaks numbers with stray spaces
("8(1 3)"), so spaces are removed before the ten year cells are read. The script checks, for
every year and both tables, that the causes sum to TOTAL and that below ground + opencast + above
ground = TOTAL.

**Limitations**
- Coal mines only, 2013–2022. The 2025 Bulletin covers part-years only, and the 2014 Annual Report
  adds 2010–2012; neither is used.
- `persons_seriously_injured` also counts serious injuries in fatal accidents (the table's note).

---

## `reference/crosswalk_msha_india.csv`

| | |
|---|---|
| **Kind** | **Hand-built** by the dataset track, with a rationale per row |
| **Sources** | 30 CFR subpart ranges, checked against the Cornell LII copy of parts 56, 57, 71, 75 and 77 (2026-09-26); DGMS causes from `accident_causes_dgms.csv` |
| **Produced by** | `data/scripts/clean_crosswalk_msha.py` |
| **Rows** | 130: 113 MSHA section ranges and parts, 17 DGMS causes |
| **Checksum** | `3429cd0998f609cba7b8ae63c74a7fd3cd096de43e2b333dc7f7ed6c9e258466` |

**Method.** MSHA rows map a range of 30 CFR sections (a subpart, a whole part, or single
sections in the "Miscellaneous" subparts) to one Indian category. DGMS rows map each DGMS cause.
`fit` grades the match: strong, partial or weak. Narrower ranges come first; the first match wins.

**Categories.** The brief's ten, plus **machinery**, added in D3. Machinery other than transport is
a leading DGMS fatal cause and a large MSHA group, and none of the ten fits it.

**Limitations**
- Several US standards have no close Indian heading:
  - travelways and surface installations → welfare (weak);
  - mine emergencies, escapeways and communications → fire (partial);
  - training → documentation (partial).
- DGMS "Miscellaneous" is left unmapped.
- Part titles outside 56/57/71/75/77 and single-section titles were written by hand, not checked
  against the CFR.

---

## `reference/msha_rates.csv`

| | |
|---|---|
| **Kind** | **Real** (US MSHA enforcement data) - a calibration base, not Indian data |
| **Source** | S08: MSHA Open Government Data (Mines, Inspections, Violations, Accidents), filtered in D2 to coal |
| **Produced by** | `data/scripts/clean_msha_rates.py` (uses `crosswalk_msha_india.csv`) |
| **Rows** | 13,310 mine-years from 2,380 coal mines, 2017–2025 |
| **Checksum** | `ee4cad25adb417d3bd2f8ea77c36ef5056a9a2f8834cbf83384fb79f135f838a` |

**Method.** One row per mine and calendar year with at least one inspection. It holds:
- inspections (all, and regular), inspection hours;
- violations: total, by Indian category via the crosswalk, and significant-and-substantial (S&S)
  count and share;
- accidents excluding no-injury reports and injuries to non-employees, with fatal and lost-time
  counts.

Over the file, violations split: ventilation/gas 23.3%, electrical 18.4%, machinery 12.8%,
transport/haulage 12.3%, fire 11.0%, roof/strata 6.9%, welfare 5.9%, documentation 4.6%,
environment 2.1%, PPE 1.7%, explosives 0.2%, unmapped 0.8%. The unmapped rows have no section
recorded. The S&S share is 19.0%.

**Limitations**
- US mines under US law; the generator must scale rates, not copy counts.
- `mine_type`, `state` and `employees_now` are the mine's current values, not per year.
- 2016 and 2026 are left out as partial years.

---

## `reference/env_stations.csv` and `reference/env_daily.csv`

| | |
|---|---|
| **Kind** | **Real** (CPCB continuous monitoring stations, via OpenAQ) |
| **Source** | S09: OpenAQ v3 (locations and daily aggregates), 2026-06-28 to 2026-09-25 |
| **Produced by** | `data/scripts/clean_env.py` |
| **Rows** | 14 stations (12 with data); 4,080 station-day-pollutant rows (PM10, PM2.5, SO2, NO2) |
| **Checksum** | stations `a731852b4c73bce456a79edae9ae46f8ff5050f1e06c333bf9e1bec8c2e5c8cc`. **`env_daily.csv` is not committed** (see below) |

**Method.** These are the 14 stations matched to the coalfield clusters in D2. Daily values are
OpenAQ's daily means, with min, max and percent coverage. Where a station has two sensors for a
pollutant, the more complete one is kept. Units are as OpenAQ returns them: µg/m³ for PM; SO2 and
NO2 mostly in ppb. Each station lists its three nearest mines in both rosters, with distances.

**Limitations**
- **Licence not stated.** OpenAQ returns `licenses = null` for all 14 stations, so
  `env_daily.csv` is kept out of git (`.gitignore`) until redistribution is confirmed; the clean
  stage rebuilds it locally. *TODO-VERIFY.*
- **Two stations have no data in the window:**
  - 854 "Chandrapur": last reading 2018;
  - 5611 "Chandrapur - MPCB": coordinates about 156 km from any mine, last reading 2022.
- Rows are not dropped for low coverage (`coverage_pct` is kept).

---

## `reference/legal_instruments.csv`

| | |
|---|---|
| **Kind** | **Real** (status of laws, from official texts) |
| **Sources** | S10: the four labour-code commencement notifications and the SS Code corrigendum; the OSH and SS Code texts; OSH (Central) Rules 2026; CMR 2017; draft CMR 2026; CPCB Pollution Control Law Series (2021) |
| **Produced by** | `data/scripts/clean_legal.py` (uses `legal_text.py`) |
| **Rows** | 17 instruments |
| **Checksum** | `7ef153680307d6b3e56eabbc211875682f8ed6aac3e7e7e4baa811c9e5f875fd` |

**Method.** Each row's status rests on one or more passages that the script finds verbatim in the
downloaded official text, after whitespace and dash normalisation. The row records the passage,
file and page. A row whose passage is not found is written as TODO-VERIFY instead; all 17 were
found.

**Status as recorded:**
- **In force:** the four labour codes (21.11.2025) and OSH (Central) Rules 2026 (08.05.2026).
- **Repealed:** the Mines Act 1952 and CLRA 1970 (OSH Code s.143(1)(c), (h)).
- **Superseded:** the Mines Rules 1955, MVT Rules 1966 and CLRA Central Rules 1971 (G.S.R. 345(E)).
- **Saved:** CMR 2017 (s.143(3)).
- **Draft:** CMR 2026, G.S.R. 67(E) of 28.01.2026. This refines D1's "31.01.2026", which is the
  date of the DGMS copy.
- **In force by absence of repeal:** the CMPF Act 1948 and the Water, Air and EP Acts.
- **TODO-VERIFY:** the EPF Act 1952.

**Limitations**
- **EPF Act.** It depends on serial (vi) of S.O. 2060(E) of 03.05.2023, which was not downloaded
  (manual step 6).
- **Absence-of-repeal statuses.** For the CMPF Act and the environmental Acts, the status rests on
  their absence from the two codes' repeal lists. Amendments after the CPCB 2021 compilation are
  not reflected.

---

## `reference/obligations.csv`

| | |
|---|---|
| **Kind** | **Real** (statutory duties quoted from official texts) |
| **Sources** | S10: OSH Code 2020; OSH (Central) Rules 2026 (G.S.R. 345(E)); Coal Mines Regulations 2017; Water Act 1974, Air Act 1981 and EP Rules 1986 as printed in the CPCB Pollution Control Law Series (2021) |
| **Produced by** | `data/scripts/clean_obligations.py` (uses `legal_text.py`) |
| **Rows** | 40: safety 13, environment 9, reporting 8, labour 5, health 5. 39 verified, 1 TODO-VERIFY |
| **Checksum** | `a0b909b5add7853715fccef243f041788f6578b068afc83dccaff964c3c04095` |

**Method.** The candidate passages were found by searching the downloaded texts, not from memory.
Every row carries its clause, PDF page and a verbatim passage, and the script checks each passage
is in the stated file before marking the row `verified = yes`. The repealed Mines Act, CLRA and
Mines Rules are never cited; CMR 2017 is cited because OSH Code s.143(3) saves it. The rows
include:
- **Contractor duties:** 5-year licence, renewal 30–90 days before expiry, work-order notice within
  15 days, wages by the 7th day, half-yearly return.
- **Worker duties:** refresher training every 4 years, reporting unsafe conditions.
- **Numeric limits** that D4 uses as sensor thresholds: inflammable gas 0.75% in return air and
  1.25% anywhere; respirable dust 2 mg/m³ (8-hour average); wet bulb 33.5 °C; plus coal-mine and
  national ambient air limits.

**Left out on purpose.** The CMR items that overlap the 2026 Rules or state no interval: reg.5
closure notice, reg.8 accident notice (the 2026 Rules r.7 timelines are used), reg.252 drills,
reg.179 safety lamps, reg.46 ventilator check.

**Limitations**
- **RPT-08 "Monthly coal production return" is TODO-VERIFY.** No downloaded source states a
  production-reporting duty; it is listed so the gap is visible.
- Where the rules name a Form but put its due date elsewhere, `note` says so.
- The environmental texts are the CPCB 2021 compilation; later amendments are not reflected.
- **A saved regulation can conflict with the Code.** CMR 2017 applies only "to the extent not
  contrary" to the OSH Code (s.143(3)); a conflict needs a legal reading, not a data rule.

---

## `reference/glossary.csv`

| | |
|---|---|
| **Kind** | **Drafted** by Claude for UI consistency - **not reviewed** |
| **Produced by** | `data/scripts/clean_glossary.py` |
| **Rows** | 54 terms × English, Hindi, Bengali, Odia, Telugu, Marathi |
| **Checksum** | `55a2ef7b36087d66d961c4ac7d8f29eb7e779bce071f4a98d8755ae174c844a6` |

**Method.** The terms cover the app's domains: mining, safety, health, roles, labour, governance,
production and environment. Where miners use the English word (dumper, overman, shotfirer), the
loanword is kept, sometimes with a native gloss.

**Limitations**
- Every row is `reviewed = false`. A native speaker with mining vocabulary must review each
  language before users see it.

---

## Calibration references added in D4

| File | Source | Used for |
|---|---|---|
| `reference/production_profile_2024_25.csv` | Coal Directory 2024-25, Table 3.7 (PT7): month-wise CIL / SCCL / All-India production | Seasonal profile for months with no published monthly figure |
| `reference/oms_company.csv` | Table 3.24 (PT24): production, manshifts and output per manshift by company × OC/UG, 2024-25 | Manpower per shift |
| `reference/company_capacity.csv` | GEM operating India mines by first-listed owner (NLC: coal only), capacity filled with the company median where unknown | Each mine's share of its company's output |
| `reference/mines_real.csv` gains `workforce`, `workforce_accuracy` | GEM "Workforce Size" (15 exact, 59 GEM estimates) | Mine size for inspection rates |

---

## `reference/state_boundaries.geojson` (Phase 5B)

| | |
|---|---|
| **Kind** | **Real** (published boundaries) |
| **Source** | S03 DataMeet maps, `States/Admin2` (the repository's non-district data: CC BY 4.0) |
| **Produced by** | `data/scripts/clean_state_boundaries.py` (stage D3, `run_data.bat clean`) |
| **Rows** | 36 features (states and union territories), 148 KB |
| **Checksum** | `6c7af545db57d2b77ef800fe737306398aac6bec5895de1d0dc61c666fd8522b` |

**Method.** Topology-preserving simplification at 2.5 km in EPSG:7755 (India LCC), back to
EPSG:4326, coordinates rounded to 3 dp (about 100 m). Property `state` is DataMeet's `ST_NM`;
`mines` counts the real roster's mines in the state. Every roster state has an outline. The file
is byte-identical across runs (sorted features, fixed formatting). Served by the API for the
offline map, so the map needs no internet.

**Limitations**
- **Simplified outlines** for a national dashboard map, not for measuring or legal boundaries.
- **As published by DataMeet**, simplified; the map is not an authoritative boundary source.

---

## `reference/map_districts.geojson` (Phase 5B)

| | |
|---|---|
| **Kind** | **Real** (published boundaries) |
| **Source** | S03 DataMeet maps, `Districts/2011_Dist` (Census 2011, CC BY 2.5 IN) |
| **Produced by** | `data/scripts/clean_state_boundaries.py` (stage D3, `run_data.bat clean`, after `clean_mines_real.py`) |
| **Rows** | 28 features (the 2011 districts containing the 74 mines of the real roster), 125 KB |
| **Checksum** | `ffa3f66b60425e1b2ea720e6ffd32299af5a59027a2ecd65be3af22471088157` |

**Method.** Each mine of `reference/mines_real.csv` is placed in the 2011 district that contains
its point; a point outside every polygon would join the nearest within 5 km (none does). The
districts found are simplified at 300 m in EPSG:7755 and rounded to 4 dp (about 10 m).
Properties are `district_2011`, `state_2011`, `censuscode_2011` and `mines` (the roster codes
inside). The API limits the outlines to the districts of the mines in the user's scope. The file is
byte-identical across runs.

**Why a second district file.** `reference/district_boundaries.geojson` is built from the
prototype seed's district names, for placing seed mines (`clean_mines.py`). The real roster
replaced some seed mines with mines in other districts (Giridih, Ranchi, Chhindwara, Paschim
Bardhaman, Surajpur), so 6 of the 74 mines had no outline on the map. This file follows the
roster the generator uses.

**Limitations**
- **Census 2011 districts.** Districts created later (Paschim Bardhaman, Surajpur) are shown
  inside their 2011 parent (Barddhaman, Surguja). The mine's own `district` field keeps the
  current name.
- **Simplified outlines** for a dashboard map, not for measuring or legal boundaries.

---

## `data/out/<preset>/` - generated operational data (stage D4)

| | |
|---|---|
| **Kind** | **Calibrated-synthetic**: generated; volumes and mixes are tuned to the real references above. Environment rows are `calibrated` (real station data plus noise) or `synthetic` |
| **Produced by** | `data/generators/generate.py` (`run_data.bat generate [small\|demo\|full]`), one module per table group |
| **Schemas** | `data/schema/*.yaml`, 25 tables; every CSV is checked against its schema as it is written |
| **Roster** | `config.yaml mine_roster: real` (default) or `seed`; both pass every check |
| **Determinism** | Seed 2026, one random stream per generator. Two demo runs are byte-identical (checked 2026-09-26) |
| **Not in git** | `data/out/` is gitignored; `_manifest.json` records rows, bytes and SHA-256 per table |

**Calibration and checks** (`_checks.json`; the stage fails if any check fails):

| Area | Method | Check (demo result) |
|---|---|---|
| Production | Company month from the Ministry figure. September 2026 is the labelled stand-in (September 2025). With no monthly figure (NLC coal; months outside the published eight), FY2024-25 × the Table 3.7 month share. Split by mine capacity ÷ the company's operating GEM capacity (`production_split`), then by day and shift. Overburden from company stripping ratios; manpower from output per manshift. | Complete company-months, roster grossed up by capacity coverage: within ±3 %. Pass, 20 of 20; exact by construction. |
| Sensors | Underground and mixed mines: CH4, CH4 return air, CO, dust, wet bulb, humidity every 15 min. Opencast: dust, wet bulb, humidity hourly. Daily cycle, seasonal factor, drift, rare spikes. | `breached` only from obligation-cited limits (SAF-11, HLT-04, HLT-05). Gas sensors at exactly the 27 underground and mixed mines. Pass. |
| Inspections | MSHA medians by type (UG 11 inspections a year, 4.25 violations per inspection; surface 3 and 2.45), scaled by (workforce ÷ MSHA p75 headcount)^0.5, clipped 0.5–2. Category mix 0.5 × MSHA + 0.5 × DGMS via the crosswalk. | Mix within 0.15 of target (pass, 0.094). Count within 25 % of expectation (pass, 1,379 vs 1,483). |
| Contractors | 5-year licence (LAB-02), 4-year VT refresher (SAF-04), annual medical (HLT-01). Fixed shares expired or expiring. | Every legal value cites a verified obligation. Pass. |
| Environment | Nearest CPCB station within 50 km with a reading that day, × lognormal noise (σ 0.10). Otherwise synthetic from the stations' fitted distribution. | Every row labelled. Pass: 44 % calibrated, 35 mines have a station. |
| Grievances | Six-language templates using glossary terms; language by the mine's state. SLA from product settings. | 84 % in the region's language. Pass. |
| People | Faker `en_IN` first names + regional surnames; masked identifiers (`REG-XXXX-0417`, `XXXXXX1234`). | Masking pass. |
| Dates | Nothing after the window's end except deadlines and validity dates. | Pass. |
| **Demo scores** | The backend's current formula (100 − 5 × open violations − 3 × breaches in the 12-second window) applied to the generated data. Open violations per mine = the roster's `seed_violations`. | Every mine matches; 100/80/70/60/45; 6/21/47; average 83.2. **Pass.** |

**Limitations**
- **All operational rows are synthetic.** Only the production totals, the environmental station
  values and the rates and mixes are tied to real figures.
- Per-mine production follows GEM capacity shares. It is not each mine's reported output (Ministry
  top-35 mine pages not parsed yet).
- **US rates.** Inspection and violation rates come from US MSHA data; the size scaling
  (square-root, clipped) is a modelling choice.
- **Sensor shapes are modelling choices,** not measurements.
- **The CO sensor has no verified limit** (TODO-VERIFY), so CO readings never breach.
- **Grievance texts and the glossary are unreviewed drafts.**

---

## Injected scenarios and validation (stage D5)

| | |
|---|---|
| **Kind** | **Synthetic, labelled.** Patterns injected into the generated data, with ground truth |
| **Produced by** | `data/generators/inject_scenarios.py`, run inside `generate.py` before alerts are derived |
| **Outputs** | `out/<preset>/scenario_label.csv` (schema `scenario_label.yaml`), `out/<preset>/scenario_expectations.json` |
| **Validated by** | `data/generators/validate.py` (`run_data.bat validate [preset]`) → `out/<preset>/_validation.json` |

**Scenarios** (demo preset, real roster; dates move with the window):

| Code | Polarity | Mine | Dates | What is injected | Measured |
|---|---|---|---|---|---|
| S1 strata repeat + incident | positive | OD-TLC-05 Bhubaneswari (HIGH demo) | 06 Aug – 19 Sep | 5 roof/strata violations over 38 days (resolved), then a dangerous occurrence (fall of sides) on 18 Sep, then 1 strata violation still open | 5 repeats in 38 days; incident after the last; 1 open after it |
| S7 late corrective actions | positive | OD-TLC-05 | 06 Aug – 25 Sep | Every resolved corrective action at the mine closed 4–18 days after its due date | 10 of 10 late |
| S2 spike before inspection | positive | CG-KRB-03 Gevra (MEDIUM demo) | 04–05 Sep | Coal on 4 Sep = 2 × the trailing 30-day mean, manpower unchanged; regular inspection on 5 Sep | Ratio 2.0 |
| S3 gas flatline | positive | JH-RAM-06 Bhurkunda (mixed) | 26–28 Aug | CH4 constant at 0.22 % for 72 h (possible tampering) | 288 identical readings |
| S4 contractor missing wage/EPF | positive | MP-SIN-42 Block-B (most contract workers) | whole window | Contractor 32: wage register and EPF challan missing for every due month (38 documents); the most violations per active worker | 1.03 per worker vs next 0.68 |
| S5 night-shift compliance | positive | UP-SON-69 Bina | whole window | PPE detections concentrated in shift C (22:00–06:00 IST) | 87 % at night (fleet ≈ 33 %) |
| S6 grievance SLA cluster | positive | OD-SUN-07 Kulda | 31 Aug – 10 Sep | 7 wage and working-condition grievances in 10 days, all past SLA and escalated | 7 of 7 breached |
| N1 legitimate increase | **negative** | JH-CHA-09 Amrapali | 16–27 Aug | +25 % output with an approved crew addition: target revised, edit-log reason, no inspection within 5 days | Achievement vs revised target 0.93 |
| N2 grievance burst within SLA | **negative** | WB-BAR-08 Jhanjra | 21–26 Aug | 6 grievances in 5 days, all resolved inside SLA | 0 breached |
| N3 night maintenance | **negative** | OD-ANG-10 Hingula-II | whole window | Shift C output −40 % (planned maintenance, moved to A/B); compliance unchanged | C share 0.18; night violation share 0.50 |

**Placement.** The owner's rules: S1 and S7 on the HIGH demo mine, S2 on a MEDIUM demo mine,
S3 on an underground or mixed mine, S4 at the mine with the most contract workers, everything
else and all decoys on non-demo mines. A preset without those mines (small: 5 named mines) falls
back to the nearest eligible mine.

**Demo scores survive injection.**
- Scenarios work through historical rows. S1's open strata violation is balanced by resolving one
  existing open violation at the same mine, so the open count is unchanged.
- `generate.py` scores every mine before and after injection and fails if any score moves.
- Production changes keep company-month totals exact.

**Validation results (final, 2026-09-26)** - `validate.py` checks V1–V11

| Run | Checks passed | Determinism (three runs) |
|---|---|---|
| small, real roster | 12 of 12 (band and average checks need all 74 mines) | Byte-identical |
| demo, real roster | 14 of 14 | Byte-identical |
| full, real roster | 14 of 14 | Byte-identical |
| demo, seed roster | 14 of 14 | Byte-identical |
| online, real roster (2026-09-30) | 16 of 16 | Byte-identical |

**The `online` preset (2026-09-30).** The same data as `demo` with 52 days instead of 90 (window
2026-08-05..2026-09-25), for the free online database (500 MB; `docs/DEPLOYMENT.md`). Sensor
readings are most of the size: loaded, the database is 247 MB against 343 MB for `demo`. 52 days keeps
every scenario's timing as in `demo` (`inject_scenarios.py` switches at 30, 40 and 50 days), and the
same scores (100/80/70/60/45, 6/21/47, 83.2). `yii ai/evaluate --preset=online` finds all 7 scenarios
and ignores all 3 decoys; the laptop keeps `demo`.

**Changes after the D5 review (2026-09-26)**
- **Stock.** Closing stock is an exact running balance in tenths of a tonne. It is recomputed after
  injection, and V10 checks opening + production − dispatch = closing for every mine-day (worst
  gap 0.00 t).
- **N3.** Resolved night detections at the decoy mine are re-timed to day shifts, and resolved
  day-shift detections are added, until the night share is 0.33 (limit 0.40). Its open violations
  are untouched, so the score is unchanged.
- **S1.** The dangerous occurrence is now a row in `incident`: ground movement, no casualties (the
  DGMS definition), RPT-05, linked to the last strata violation. Its alert points at the incident.

**Limitations**
- **Scenario strength is deliberate.** The positives are clear signals for testing detectors, not
  subtle real-world cases.
- **S7 is mine-level.** The brief says "at one area", but OD-TLC-05 has no published area.

---

## `data/out/<preset>/incident.csv` (stage D5 follow-up, C23)

| | |
|---|---|
| **Kind** | **Calibrated-synthetic.** Counts follow DGMS rates; each event is generated |
| **Sources** | DGMS Sanket 2024: Table 2.9 (fatal), Table 2.10 (serious), Table 2.6 (dangerous occurrences, parsed into `reference/dangerous_occurrences_dgms.csv`, totals checked); Ministry of Coal national output; obligations RPT-03 / RPT-04 / RPT-05 |
| **Produced by** | `data/generators/gen_incidents.py`; the S1 scenario's dangerous occurrence comes from `inject_scenarios.py` |
| **Rows (demo)** | 83: 58 minor, 18 serious, 6 fatal, 1 dangerous occurrence (S1). 8 reported after 48 h |

**Method.**
- **Rates:** the latest three published years (2020–2022) for all Indian coal mines: 38.3 fatal,
  161.3 serious and 22.7 dangerous occurrences a year.
- **Scaling:** × days/365 × our roster's share of national coal output in the window (0.55 in the
  demo). Counts are Poisson draws.
- **Minor accidents:** 3 × serious, an assumption; DGMS names the category but no downloaded
  source counts it.
- **Placement:** mines weighted by workforce. Fatal and serious incidents are kept off the five
  demo mines, so the demo story stays coherent. Causes follow the DGMS mix for each severity.
- **Links:** `related_violation_id` is the most recent violation at the mine in a matching category
  within 60 days (60 % of the time).
- **Reporting:** 10 % of injury reports arrive after 48 h. Obligations: fatal RPT-03, injuries
  RPT-04 (OSH (Central) Rules r.7(2)), dangerous occurrence RPT-05.

**Checks.**
- Counts within Poisson bounds of the expectation (generate.py).
- The 48-hour flag, the obligation for each severity, and no casualties in a dangerous occurrence
  (validate V11).
- Incidents are not part of the backend score, so demo scores cannot move.

**Limitations**
- **Minor accidents rest on an assumed ratio.**
- **Fatal and serious incidents never land on the demo mines** (a presentation choice).
- **DGMS figures are national.** Per-mine incidence is not published, so the split by workforce is
  a model.

---

## `data/out/<preset>/obligation*.csv` - statutory obligation register (Phase 5B)

| | |
|---|---|
| **Kind** | **Catalogue: real** (`reference/obligations.csv`, cited). **Tasks and submissions: synthetic** |
| **Produced by** | `data/generators/gen_obligations.py`, run inside `generate.py` after the scenarios, on its own random stream (so no other table moves; evidence file rows are appended to `file.csv` after the existing ids) |
| **Tables** | `obligation` (40, the catalogue with its schedule), `obligation_applicability` (961 in demo), `obligation_task` (4,589: 4,506 calendar + 83 incident reporting tasks), `obligation_submission` (3,595); 3,595 file rows appended |
| **Validated by** | `validate.py` V12 (and V1-V4 for schema, keys, references, dates) |

**Which obligations get tasks.** Only verified obligations that apply to a mine and have a
calendar frequency: 15 of 40 (SAF-01, SAF-03, SAF-06, SAF-07, SAF-09, SAF-10, SAF-12, SAF-13,
HLT-01, HLT-03, ENV-03, ENV-05, ENV-06, ENV-07, RPT-06). On-event duties come from the events
themselves, continuous limits are monitored by the sensor rules, "every shift" (SAF-08) is too
fine-grained for a register, and contractor and worker duties belong to the contractor module.
**RPT-08 (TODO-VERIFY) never gets a task**; V12 checks it.

**Incident reporting tasks** (owner, 2026-09-28). Each incident gets one task for its reporting
obligation (`incident.obligation_code`: RPT-03 fatal, RPT-04 serious or minor injury, RPT-05
dangerous occurrence), linked by `obligation_task.incident_id`:
- period `INC-000123`, the incident's date (IST) as period start and end;
- due at the **law's time** (owner, Phase 6 approval; `gen_obligations.incident_deadline`):
  - RPT-05 *within twelve hours*: 12 h after the incident, `due_basis` law;
  - RPT-04 *within twelve hours after the completion of forty-eight hours* (of disablement,
    counted from the incident): 60 h, law;
  - RPT-03 *forthwith*: treated as immediate, with a 1 h grace
    (`product.obligation_schedule.forthwith_grace_hours`), `due_basis` product.

  The hours are the `legal` values of `rules.yaml`, each citing its obligation;
- `accepted` at the incident's `reported_at`, which is on time or late; the incident record is the
  report, so there is no upload and no review.

They are appended after the calendar tasks with no random draws, so no existing row moves. Demo: 83
tasks, 12 of them late:
- all 6 fatal notices (reported hours after the accident, against *forthwith* + 1 h);
- 6 of 76 injury reports (after 60 h);
- the one dangerous occurrence was reported in 3 h.

`incident.reported_within_48h` stays as recorded data. V12 checks one task per incident, its
obligation, the due time and basis, the status and `accepted_at`.

**Applicability** (modelling choices):
- SAF-07, SAF-09, SAF-12 (winding ropes, CO testing of depillaring districts, gas checks where
  electricity is used) apply only to underground and mixed mines - 81 rows.
- SAF-01 applies only to mines with 500 or more workers, as the obligation's own note says
  (r.14(1)) - 66 rows.
- Everything else applies to every mine.

**Due dates.**
- Where the rule names a date, that date (`due_basis` law, 222 tasks):
  - ENV-03: 30 September for the financial year ending 31 March;
  - RPT-06: 28/29 February after the calendar year.
- Otherwise the product setting `rules.yaml product.obligation_schedule`: 23:59 IST on the
  period's last day (`due_basis` product). Weekly means ISO weeks, fortnightly means ISO weeks
  (1, 2), (3, 4) and so on, and the others are calendar periods.

Tasks cover every period that started by the window end and is due on or after the window start,
so the current week, fortnight and month are there as open tasks.

**History** (modelling choices):
- Each mine has a filing discipline d ~ Beta(8, 2), nudged by its demo score (riskier mines miss
  more).
- A task is submitted on time with probability d, late with (1 - d) x 0.85, never with
  (1 - d) x 0.15.
- Reviews by an inspector or the government come 0.5-4 days after submission. 3 % of reviewed
  evidence is rejected with a reason, and 60 % of those are resubmitted.
- Unfinished past-due tasks are `overdue` (level 1), or `escalated` (level 2) once more than
  `escalate_after_hours` (168 h) late.

Demo result:
- tasks: 3,316 accepted, 849 open, 171 submitted, 43 overdue, 127 escalated;
- 108 rejected submissions and 554 late submissions;
- on-time-accepted share of due tasks per mine: mean 79.5 %, range 36-100 %.

**Limitations**
- **Every submission and review is synthetic.** Evidence files are metadata only (path,
  placeholder hash).
- **The applicability rules and the filing model are modelling choices,** not a record of any
  mine's compliance.
- **Product-setting due dates are not law**, and the API labels them as such.

---

## `grievance.tracking_code` (Phase 5B fix)

8 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no 0/O, 1/I/L), derived deterministically
from (seed, ticket number) by `common.tracking_code`. It uses no random stream, so adding it moved
no other value. These are **demo codes only**, like the demo passwords:
- the API stores an HMAC of each code, never the code itself;
- codes for new grievances come from a cryptographic random generator and are shown once.

V13 checks the format, the derivation and uniqueness.

