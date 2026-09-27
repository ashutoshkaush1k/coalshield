# Progress (governance backend, branch `feat/governance-backend`)

Brief: `CLAUDE_CODE_TASK.md`. Plan and decisions: `PLAN.md`. Data: `data/HANDOFF.md`.

## Remaining phases, in order

5 (grievances) → **5B** → 6 (multilingual) → 7 (automation, ai-service) → **7B** → 8 (hardening).
The full scope of each is in `PLAN.md` §6.

- **Phase 5B - compliance obligation register and GIS map** (owner addition, 2026-09-27).
  - **Register:**
    - The 40 obligations of `data/reference/obligations.csv` are loaded as a cited catalogue:
      instrument, clause, quote, and a link to the source PDF page.
    - Due tasks are generated per mine only for verified, calendar-frequency obligations. On-event
      ones come from the events themselves; continuous limits are shown as "monitored"; RPT-08
      stays TODO-VERIFY and is never generated.
    - Mine heads submit evidence per task.
    - Government, corporate and inspector see the register across mines, compliance % per mine and
      per domain, and the overdue / escalated queue. Government and inspector accept or reject
      submissions; corporate is read-only.
    - Overdue raises `OBLIGATION_OVERDUE` and escalates; reminders come as `OBLIGATION_DUE_SOON`.
    - Due days not stated in law are product settings, labelled as such.
  - **GIS map:**
    - Leaflet with OpenStreetMap: mines coloured by risk band (with a text label too) and the 35
      district boundaries.
    - The state filter and click-through to mine detail work as elsewhere.
    - Scoped per role like `/v1/mines`, and approximate locations are marked.
- **Phase 7B - offline-capable PWA for field inspection** (owner addition, 2026-09-27).
  - Installable PWA with an offline shell and cached reference data.
  - A checklist-driven field inspection: geo-tagged (with accuracy), time-stamped observations
    with camera photos, in an IndexedDB outbox with per-item status.
  - Idempotent batch sync by client UUID, with resumable photo upload, the same scoping and
    validation as the normal endpoints, and conflicts surfaced rather than dropped.
  - Sync creates observations, violations (with alerts) and corrective actions through the
    existing services, so the audit chain and history stay intact.
  - Device time and out-of-boundary locations are flagged.
  - Tested by an offline → online browser run.

## Phase 5: Grievance handling, plus two owner decisions (done, 2026-09-27)

### Decisions recorded

- **AGPL-3.0:** the repository is public and open-source for SIH, which satisfies AGPL-3.0. A
  closed deployment would need a licence review first (README, `docs/AI_EVALUATION.md`).
- **Phases 5B and 7B** are added to `PLAN.md` §6 and to "Remaining phases" above:
  - 5B: the compliance obligation register and GIS map, after Phase 5;
  - 7B: an offline PWA for field inspection, after Phase 7.

### Phase 5: grievances

- **Migration** `m261001_000001`:
  - `grievance` and `grievance_action` (CHECKs, FKs, indexes, and a partial index for sensitive
    grievances);
  - `grievance_ticket_counter` + `next_grievance_ticket()` for `GRV-YYYY-NNNNNN` from a per-year
    counter that never goes below the highest stored ticket;
  - `rate_limit`, the FK `observation.grievance_id`, and `file.uploaded_by` nullable only for a
    public attachment.
- **Seeding:** `yii seed` loads 374 grievances and 1,748 timeline steps, and their history goes into
  the audit chain (without names or contacts).
- **Public pages (no login), linked from the login page:**
  - `/grievance` is the form: mine, who you are, anonymous or not, category (safety: which kind),
    about the mine head, language, text, optional file and location;
  - `/grievance/track` shows status and the public timeline only.
  - The API rate-limits both per IP, accepts only PDF, JPEG or PNG up to 5 MB, and rejects a filled
    honeypot.
- **Mine head:** a queue for its own mine, most urgent first. It can acknowledge, investigate,
  resolve (a note is required; the complainant sees it), close, reopen (a new SLA period) and
  assign, with the timeline shown.
- **Government and inspector:** all grievances and the analytics:
  - totals, open, SLA breaches and breach rate, average time to first resolution, escalated and
    open;
  - breakdowns by category, by mine and by language;
  - breach clusters, the escalated queue, and filters (open / escalated / sensitive).
- **Corporate:** the same analytics, for its company's mines (read-only).
- **SLA:** per category from `rules.yaml` (product settings). A breach escalates to level 1 and
  raises `GRIEVANCE_SLA_BREACHED`; one more SLA period without resolution takes it to level 2.
  - The check runs on reads and in `yii grievance/check` (`run_all.bat`); it is idempotent and a
    system action.
  - On the demo seed the first check records 7 new breaches (reopened seeded grievances still
    unresolved past their new SLA period) and 7 escalations to level 2 (open grievances a full SLA
    period past their breach; some are the same grievances).
- **Sensitive routing** (`AccessRule`): harassment, or a grievance against the mine head, is
  assigned to the government and does not exist for the mine head - not listed, 404 by id, and not
  visible through its alert, the open-alert count or the audit trail.
  - The complainant's identity is never serialised to a mine head (the keys are absent), and is
    shown to corporate only for grievances that are not sensitive.
  - Both are proved by `GrievanceCest`.
- **Safety grievances** open a linked observation (category from the form), unless sensitive: an
  observation would show a sensitive case to the mine head.
- **Scenarios:**
  - S6 (Kulda, OD-SUN-07) is flagged as a breach cluster: 7 grievances raised 1-9 September, all
    breached; 8 after the first check adds a reopened one.
  - The N2 decoy (Jhanjra, WB-BAR-08: six grievances, all within SLA) is not.
  - `GrievanceClusterTest` scores this against `scenario_expectations.json`: TP 1, FN 0, FP 0,
    TN 1, and no other mine is flagged.
- **Languages:** grievance texts are shown as written, labelled with their language (for example
  "Hindi · हिन्दी"). All new UI text is in `en.json` (`grievance.*`, the public pages, the new
  error and field codes, statuses, audit labels).
- **Tests:** 145 tests, 1,490 assertions, all passing.
  - `GrievanceCest` (9): public submit and track, the honeypot, file and field checks, the rate
    limit, safety → observation, sensitive routing, identity, the queue workflow, SLA escalation,
    and analytics per role.
  - `GrievanceClusterTest`.
- **Browser check:** `node scripts/browser_check.mjs phase5 --side-tabs` saves 15 screenshots in
  `docs/screenshots/phase5/`, with a government tab and a mine-head tab polling alongside. All
  steps passed and no browser errors were logged. It covers:
  - public submission and tracking (the outcome after resolution included);
  - the Gevra mine head's queue and detail, with no identity shown;
  - government analytics with the Kulda cluster;
  - a sensitive case at Gevra seen by government and absent (404 by id) for its mine head;
  - corporate SECL scope.
- **Performance:** the grievance screens take 44 ms (government) and 14 ms (mine head) median, in
  one request each (`docs/PERFORMANCE.md`).

### Known issues (Phase 5)

- **Rate limits are per IP.** Behind a shared NAT (a mine colony, a cyber cafe) many people share
  one address. The limits are env settings (`GRIEVANCE_SUBMIT_PER_HOUR`,
  `GRIEVANCE_TRACK_PER_MINUTE`). There is no CAPTCHA, by design.
- **Anyone with a ticket number sees its status.** Tickets are sequential, but tracking shows no
  text and no people, and it is rate-limited.
- **Satisfaction rating:** the column is loaded and shown, but there is no public endpoint yet for
  a complainant to rate.
- **Severity** at submission follows the category (a product setting); staff cannot change it yet.
- **Translation of grievance text** is not done: texts are shown as written (brief Phase 6: machine
  translation is roadmap).

### How to verify (Phase 5)

```bat
run_all.bat
api\yii.bat seed demo
api\yii.bat grievance/check
api\yii.bat grievance/clusters
cd api && run_tests.bat
node scripts\browser_check.mjs phase5 --side-tabs
```

Re-seed after a browser check.

## Phase 4: Production reporting, plus the PPE rerun and performance work (done, 2026-09-27)

### PPE model: second training run

- **Run 2:** 25 epochs, 640 px, nothing frozen. About 114 min of CPU time; the machine slept about
  6 h during epoch 12, so the wall clock was longer.
- **Held-out test results:** mAP50 0.878 vs 0.850, mAP50-95 0.595 vs 0.560, precision 0.818 vs
  0.777, recall 0.889 vs 0.848. Vest was the only class to drop, slightly (0.950 → 0.932).
- **Kept:** run 2, installed as `backend/ml/weights/ppe.pt`. `scripts/build_ppe_model.py` now
  rebuilds it by default. `docs/AI_EVALUATION.md` records both runs.
- **End to end** on the test split, with the product's own violation rule: the violation set is
  exactly right on 92 of 126 images that show people (73 %).
- **Demo images:** only held-out test images. `scripts/select_ppe_demo_images.py` copied three
  clean frames and three with violations to `backend/data/samples/heldout/` (CC BY 4.0,
  attributed; selected from the agreeing images, which the folder says).
  - Through the product: `heldout_06` gives 3 × `no_helmet`, and Jayant goes 80 → 65.
  - Fixture mode is removed from the demo script; it remains for automated tests only.

### Performance

- **Serving:** the API runs under XAMPP's Apache (mod_php, thread-safe build) on 8080.
  - `scripts/api_server.bat` writes a self-contained config (vhost, `php.ini` with OPcache) into
    `api/runtime/apache/`. XAMPP's own files are not touched.
  - `run_all.bat` starts and stops it. `api/serve.bat` (`php -S`, now also with OPcache) remains the
    fallback.
- **Caching and connections:**
  - persistent PostgreSQL connections under Apache;
  - schema and RBAC file caches, flushed by `migrate`, `seed` and `rbac/init`.
- **Fewer requests per poll:**
  - one aggregated endpoint per screen (`/v1/views/overview`, `/v1/views/mine/{id}`,
    `/v1/views/production`, `/v1/views/production-overview`), so at most two requests per screen
    and cycle;
  - polling every 10 s instead of 5.
- **Query fixes:**
  - `contractors/summary` computes its per-mine scores in one pass;
  - the sensor trend is one LATERAL query instead of six;
  - alert histories load in one query.
- Numbers are in `docs/PERFORMANCE.md`:
  - every screen now takes 18-45 ms median (p95 at most 52 ms) in one request per poll, the same
    with two dashboards side by side;
  - before, a cycle took 374-853 ms, and 1.2-1.4 s with two dashboards open.

### Phase 4: production

- **Migration** `m260930_000001`: `daily_production` (unique mine, date and shift),
  `production_edit_log` and `production_detail_request`, with CHECKs, FKs and indexes.
- **Seeding:** `yii seed` loads all three tables from `data/out`, and their history is written into
  the audit chain (`seed_history`).
- **Mine head:**
  - daily entry: draft → submit (locked) → correct with a reason, each change going to the edit log;
  - charts: target vs actual with anomaly days marked, month-to-date cumulative, shift split;
  - an inbox of calls for detailed report, with a response form (note and file).
- **Government, corporate and inspector:**
  - a numbers-only table: day and month-to-date target, actual, achievement %, and the anomaly flag;
  - a "Call for detailed report" button (date range, reason, due date) and request statuses;
  - the detail view (charts, response note, entries with their corrections) only for answered
    ranges, otherwise 403 `DETAIL_REQUEST_REQUIRED`.
  - The rule lives in `api/components/AccessRule.php`, as a third access layer next to scoping and
    RBAC.
- **Escalation:** overdue requests escalate automatically. At the deadline the request becomes
  overdue and raises `DETAIL_REQUEST_OVERDUE` at level 1; 72 h later it becomes escalated at
  level 2.
  - The check runs on every read of the requests or summary and in `yii production/check`.
  - It is idempotent and recorded as a system action.
- **Anomaly detection (PHP):** a day is flagged when output is more than 50 % over target, or when
  both output and output ÷ target deviate strongly from the mine's 30-day rolling mean.
  - Settings are in `rules.yaml` `product.production_anomaly`.
  - On the demo data it flags 5 of 6,142 mine-days. S2 (Gevra, 4 September) is flagged at 2.0x
    with z 14.4; the decoys N1 and N3 are not.
  - `ProductionAnomalyTest` scores the detector against `scenario_expectations.json`: TP 1, FN 0,
    FP 0, TN 2.
- **i18n:** all new UI text is in `en.json` (`production.*`, `detailRequest.*`, new error and field
  codes, statuses, audit labels).
- **Tests:** 135 tests, 1,288 assertions, all passing.
  - `ProductionCest` (7): entry, submit and correction; summary; the detail gate before, during and
    after a request; out-of-scope 404; roles; escalation; the views.
  - `ProductionAnomalyTest` (3).
  - `ViewCest` (5).
  - `ContractorServiceTest`: the one-pass per-mine scoring equals scoring each mine separately.
- **Browser checks:** `node scripts/browser_check.mjs phase4` saves 15 screenshots in
  `docs/screenshots/phase4/`. It covers the government numbers-only table, the 403
  explained, the call for a report, then as the Gevra mine head: entry, submit, correction and the
  edit log, and the answer. Back as government: the detail view and closing the request; then
  corporate SECL.
  - Phases 2, 3 and 4 were rerun with `--side-tabs`, with a government tab and a mine-head tab
    polling alongside: all passed. The only browser errors logged are the expected 403 and 404.

### Known issues (Phase 4)

- **The PPE model is still out of distribution for mines.** It misses people on the repository's
  own sample photos, and there is no coal-mine footage to train or test on. (AGPL-3.0: decided
  2026-09-27 - see Phase 3 known issues.)
- **Four of the detector's five flags are real step changes, not scenarios.** Month-boundary
  jumps in the synthetic data (1-2 August and 2 September, at four mines) are flagged alongside S2. They are defensible
  deviations, but not planted ones.
- **The deadline check runs on reads**, so a request turns overdue when someone next looks (or at
  `yii production/check`). Scheduled jobs come in Phase 7.
- **The escalation window (72 h), the lock window (7 days) and the anomaly thresholds** are product
  settings in `rules.yaml`, not law.
- **The first request after an Apache restart** opens each thread's database connection (about
  120 ms, once per thread).

### How to verify (Phase 4)

```bat
run_all.bat
api\yii.bat seed demo
api\yii.bat production/check
api\yii.bat production/anomalies
cd api && run_tests.bat
node scripts\perf_check.mjs aggregated
node scripts\browser_check.mjs phase4 --side-tabs
```

Re-seed after a browser check.

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

- ~~**The PPE model is weak out of distribution**~~: retrained in Phase 4 (see above). The demo
  now uses held-out test images with the real model.
- **AGPL-3.0 - decided 2026-09-27:** the repository is public and open-source for SIH, which
  satisfies AGPL-3.0. A closed deployment would need a licence review first (README,
  `docs/AI_EVALUATION.md`).
- ~~**The PHP built-in server handles one request at a time**~~: the API runs under Apache since
  Phase 4 (`docs/PERFORMANCE.md`).
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

No new regulatory facts were introduced in Phases 1-4 (Phase 3 cites LAB-02, SAF-04 and HLT-01
from `rules.yaml`; its due day and warning window are product settings). Phase 4's lock window,
escalation window and anomaly thresholds are product settings too, and production reporting cites
no statutory return (the RPT-08 production return stays TODO-VERIFY below). Every limit, period and obligation code the
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
