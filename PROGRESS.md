# Progress (governance backend, branch `feat/governance-backend`)

Brief: `CLAUDE_CODE_TASK.md`. Plan and decisions: `PLAN.md`. Data: `data/HANDOFF.md`.

## Phase 3: Contractor management, plus two Phase 2 fixes (done, 2026-09-27)

### Fix 1: audit history backfill

- `audit_log.source` (`app`, `seed`, `seed_history`; migration `m260929_000001`) is part of the
  row hash (`audit_row_hash`, 12 arguments).
- After loading, `yii seed` writes the seeded history into the chain in one `INSERT ... SELECT
  ... ORDER BY at`, through the same trigger that chains every entry, so nothing bypasses it.
  - The history covers: violations recorded and resolved; corrective actions recorded and
    resolved; incidents reported; inspections visited and closed; directives and alerts raised;
    contracts started; contractor documents uploaded.
  - Each entry keeps its original timestamp and actor (inspector, corrective-action author,
    uploader).
  - The demo seed writes 12,632 entries, and `yii audit/verify` passes.
- `GET /v1/audit` returns `source` and accepts `?source=` as a filter.
- Tests:
  - `SeedTest::testSeededHistoryIsInTheChainWithOriginalTimestampsAndActors`;
  - `AuditTrailCest::aMinesTrailShowsItsHistoryRightAfterSeeding`.

### Fix 2: real PPE detection

- The model is YOLO11n, fine-tuned on S13 (CC BY 4.0) on CPU.
  - Settings: 10 epochs, 512 px, the first 10 layers frozen. Training took 19.4 min.
  - Results on the held-out test split (213 images): mAP50 0.850, mAP50-95 0.560.
  - The per-class table is in `docs/AI_EVALUATION.md`.
- No openly licensed pretrained PPE model that can be downloaded without a login was usable.
  The candidates and the reasons are in `docs/AI_EVALUATION.md`.
- Ultralytics code and weights are AGPL-3.0; that document sets out what this means for the
  project.
- The weights are gitignored:
  - `scripts/build_ppe_model.py` rebuilds them into `backend/ml/weights/ppe.pt`;
  - `run_all.bat` warns if they are missing;
  - the ai-service falls back to the fixture without them, or when `PPE_DETECTOR=fixture` is set.
- `VisionCest` covers both backends.

### Phase 3: contractors

- **Migrations** (reversible):
  - `contractor`, `contract`, `contract_worker` and `contractor_compliance_doc`, with CHECKs,
    unique keys and indexes;
  - FKs from `violation`, `corrective_action` and `observation` to `contractor`;
  - the alert code `CONTRACT_WORKER_CAP_EXCEEDED`.
- **Seeding:** `yii seed` loads the four tables and their files from `data/out`.
- **Scoping:** a contractor has no mine of its own. It is in scope when one of its contracts is:
  `ScopedActiveQuery` `via:contract.contractor_id`. Contracts, workers and documents scope by
  `contract.mine_id`. Anything out of scope is 404.
- **Compliance scoring (`ContractorService`):** 100 minus penalties, computed from the data.
  - Penalties come from: violations per active worker; missing monthly wage register, EPF challan
    and ESI challan; the labour licence (expired or expiring); workers with expired training or
    overdue medicals; contracts over their worker cap.
  - Bands: compliant, watch, flagged.
  - Ordering: worst first.
  - Every reason is a `{code, params}` pair and cites `rules.yaml`: LAB-02 (OSH Code s.48(3)),
    SAF-04, HLT-01 (OSH (Central) Rules, 2026). The repealed Acts are never cited.
- **Alerts:** `ContractorAlertService` raises the brief's alert codes from the data, and running
  it twice changes nothing. Run it with `yii contractor/check`; `yii contractor/report` prints
  the ranking.
- **Mine head:**
  - full CRUD: register a contractor with its first contract; contracts; workers; monthly
    document upload and verification; status changes with a reason;
  - a contractor selector in the violation detail and the corrective-action form.
- **Government, inspector and corporate:** read-only.
  - The Overview page has a contractor compliance card, with flagged contractors worst first.
  - The Contractors tab has the per-mine summary and all contractors.
- **Scenario S4 (Prakash Infra Projects):** flagged and first in the fleet.
  - It has the most violations per worker (1.03) and months of missing wage and EPF proof.
  - At Block-B (MP-SIN-42) its score is 40, with 3 violations per worker.
- **i18n:** all new UI text is in `en.json` (`contractor.*`, the new alert, audit sources and
  actions).
- **Tests:** 119 tests, 964 assertions, all passing.
  - `ContractorCest` covers S4 visibility, scoping, 404s, the full management flow, generated
    alerts and read-only roles.
  - `ContractorServiceTest` covers due periods, score, licence and status.
- **Browser check:** `node scripts\browser_check.mjs phase3` saves 16 screenshots, for the S4 mine
  head, government and corporate NCL, to `docs/screenshots/phase3/`. No browser errors were
  logged.
  - The check now waits for the data rather than sleeping a fixed time.
  - If a check fails, it saves `failure.png` and `failure.txt`.

### Known issues (Phase 3)

- **The PPE model is weak out of distribution:**
  - it reports a false `no_helmet` on `with_ppe.jpg`;
  - it misses people on the metro-shaft photo.

  For the scripted demo numbers, start the ai-service with `PPE_DETECTOR=fixture`
  (demo-script.md). A longer run (25 epochs, 640 px, no freezing; about 1.5-2 h on CPU) is
  proposed and needs owner approval.
- **AGPL-3.0:** any non-open deployment of the YOLO model needs an owner decision (an Ultralytics
  Enterprise licence or a different detector).
- **The PHP built-in server (`php -S`) handles one request at a time.** A browser tab left
  polling the dashboard slows every other client, including the headless check. Close other tabs
  before `browser_check.mjs`.
- **The vision test skips itself while the ai-service is still loading the model.** Run the
  tests once the ai-service answers `/health`.
- The document due day (10th of the following month) and the 30-day licence warning are
  **product settings**, not statutory periods.
- `epf_challan` is kept as a document type without a legal claim (EPF Act status is still
  TODO-VERIFY).

### How to verify

```bat
run_all.bat
api\yii.bat seed demo
api\yii.bat contractor/check
cd api && run_tests.bat
node scripts\browser_check.mjs phase3
```

Re-seed after the browser check.

## Phase 2: Port existing modules to Yii2 (done, 2026-09-27)

### Done

- **Migrations** (reversible, tested down and up; 10 new):
  - `audit_log.mine_id`: part of the hash, so the trail can be scoped. There is no FK, because
    history outlives the records it describes.
  - `inspection`, `violation` + `observation`, `alert`, `corrective_action`, `incident`,
    `env_reading`, with columns exactly as in `data/schema/`. The `observation ↔ violation` FKs are
    `DEFERRABLE`.
  - `sensor_reading`, partitioned by month (2026-01 … 2027-12 plus a default partition;
    `yii partition/ensure` adds months).
  - `status_history` (every workflow transition) and `record_edit_log` (edits of locked records).
  - `compliance_score` (trend line) and `api_key` (SHA-256 only).
  - CHECK constraints for every enum, the 11 categories, and the consistency rules (resolved ⇔
    `resolved_at`, promoted ⇔ `violation_id`, closed ⇒ locked). An index on every FK plus
    `(mine_id, date)` and `(mine_id, status)`.
- **New dependency:** `symfony/yaml`, to read `data/schema/rules.yaml` and
  `violation_categories.yaml` rather than copying legal limits into PHP. It was already installed
  through Codeception and is now a direct requirement (brief: "justify any other dependency").
- **`yii seed demo`** loads all 13 Phase 1-2 tables (1.7 M sensor readings) in about 25 s. It
  also records the per-mine open-violation baseline for the simulator pre-flight.
- **Services:**
  - `ComplianceScoreService`: the prototype's formula, window and bands, with identical numbers.
  - `InspectionPriorityService`: urgency and trend; reasons as `{code, params}`.
  - `SensorService`: ingest, fleet standing, breach buckets and trend. **Legal limits are read
    from `rules.yaml`:** SAF-11, HLT-04 (as an 8-hour mean) and HLT-05. CO and humidity have no
    limit, so `breached` is null for them.
  - `AlertService`: `{code, params}`, directives, acknowledge/resolve/reopen.
  - `CorrectiveActionService`: resolving an action closes its violation.
  - `InspectionService`: schedule → visit → observe → promote → close (locks); a locked edit
    needs a reason.
  - `VisionService` (via `AiClient`) and `BaselineService`.
- **Incident module:**
  - List (scoped, `?late=1`) and detail with the linked violation.
  - Report an incident: the obligation follows the severity (RPT-03 / RPT-04 / RPT-05), and a
    dangerous occurrence raises an alert.
  - Link or unlink a violation of the same mine.
  - The **48-hour reporting check**: `reporting_check: {code: REPORTED_WITHIN_48H |
    REPORTED_AFTER_48H, params: {hours, limit_hours, obligation_code}}`.
- **Endpoints:** all of `docs/api-contract.md`.
  - Out of scope is always **404**, and a missing permission is 403.
  - `GET /v1/users/me` carries `permissions`, so the UI can hide what the API would refuse.
  - Contract changes are listed in `docs/API_CHANGES.md`.
- **ai-service** (`ai-service/`, PLAN Q13): `POST /vision/ppe` imports the existing vision code
  and returns detections, candidates, evidence and the annotated frame. If it is down, the API
  answers 503 `AI_SERVICE_UNAVAILABLE` and records a low alert.
- **Simulator** (`scripts/run_simulator.py`, stdlib only):
  - Replays the last 14 days of `data/out/demo` through `POST /v1/sensor-readings/ingest` using
    an API key, one data-hour per tick.
  - Pre-flight via `GET /v1/sensor-readings/baseline`, with `--check-only` and `--require-clean`.
- **`run_all.bat`:**
  - Starts PostgreSQL through `scripts\db.bat` if it is not running, waits for `pg_isready`, and
    fails clearly with the log tail if it never comes up.
  - Migrates and seeds on the first run, then opens API, AI, frontend (and the simulator with
    `--sim`).
  - `--stop` closes the windows and does a clean `fast` shutdown. PostgreSQL runs in its own
    hidden console, so closing windows never touches it.
- **Frontend on the new API** (`VITE_API_URL`):
  - Error envelope and 404 handling.
  - Lower-case roles; the corporate and inspector roles use the overview screens.
  - Alerts, priority reasons, sensor status, incident checks and audit entries all translated
    from codes (`src/i18n/labels.js`, `locales/en.json`).
  - New records row on both dashboards: violations (all categories), corrective actions (record,
    close with proof), incidents (48-hour check), audit trail.
  - Login quick-fill and every mine name come from the real roster.
  - GEM attribution sits in the footer, and the "demo value" tag's tooltip reads "Demo score -
    not a real safety assessment".
- **Docs:**
  - `docs/demo-script.md` rewritten: new mine names, the 404 rule, the new commands, and the
    Odisha region for the live-sensor step.
  - `docs/access-control.md`, `docs/api-contract.md`, `docs/API_CHANGES.md`, `README.md` and the
    `docs/architecture.md` header updated.
  - `docs/SETUP_WINDOWS.md` sections 7-9 added: `run_all.bat` and the database, where secrets live
    and how to regenerate them (with the git check), and the FastAPI fallback.
- **Tests: 106 Codeception tests, 666 assertions, all passing (none skipped).** They include the
  **demo-score test** (`tests/api/DemoScoreCest.php`): after `yii seed demo`, 100 / 80 / 70 / 60 / 45
  for the five demo mines, 6 / 21 / 47 bands, average 83.2. `run_tests.bat` now seeds `demo` into
  the test database.
- **Browser check:** `scripts/browser_check.mjs` drives Edge headless through both dashboards and
  corporate. It saves 21 screenshots to `docs/screenshots/phase2/` (with `results.json`) and
  asserts the key numbers.

### Pending (next phases)

- Phase 3 contractors, Phase 4 production, Phase 5 grievances (their tables are skipped by the
  seeder until then; FKs from `violation` / `observation` / `corrective_action` to `contractor`
  and `grievance` arrive with those tables).
- Phase 6: the other five languages. The keys are all in `en.json`; the violation types and DGMS
  cause codes still render humanised English.
- Phase 7:
  - `CORRECTIVE_ACTION_OVERDUE` and escalation jobs;
  - anomaly scores (the fleet and trend no longer carry `anomaly_score`);
  - the rest of ai-service, with its own venv (it uses `backend\.venv` for now).
- Phase 8: parity sign-off, then remove `backend/`.

### Known issues

- **Docker path not verified:** Docker Desktop is not installed on the development machine.
- ~~**Audit trail empty after a seed**~~: fixed in Phase 3 (seeded history is backfilled into the chain).
- **Dust rarely breaches in the live replay:** its limit is an 8-hour average, and the replay
  compresses data-hours into seconds. Methane and wet-bulb breaches (instantaneous) do fire,
  about one every 20 s somewhere in the fleet (most at Nandira, OD-ANG-57).
- ~~**PPE vision in this environment uses the fixture backend**~~: fine-tuned weights since
  Phase 3 (the fixture remains the fallback). The browser check cannot drive a file picker, so vision is covered by `VisionCest`
  (against the running ai-service) rather than by a screenshot.
- **Violation types and DGMS cause codes** are shown humanised from their tokens; translation
  keys for them come in Phase 6.

### How to verify

```bat
run_all.bat
cd api && run_tests.bat
node scripts\browser_check.mjs
```

`run_all.bat` starts the database if needed and opens the dashboard; sign in with a quick-fill
account. The demo-score numbers are in `api/tests/api/DemoScoreCest.php`. The browser check
changes data like a user would, so re-seed afterwards with `api\yii.bat seed demo`.

## Phase 1: Foundation (done, 2026-09-27)

Toolchain without admin rights (PostgreSQL 16.15 + PostGIS 3.6.2 portable, Composer, XAMPP PHP
extensions), `api/` scaffold (Yii2 JSON API, error envelope, listing conventions, JWT), reversible
migrations for subsidiary / area / mine / user / audit_log / file / RBAC / queue,
`ScopedActiveQuery`, `AuditBehavior` + SQL hash chain + `yii audit/verify`, `StatusTransition`,
`FileStorage`, RBAC roles, `yii seed` (COPY loader), i18next scaffold, demo-data footer and tags.
Commit `b026164`.

## TODO-VERIFY register

No new regulatory facts were introduced in Phases 1-3 (Phase 3 cites LAB-02, SAF-04 and HLT-01
from `rules.yaml`; its due day and warning window are product settings). Every limit, period and obligation code the
API uses is read from `data/schema/rules.yaml` or the incident data, each tied to a verified row
of `data/reference/obligations.csv`. The 48-hour reporting check is the rule the data's
`reported_within_48h` column encodes.

Items still open from the data track (`data/HANDOFF.md`, "Open TODO-VERIFY items"):

- EPF Act status;
- the RPT-08 production return;
- the OpenAQ licence;
- glossary and grievance translations;
- **the CO sensor limit** (CO readings stay unjudged until a verified obligation gives one).

`PLAN.md` §8 lists the items for later phases (grievance SLAs, document due day, and others).
