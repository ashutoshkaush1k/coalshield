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
