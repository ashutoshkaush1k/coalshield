# Data track handoff

**Ownership (2026-09-26).** One owner: Yug Pancholi, the only person on this project. All
implementation is done by Claude Code, which owns `backend/`, `api/`, `ai-service/`, `frontend/`
and `data/`. Earlier "pending team approval" or "owned by another track" notes are resolved. This
file records what the data track hands to the backend and frontend work, the decisions taken, and
what is still open.

---

## Decision: the real mine roster is adopted (2026-09-26)

`data/reference/mines_real.csv` is **the project's mine list**: 74 real operating coal mines from
the Global Energy Monitor (GEM) Global Coal Mine Tracker (August 2026, CC BY 4.0), all with exact
GEM coordinates. `data/reference/mines.csv` (the prototype's fictional seed, with district-level
locations) stays as the fallback, selected with `mine_roster: seed` in `data/config.yaml`.

**What the backend does in Phase 1**

1. Load mines from `data/reference/mines_real.csv` instead of `backend/data/seed/mines.json`.
   `id`, `code`, `name`, `state`, `district` and `region` map one-to-one. The operator comes from
   `company_id` via `data/reference/companies.csv`, and `location` is "district, state". Also
   available: `lat`, `lon`, `type`, `capacity_mtpa`, `area_id`, `gem_id`.
2. Use `data/reference/mine_code_mapping.csv` (`old_code → new_code`) for anything keyed by the old
   code (users, violations, sensor readings).
3. **Logins** follow `head.<code in lower case>@coalmine.in` (`head_email`).
   - The five demo mines keep their codes and logins (JH-DHN-01, MP-SGR-02, CG-KRB-03, WB-RNG-04,
     OD-TLC-05); 10 codes in total are unchanged.
   - New logins get the same demo password the seed uses today. The data track does not copy
     passwords.
4. **Scores:** each real mine takes the demo score, band and open-violation count of the seed slot
   it replaces. `100 − 5 × seed_violations` reproduces `demo_score`: average 83.2, **6 High /
   21 Medium / 47 Low**, with the named mines at 100 / 80 / 70 / 60 / 45.
5. **Demo script:** update the Mine / District, State table under "Before the room" in
   `docs/demo-script.md`:
   - Jharia → Moonidih (BCCL, Dhanbad);
   - Singrauli → Jayant (NCL);
   - Korba → Gevra (SECL);
   - Raniganj → Sonepur Bazari (ECL, Paschim Bardhaman);
   - Talcher → Bhubaneswari (MCL, Angul).

**Must-haves**

- **Scores on real mines are demo values, not assessments.** Every score, band and violation count
  is synthetic (`score_note`). Wherever a score appears next to a real mine name, the UI must say
  so, e.g. "Demo score - not a real safety assessment".
- **Attribution.** Credit "Global Energy Monitor, Global Coal Mine Tracker, August 2026" wherever
  mine names, locations or capacities are shown (an About page or map footer is enough).
- The map can use real points; all 74 mines have exact GEM coordinates.

---

## Decisions on the D3 review (2026-09-26)

All accepted by the owner:

- **Five dropped GEM candidates.** Kakri, Barsingsar, Kondapuram, Vakilpalli and Neyveli I/I A/II
  (the Neyveli mines have no seed state prefix).
- **Name-based area rule.** A mine whose name contains its company's area name gets that area.
- **2011 district basis for 12 mines** whose districts were split after 2011.
- **`reference/env_daily.csv` stays out of git** until the OpenAQ station licence is known.
- **An 11th violation category, `machinery`.** The final list of 11 is in
  `data/schema/violation_categories.yaml`, and the backend uses the same list.

---

## Schema conflicts and choices (stage D4, 2026-09-26)

The output schemas (`data/schema/*.yaml`) are derived from `CLAUDE_CODE_TASK.md` (including its
Legal update), `PLAN.md` and the current FastAPI models. **Rule (owner):** where they conflict,
`CLAUDE_CODE_TASK.md` wins. The backend uses the same schemas.

| # | Conflict | Choice |
|---|---|---|
| C1 | **Role values.** Repo and frontend use `GOVERNMENT` / `MINE_HEAD`; the brief uses `government`, `corporate`, `mine_head`, `inspector` (PLAN Q6 agrees). | Lower case, as the brief. `auth/roles.js` must follow. |
| C2 | **Enum case.** Repo statuses are upper case (`OPEN`, `RESOLVED`, `VISION`, `SYSTEM`); the brief writes statuses in lower case (`open/acknowledged/resolved`, `draft/submitted/locked`). | All enum values lower case. Alert **codes** stay UPPER_SNAKE (`GRIEVANCE_SLA_BREACHED`), as the brief writes them. Shift letters stay `A/B/C`. |
| C3 | **Table names.** Repo tables are plural (`violations`, `sensor_readings`, `alerts`, `users`, `mines`); the brief's are singular. | Singular: `violation`, `sensor_reading`, `alert`, `user`, `mine`, ... |
| C4 | **Alert text.** Repo alerts carry display text (`message`, `source`, `alert_type`); brief rule 7 requires `{code, params}`. | `code` + `params` JSON only; no text column. |
| C5 | **Number of mines.** Brief Phase 8 seeds 5 mines; PLAN B3 and the owner keep 74. | 74 (real roster adopted). The `small` preset is the five named mines. |
| C6 | **Company structure.** Repo stores an `operator` string; the brief has `subsidiary` → `area` → `mine`. PLAN Q10: subsidiary = operating company with a parent column; PLAN Q9: placeholder areas. | `subsidiary` (10 companies, `parent_id` → CIL) and the 43 published areas from `reference/areas.csv`, replacing Q9's placeholders. `mine.area_id` is nullable (most mines have no published area). |
| C7 | **Mine location.** PLAN Q8: nullable, no coordinates; the brief has `geometry(Point,4326)`. | Exact GEM points, as WKT `POINT(lon lat)`; still nullable. |
| C8 | **Mine type.** The brief lists opencast/underground; GEM has 6 "Underground & Surface" mines. | `mixed` added as a third value. It counts as underground for gas sensors. |
| C9 | **Inspections.** The brief assumes inspection and observation records (rule 9, Phase 5) but lists no columns. | PLAN Q11's minimal design: `inspection` (type, `scheduled/visited/closed`, `is_locked`, `findings_count`) and `observation` (category, severity, `open/promoted/dismissed`, `violation_id`, `grievance_id`). |
| C10 | **Violation source.** The repo has `VISION` only. | `vision`, `inspection` or `grievance`. `violation` also gains `category` (the 11), `inspection_id`, `observation_id` and `contractor_id` (brief Phase 3). |
| C11 | **Passwords.** The brief's `user` has `password_hash`; the repo seed (`users.json`) carries a plain `password` that the loader hashes. | The seed CSV has `password` (`demo123`, demo only) and the loader hashes it, as today. The hash format is the API's choice. |
| C12 | **`corrective_action.created_by`.** Free text in the repo. | A user id. `due_at` added (needed for "overdue", brief D4) and `contractor_id` (brief Phase 3). |
| C13 | **Sensors.** Repo types are `gas` (ppm), `dust` and `temperature`, with demo thresholds 50 / 10 / 45. The dataset brief asks for CH4, CO, dust, temperature and humidity, with legal thresholds. | `ch4`, `ch4_return_air`, `co`, `dust`, `temperature` (wet bulb) and `humidity`. `breached` comes only from obligation-cited limits (`schema/rules.yaml`) and is empty where none exists (co: TODO-VERIFY; humidity). The repo's demo thresholds are not used for breaches. |
| C14 | **Grievance identity.** The brief writes "name/contact nullable". | Two nullable columns, `name` and `contact` (masked). |
| C15 | **Timestamps.** The repo writes `+00:00`; the brief requires ISO-8601 UTC. | `YYYY-MM-DDTHH:MM:SSZ`. |
| C16 | **Seed volumes.** Brief Phase 8 volumes (~15 contractors, ~60 contracts, ~600 workers, ~120 grievances) are for 5 mines. | Per-preset volumes in `config.yaml`. `demo`: 40 contractors, 148 contracts, about 2,400 workers, about 360 grievances. |
| C17 | **Corporate login.** The brief names `corporate.secl@coalmine.in`. | The same pattern for all 10 companies. |
| C18 | **Inspector role.** The brief: "inspector defined, unused"; inspections need an inspector (PLAN Q11). | 8 inspector accounts with fictitious names on the reserved `.example` domain. |
| C19 | **`env_reading`.** Defined only by the dataset brief. | Columns as in `schema/env_reading.yaml`. |
| C20 | **`contractor_compliance_doc.file_id`.** Nullability not stated. | Required. A missing document is a missing row, not a row without a file. |
| C21 | **Incidents (D5).** No schema has an incident or accident table. | New alert code `DANGEROUS_OCCURRENCE_REPORTED`, params citing RPT-05 (OSH (Central) Rules r.7(3)). |
| C22 | **`scenario_label` (D5).** The dataset brief lists 7 columns; the owner asked for decoys labelled as negatives. | Extra column `polarity` (`positive` / `negative`). |
| C23 | **Incident table (owner, 2026-09-26).** Incidents were previously only an alert. | New table `incident` (`schema/incident.yaml`): `id, mine_id, occurred_at, reported_at, type` (9 DGMS cause groups), `severity` (fatal / serious / minor / dangerous_occurrence), `persons_affected, description_code, related_violation_id` (nullable), `reported_within_48h, obligation_code` (RPT-03 / RPT-04 / RPT-05). S1's dangerous occurrence is an incident row; its alert (C21) now points at it (`entity_type = incident`). |

---

## Scenarios for the AI features (stage D5)

`data/out/<preset>/scenario_label.csv` (one row per affected entity) and
`scenario_expectations.json` (the detector, entity ids, dates and a measurable signal per
scenario) are the ground truth for scoring the anomaly and risk features (precision and recall).

**Demo placement** (real roster, demo preset):
- On the HIGH demo mine OD-TLC-05: repeated strata violations, then an incident (S1), and late
  corrective actions (S7).
- On the MEDIUM demo mine CG-KRB-03: a production spike the day before an inspection (S2).
- On non-demo mines: everything else, including the three decoys (N1–N3), which detectors must
  *not* flag.

Demo scores are unchanged by injection; this is checked on every run.

---

## Backend work the data now requires

Implemented by Claude Code in the backend phases (`CLAUDE_CODE_TASK.md`, `PLAN.md`); the data is
ready for it.

1. **Adopt the real roster.** Seed `mine` from `data/out/<preset>/mine.csv` (built from
   `reference/mines_real.csv`); use `mine_code_mapping.csv` for anything keyed by old codes.
2. **Incident table** (C23): migration from `schema/incident.yaml`, a model, and scoped read
   endpoints. The compliance feature flags `reported_within_48h = false` (RPT-04). Incidents do
   not enter today's score.
3. **Lower-case roles** (C1): `government`, `corporate`, `mine_head`, `inspector`. The frontend's
   `src/auth/roles.js` still compares `GOVERNMENT` / `MINE_HEAD` and must switch, as must every
   role check in `api/`.
4. **Alert codes** (C4, C21): alerts carry `{code, params}` only. The codes in use are
   `SENSOR_THRESHOLD_BREACHED`, `VIOLATION_RECORDED`, `CORRECTIVE_ACTION_OVERDUE`,
   `CONTRACTOR_LICENCE_EXPIRING`, `WORKER_VT_EXPIRED`, `WORKER_MEDICAL_EXPIRED`,
   `CONTRACTOR_DOC_MISSING`, `GRIEVANCE_SLA_BREACHED`, `DETAIL_REQUEST_OVERDUE` and
   `DANGEROUS_OCCURRENCE_REPORTED`. Each needs a translation key in all six locales.
5. **The 11 violation categories** (`schema/violation_categories.yaml`): `roof_strata`,
   `ventilation_gas`, `electrical`, `transport_haulage`, `explosives`, `ppe`, `fire`,
   `environment`, `welfare`, `documentation`, `machinery`. Use them as an enum or lookup table, with
   UI labels via translation keys.
6. **Sensor thresholds** from `schema/rules.yaml` (SAF-11, HLT-04, HLT-05), not the prototype's
   50 / 10 / 45:
   - dust is judged as an 8-hour rolling mean;
   - CO has no limit (TODO-VERIFY) and humidity none, so `breached` is empty for both;
   - the simulator's thresholds must follow.
7. **Demo-score label.** Show "Demo score - not a real safety assessment" next to real mine names,
   and credit GEM (CC BY 4.0) where mine data is shown.
8. **Load `data/out/<preset>/*.csv` with `yii seed`** via PostgreSQL `COPY`, in the order below.

### `yii seed` load order (foreign keys)

Computed from `data/schema/*.yaml`. Load each file with `COPY ... FROM ... WITH (FORMAT csv,
HEADER true, NULL '')`, then reset each table's id sequence to `max(id)`.

| # | Table | Depends on | Note |
|---|---|---|---|
| 1 | `subsidiary` | itself (`parent_id`) | CIL (id 1) is the first row, so the self-reference resolves in file order |
| 2 | `area` | subsidiary | |
| 3 | `mine` | subsidiary, area | `location` is WKT: load via `ST_GeomFromText(location, 4326)` |
| 4 | `user` | subsidiary, area, mine | `password` is the plain demo password; hash on load (C11) |
| 5 | `file` | user | |
| 6 | `contractor` | - | |
| 7 | `contract` | contractor, mine | |
| 8 | `contract_worker` | contract | |
| 9 | `contractor_compliance_doc` | contract, file, user | |
| 10 | `daily_production` | mine, user | |
| 11 | `production_edit_log` | daily_production, user | |
| 12 | `production_detail_request` | mine, user, file | |
| 13 | `grievance` | mine, user, file | `location` WKT, as for mine. `tracking_code` is a demo code: store an HMAC of it, never the code (Phase 5B) |
| 14 | `grievance_action` | grievance, user | |
| 15 | `inspection` | mine, user | |
| 16 | `observation` | inspection, grievance, contractor, mine, **violation** | **Cycle** with `violation.observation_id`: create the FK `DEFERRABLE` and load 16–17 in one transaction, or load `observation.violation_id` as NULL and `UPDATE` it after 17 |
| 17 | `violation` | mine, inspection, observation, contractor | |
| 18 | `alert` | mine, user | `entity_type` / `entity_id` are loose references (no FK), as today |
| 19 | `corrective_action` | violation, alert, contractor, mine, user | |
| 20 | `incident` | mine, violation | |
| 21 | `obligation` | - | the cited catalogue (Phase 5B) |
| 22 | `obligation_applicability` | mine, obligation | |
| 23 | `obligation_task` | mine, obligation | |
| 24 | `obligation_submission` | obligation_task, file, user | the evidence file rows are in `file.csv` (entity `obligation_submission`) |
| 25 | `sensor_reading` | mine | Largest table; partition by month on `recorded_at` (brief rule 10) before `COPY` |
| 26 | `env_reading` | mine | |
| - | `scenario_label` | mine | **Do not load into the app database.** Ground truth for scoring detectors; keep it in a separate evaluation schema or read it from the CSV |

### Row counts per preset

| Table | small | demo | full |
|---|---|---|---|
| `alert` | 100 | 2,759 | 11,036 |
| `area` | 43 | 43 | 43 |
| `contract` | 15 | 148 | 222 |
| `contract_worker` | 214 | 2,421 | 4,445 |
| `contractor` | 8 | 40 | 60 |
| `contractor_compliance_doc` | 28 | 1,243 | 6,341 |
| `corrective_action` | 51 | 2,121 | 8,125 |
| `daily_production` | 210 | 19,980 | 81,030 |
| `env_reading` | 280 | 26,640 | 108,040 |
| `file` | 30 | 1,252 | 6,369 |
| `grievance` | 48 | 374 | 1,407 |
| `grievance_action` | 194 | 1,748 | 6,705 |
| `incident` | 3 | 83 | 393 |
| `inspection` | 7 | 217 | 844 |
| `mine` | 5 | 74 | 74 |
| `observation` | 22 | 2,173 | 8,785 |
| `production_detail_request` | 5 | 12 | 40 |
| `production_edit_log` | 2 | 92 | 397 |
| `scenario_label` | 388 | 579 | 926 |
| `sensor_reading` | 12,096 | 1,704,240 | 6,911,640 |
| `subsidiary` | 10 | 10 | 10 |
| `user` | 18 | 93 | 93 |
| `violation` | 51 | 2,121 | 8,125 |
| **Total size** | 0.9 MB | 111.9 MB | 456.6 MB |
| **Generation time** | 1.4 s | 35.8 s | 145.2 s |

Presets: `small` is the 5 named mines over 14 days (for tests); `demo` (the default) is all 74 mines
over 90 days; `full` is all 74 mines over 365 days. Build one with:

```bat
data\run_data.bat generate demo
```

### Schema assumptions that differ from PLAN.md

`PLAN.md` was written before the data track, and these points differ from it (details in C1–C23
above):
- **Q6 (roles):** agreed, lower case.
- **Q8 (location):** real points instead of NULL (C7).
- **Q9 (areas):** real published areas instead of placeholder areas (C6).
- **Q10 (company structure):** `subsidiary.parent_id` rather than a parent-company string (C6).
- **Q11 (inspections):** kept as proposed, with the concrete columns in C9.
- **Q7 (alerts):** PLAN's transitional "code + legacy text" for alerts is not in the seed data,
  which carries `{code, params}` only (C4).
- **Not in PLAN at all:** the `incident` table (C23), `mine.type = mixed` (C8), `env_reading`
  (C19) and the new sensor types (C13).

### Suggested UI text

Dashboard footer: **"Demo data: synthetic, calibrated to public statistics — see DATASETS.md"**.

---

## Open TODO-VERIFY items (left open on purpose)

| Item | Where | What would close it |
|---|---|---|
| EPF Act 1952 status | `reference/legal_instruments.csv` | Serial (vi) of S.O. 2060(E) of 03.05.2023 (manual step 6) |
| Monthly coal production return (RPT-08) | `reference/obligations.csv` | A downloaded source stating the reporting duty |
| OpenAQ station licence | `reference/env_stations.csv` | Licence for the CPCB stations; then `env_daily.csv` can be committed |
| Glossary translations | `reference/glossary.csv` | Native-speaker review of hi, bn, or, te, mr (`reviewed = false`) |
| CO sensor limit | `schema/rules.yaml` (`sensors.co`) | A verified obligation stating a carbon monoxide limit; until then CO readings have no `breached` value |
| Grievance texts | `generators/grievance_templates.py` | Native-speaker review, as for the glossary |
