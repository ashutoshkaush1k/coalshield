# CoalShield — Dataset Track
### Task brief for Claude Code (downloads, cleaning, generation, validation)

The user will not write code or download files manually, except for the few sign-up steps listed in `data/MANUAL_STEPS.md` (which you create). You do everything else.

---

## 0. Ground rules (read first)

1. **Scope:** work only inside `data/` plus `.gitignore`, `.env.example` and a short README section. Do **not** touch `backend/`, `api/`, `ai-service/` or `frontend/` — those belong to the backend track (`CLAUDE_CODE_TASK.md`, `PLAN.md`).
2. **Branch:** create `feat/datasets` from `feat/governance-backend`. Commit at the end of every stage (`data D<n>: <summary>`). Never push.
3. **Stop after every stage**, report what was done, sizes on disk, anything that failed, and the exact command to re-run it. Wait for approval.
4. **Environment:** Windows. Use Python 3 in a dedicated venv at `data/.venv` (gitignored); pin dependencies in `data/requirements.txt`. Every script must run from PowerShell. Provide `data\run_data.bat` as the single entry point with stages: `setup`, `download`, `clean`, `generate`, `validate`, `all`.
5. **Downloads must be polite and legal:**
   - Only public pages and files. **Never** bypass logins, CAPTCHAs, paywalls or rate limits. If a source needs any of these, add it to `data/MANUAL_STEPS.md` with plain click-by-click instructions and a fallback, then continue with other sources.
   - Respect `robots.txt`, max ~1 request/second per host, a descriptive User-Agent, retries with backoff, resumable downloads, skip files already present with matching checksum.
   - Record the licence/terms of every source. If redistribution is not allowed, keep the raw file out of git (it is gitignored anyway) and note it.
6. **Never hard-code URLs from memory as facts.** The landing pages below are starting points; find the current file links on them, verify each with a HEAD/GET, and record the final URL, access date, size and SHA-256 in `data/SOURCES.md` and `data/sources.yaml`.
7. **Never invent facts.** Legal values (validity periods, due dates, thresholds) must cite a document and clause from `data/raw/legal/`. If not found, write `TODO-VERIFY` and list it.
8. **Reproducibility:** one global random seed (`2026`) in `data/config.yaml`; running `run_data.bat all` twice must produce byte-identical `data/out/`.
9. **Disk budget:** check free space first. Keep `data/raw/` under ~5 GB; skip optional large sources (marked OPTIONAL) if space is short and say so.

---

## Target folder layout

```
data/
  .venv/                 (gitignored)
  config.yaml            random seed, date range (default: last 90 days ending today), scale presets small/demo/full
  sources.yaml           machine-readable manifest: id, name, landing_page, final_url, target_path, licence, manual(bool), sha256, accessed_at
  SOURCES.md             human-readable version of the manifest
  MANUAL_STEPS.md        sign-ups / files the user must drop in, with fallbacks
  DATASETS.md            data card per output dataset
  schema/                one YAML per output table (column name, type, nullable, FK) — the CSV contract
  raw/                   downloaded files, one subfolder per source (gitignored except .gitkeep + README)
  reference/             cleaned real data as CSV (committed, keep each file < 20 MB)
  scripts/               download_*.py, clean_*.py
  generators/            gen_*.py, one per component
  out/                   generated CSVs matching schema/ exactly (gitignored; regenerated deterministically)
  validate.py
  run_data.bat
```

---

## Stage D0 — Setup
- Create the layout above, `.gitkeep` files, `.gitignore` entries (`data/.venv/`, `data/raw/**`, `data/out/**`, keep `.gitkeep` and READMEs).
- Create venv and `requirements.txt` (e.g. requests, pandas, pyarrow, openpyxl, pdfplumber, camelot-py or tabula alternative that works without Java if possible, PyYAML, Faker, shapely, geopandas only if it installs cleanly on Windows — otherwise use shapely + pyproj).
- Add to `.env.example`: `OPENAQ_API_KEY=`, `KAGGLE_USERNAME=`, `KAGGLE_KEY=`, `ROBOFLOW_API_KEY=` (all optional).
- Extract the **existing 74 mines** from the current repo seed (read `backend/` seed/fixtures; do not modify them) into `data/reference/mines_base.csv` with every field the seed has (name, state, operator, current demo values).
- Report free disk space and Python version. **Stop.**

## Stage D1 — Source discovery (no big downloads yet)
For each source below, visit the landing page, identify the exact files/endpoints, check access requirements and licence, and fill `sources.yaml`, `SOURCES.md`, `MANUAL_STEPS.md`. **Stop** and show me the manifest and the manual steps.

| ID | Source | Starting point | Target | Purpose |
|---|---|---|---|---|
| S01 | Existing repo seed | repo | `reference/mines_base.csv` | The 74 mines (done in D0) |
| S02 | Global Coal Mine Tracker (Global Energy Monitor) | globalenergymonitor.org → Global Coal Mine Tracker | `raw/gem_gcmt/` | Mine coordinates, owner, capacity, status, type. Download usually requires a short form → likely MANUAL; user drops the .xlsx here |
| S03 | DataMeet maps (district/state boundaries) | github.com/datameet/maps | `raw/datameet/` | District centroids as location fallback; state/district polygons for the GIS layer |
| S04 | Wikidata (SPARQL, no key) | query.wikidata.org | `raw/wikidata/` | Coordinates of coal mines / mining towns as second fallback |
| S05 | Company websites: Coal India Ltd and subsidiaries (ECL, BCCL, CCL, NCL, WCL, SECL, MCL, CMPDI), SCCL, NLC India | each company's official site | `raw/company_sites/` (saved HTML/PDF) | Area lists, subsidiary → area mapping |
| S06 | Ministry of Coal statistics | coal.gov.in → statistics / publications | `raw/moc/` | Provisional Coal Statistics, Coal Directory of India, monthly production summaries (company-wise, state-wise) |
| S07 | DGMS | dgms.gov.in → annual reports / statistics | `raw/dgms/` | Accident counts by cause, fatality and serious-injury rates in coal mines |
| S08 | US MSHA Open Government Data | arlweb.msha.gov → Open Government Data | `raw/msha/` | Mines, Inspections, Violations, Accidents datasets (zip, pipe-delimited). Large — download only these four |
| S09 | Air quality near coalfields (CPCB via OpenAQ) | openaq.org (API v3; free key) | `raw/openaq/` | Daily PM10/PM2.5/SO₂/NO₂ for stations nearest each mine cluster (Dhanbad, Asansol, Korba, Singrauli, Angul/Talcher, Chandrapur, Ramagundam, Neyveli). Key → MANUAL step; fallback: skip and synthesize with documented ranges |
| S10 | Legal texts | indiacode.nic.in, labour.gov.in, dgms.gov.in, moef.gov.in / egazette | `raw/legal/` | See legal list below |
| S11 | Environmental clearance letters (OPTIONAL) | parivesh.nic.in (public documents only) | `raw/parivesh/` | 5–10 EC letters for mines in our list → clearance conditions + OCR test set. If search needs CAPTCHA → MANUAL, fallback: skip |
| S12 | Tender/award data (OPTIONAL) | coalindiatenders.nic.in, eprocure.gov.in | `raw/tenders/` | Typical contract values/durations by work type. If CAPTCHA/login → skip; use published annual-report figures instead |
| S13 | PPE detection dataset (OPTIONAL, large) | openly licensed PPE/hard-hat datasets downloadable without login (check GitHub releases first; Kaggle/Roboflow need keys → MANUAL) | `raw/ppe/` | Evaluation set for the YOLO model |

**Legal list for S10** (download official PDFs; record for each: current status — in force / repealed / subsumed / partly in force — and the notification that established that status):
- Occupational Safety, Health and Working Conditions Code, 2020 and the OSH (Central) Rules, 2026
- Code on Wages, 2019; Code on Social Security, 2020; Industrial Relations Code, 2020
- Mines Act, 1952; Mines Rules, 1955; Coal Mines Regulations, 2017; Mines Vocational Training Rules, 1966; Contract Labour (R&A) Act, 1970 and Central Rules, 1971 — **these are affected by the labour codes; determine and record exactly which provisions/regulations still apply under savings/transition clauses. Do not assume.**
- Environment (Protection) Act, 1986; Air (Prevention and Control of Pollution) Act, 1981; Water (Prevention and Control of Pollution) Act, 1974
- Coal Mines Provident Fund and Miscellaneous Provisions Act, 1948
- Relevant DGMS technical circulars on gas monitoring, PPE and vocational training (only those you can find publicly)

## Stage D2 — Download
- `scripts/download_all.py` reads `sources.yaml` and downloads every non-manual source into its `raw/<id>/` folder, writing a `raw/<id>/README.md` (what, from where, when, licence, checksum).
- For MSHA: after download, keep the zips but immediately filter to **coal** mines and the **last 10 years** into `raw/msha/filtered/*.parquet`, so later stages never load the full files.
- For OpenAQ: only if `OPENAQ_API_KEY` is set; otherwise log "skipped — manual key missing".
- Summary table at the end: source, status (ok / skipped-manual / failed), files, size. **Stop.**

## Stage D3 — Clean into reference data
Write one `scripts/clean_<topic>.py` per output. Each output gets a data card in `DATASETS.md` (source IDs, method, row count, known limitations).

| Output (`reference/`) | Content |
|---|---|
| `companies.csv` | CIL (parent) + subsidiaries, SCCL, NLC India, others found in `mines_base.csv`; parent_id, type (holding/subsidiary/psu/state_jv/private) |
| `areas.csv` | company_id, area name, source |
| `mines.csv` | all 74 mines: company_id, area_id (nullable), state, district, lat, lon, `location_quality` (exact_gem / wikidata / district_centroid), `gem_id`, type (opencast/underground/mixed), capacity_mtpa, status, match_confidence. Matching: fuzzy name + state against GEM; review every match below a confidence threshold and list them |
| `district_boundaries.geojson` | simplified polygons for districts that contain our mines (keep small) |
| `production_company_monthly.csv` | company, year, month, coal_mt (and OB where published) parsed from Ministry of Coal PDFs; keep page reference per row |
| `accident_causes_dgms.csv` | year, cause category, accidents, fatalities, serious injuries (coal only) |
| `msha_rates.csv` | per coal mine-year: inspections, inspection hours, violations by category, S&S share, accidents — the calibration base |
| `crosswalk_msha_india.csv` | MSHA violation category (CFR part/subpart group) → our Indian category (roof/strata, ventilation/gas, electrical, transport/haulage, explosives, PPE, fire, environment, welfare, documentation). Hand-built by you; each row with a one-line rationale |
| `env_stations.csv`, `env_daily.csv` | station id, coords, nearest mines (distance); daily pollutant values (only if S09 succeeded) |
| `legal_instruments.csv` | instrument, year, status, effective_date, status_source (document + clause/notification) |
| `obligations.csv` | obligation_code, title, instrument, clause/section, applies_to (mine/contractor/worker), frequency, due_rule, responsible_role, evidence_type, citation_page, verified (yes / TODO-VERIFY). Target 25–40 obligations across safety, environment, labour, production reporting |
| `glossary.csv` | mining/governance terms in en, hi, bn, or, te, mr (drafted by you, `reviewed=false`) — used later for UI translation consistency |

**Stop** and list: unmatched/low-confidence mines, every TODO-VERIFY, and any figure you could not parse.

## Stage D4 — Generate synthetic operational data
First write `data/schema/*.yaml` for each table, derived from `CLAUDE_CODE_TASK.md` and `PLAN.md` (use column names exactly as those documents define; where the backend migrations already exist, the migrations win). Generators must output CSVs that match these schemas exactly.

Scale presets in `config.yaml` (`small` for tests, `demo` default, `full`). Suggested `demo` volumes over 90 days:

| Generator | Output tables | Calibration |
|---|---|---|
| `gen_users.py` | users per role (keep existing demo accounts and passwords; add one corporate user per company) | — |
| `gen_production.py` | `daily_production` (mine × day × shift A/B/C) | Split `production_company_monthly` across mines by capacity, then days and shifts; monsoon dip Jul–Sep, March push; realistic breakdown hours and manpower. **Daily totals must re-aggregate to published monthly company figures within ±3 %** |
| `gen_sensors.py` | `sensor_reading` (CH₄, CO, dust, temperature, humidity) at 15-minute intervals for underground mines, reduced set for opencast | Daily cycles, slow drift, rare spikes; thresholds only from `obligations.csv` / legal texts, else TODO-VERIFY |
| `gen_environment.py` | `env_reading` per mine | Real `env_daily` from nearest station + small mine-level noise; fully synthetic with documented ranges if S09 skipped |
| `gen_inspections.py` | `inspection`, `observation`, `violation`, `corrective_action` | Rates from `msha_rates` scaled by mine size; category mix weighted by `accident_causes_dgms` via the crosswalk; corrective actions with realistic closure times, some overdue |
| `gen_contractors.py` | `contractor`, `contract`, `contract_worker`, `contractor_compliance_doc` | **Fictitious company and person names** (Faker `en_IN` + regional names); work types and values in plausible ranges; some licences/VT certificates/medicals expiring or expired; some monthly documents missing |
| `gen_grievances.py` | `grievance`, `grievance_action` | Category mix, SLA outcomes, languages matched to mine regions; texts written by you in all six languages from templates (varied, realistic, no real people); include sensitive cases (harassment, against mine head) and safety cases linked to observations |
| `gen_requests.py` | `production_detail_request` | A few pending, submitted, one overdue |
| `gen_alerts.py` | `alert` (historical only; live alerts come from backend jobs) | `{code, params}` format, never display text |

No real personal data anywhere. Aadhaar-like or phone-like values must be obviously fake (e.g. masked `XXXX-XXXX-1234`).

## Stage D5 — Scenario injection and validation
- `generators/inject_scenarios.py` adds labelled patterns and writes `out/scenario_label.csv` (`scenario_code, mine_id, entity, entity_id, date_from, date_to, notes`). Minimum set:
  1. Repeat roof/strata violations at one mine → followed by an incident
  2. Production spike the day before a scheduled inspection
  3. Flatlined gas sensor for 3 days (possible tampering)
  4. Contractor with repeated missing wage/EPF proof and most violations per worker
  5. Night-shift (C) compliance consistently worse at one mine
  6. Grievance SLA breach cluster at one mine
  7. Corrective actions repeatedly closed late at one area
  Keep the 5 demo mines' risk bands as described in the repo's demo script (one green, one yellow, one red at minimum).
- `validate.py` checks and prints a pass/fail report:
  - every CSV matches its schema YAML (columns, types, nullability)
  - all foreign keys resolve; no orphan rows; no future dates
  - production re-aggregation within ±3 % of published figures
  - every scenario in `scenario_label` is present in the data
  - every legal threshold/deadline used by generators cites an obligation row
  - determinism: generate twice with the same seed → identical hashes
- **Stop** with the validation report.

## Stage D6 — Documentation and hand-off
- Finish `DATASETS.md` (one card per dataset: real / calibrated-synthetic / synthetic, sources, method, limitations) and `SOURCES.md` (with licences and access dates).
- Add a short "Data" section to the root README: how to run `data\run_data.bat all`, where outputs go, and that the backend `yii seed` should load `data/out/*.csv` via PostgreSQL `COPY` in FK order (the backend track implements the loader — do not implement it here).
- Write `data/HANDOFF.md` for the backend track: table list in FK load order, row counts per preset, any schema assumptions that differ from `PLAN.md`.
- Suggest one line of UI text for the dashboard footer: "Demo data: synthetic, calibrated to public statistics — see DATASETS.md".
- Final summary: what is real, what is synthetic, open TODO-VERIFY items, manual steps still pending. **Stop.**

---

## Definition of done
- `data\run_data.bat all` on a fresh clone (after manual steps) produces all `reference/` and `out/` files and a green validation report.
- All 74 mines have coordinates with a stated quality level.
- Every legal value is either cited or listed as TODO-VERIFY.
- Two identical runs give identical outputs.
- No real personal data; every source has licence and access date recorded.
