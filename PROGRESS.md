# Progress (governance backend, branch `feat/governance-backend`)

Brief: `CLAUDE_CODE_TASK.md`. Plan and decisions: `PLAN.md`. Data: `data/HANDOFF.md`.

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
- **Audit trail empty after a seed:** seeded history is loaded by COPY, not through the audit
  log, so a mine's trail starts with the first change made in the app (the seed itself is the
  chain's genesis entry).
- **Dust rarely breaches in the live replay:** its limit is an 8-hour average, and the replay
  compresses data-hours into seconds. Methane and wet-bulb breaches (instantaneous) do fire,
  about one every 20 s somewhere in the fleet (most at Nandira, OD-ANG-57).
- **PPE vision in this environment uses the fixture backend:** there are no YOLO weights on the
  machine. The browser check cannot drive a file picker, so vision is covered by `VisionCest`
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

No new regulatory facts were introduced in Phases 1-2. Every limit, period and obligation code the
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
