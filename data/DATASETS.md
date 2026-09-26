# Data cards

One card per dataset in `data/reference/` and `data/out/`. Each states whether the data is
**real** (taken from a published source), **calibrated-synthetic** (generated, but tuned to real
statistics) or **synthetic** (generated from documented assumptions), plus where it came from, how
it was made, and what it cannot be trusted for.

Cards are added stage by stage; stage D6 completes the set.

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
| **Source** | S01, the existing repo seed (`backend/data/seed/`), read-only |
| **Produced by** | `data/scripts/extract_mines_base.py` (stage D0; `run_data.bat setup`) |
| **Rows** | 74 — one per seeded mine |
| **Checksum** | `9ac697e189e00847d267be3401801b0cac48b6a7293ad2261bbd06c7cae3de51` (SHA-256) |

**Inputs** (SHA-256, commit `2509d16`):

| File | SHA-256 |
|---|---|
| `backend/data/seed/mines.json` | `951ce401c70a25fbcfe5c976514241997846f9f5cfeb2fb115738b9beb3d113e` |
| `backend/data/seed/users.json` | `3a74a2328805ae26667f6c24ebe4895da0c11136aa5d5a80a49ea8ff3e309723` |
| `backend/data/seed/violations.json` | `ce6f2c9775025c2446deead7bd6d9d6896230bae8bd44adc93335e279feaabe4` |
| `backend/data/seed/sensor_readings.csv` | `49f58cfe9e3ab5e3a5dd146e1614da53c561bb619b5b8eecab6e53da6b1df2c0` |

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
(`backend/smartmine.db`, opened read-only, pre-flight clean): all 74 mines match on every column,
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
| **Checksums** | mines_real `8a4e6dd4c022bed0fa33e33d2ab39b2828e87918347d9d0fe5d7cee5b32c48ca`; mapping `4328244b2eb8a2528a94258980fa60c74dff6bcc46a8caf519324eeadb39771f` |
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
