# Progress (governance backend, branch `feat/governance-backend`)

Brief: `CLAUDE_CODE_TASK.md`. Plan and decisions: `PLAN.md`. Data: `data/HANDOFF.md`.

## Remaining phases, in order

5 (grievances, done) → **5B** (done) → 6 (multilingual, done) → 7 (automation, ai-service, done) → **7B** (field app, done) → 8 (hardening, done).
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
- **Phase 7B - offline-capable PWA for field inspection** (owner addition, 2026-09-27; done 2026-09-28, see below).
  - Installable PWA with an offline shell and cached reference data.
  - A checklist-driven field inspection: geo-tagged (with accuracy), time-stamped observations
    with camera photos, in an IndexedDB outbox with per-item status.
  - Idempotent batch sync by client UUID, with resumable photo upload, the same scoping and
    validation as the normal endpoints, and conflicts surfaced rather than dropped.
  - Sync creates observations, violations (with alerts) and corrective actions through the
    existing services, so the audit chain and history stay intact.
  - Device time and out-of-boundary locations are flagged.
  - Tested by an offline → online browser run.

## Phase 8: Hardening (done, 2026-09-29)

**Real-phone results:** none were reported (the message kept its placeholder), so none were fixed;
the field app is still tested only in a headless browser emulating a 360 px Android phone.

### Parity sign-off, then `backend/` removed

Every prototype endpoint maps to the new API, or was dropped on purpose (recorded in Phase 2,
`docs/API_CHANGES.md`):

| Prototype (`/api/v1`) | Now | Evidence |
|---|---|---|
| `POST /auth/login`, `GET /auth/me` | `POST /v1/auth/login`, `GET /v1/users/me` (+ `/auth/me`) | `AuthCest`, `UsersMeCest` |
| `GET /mines`, `/mines/{id}` | same, plus `/mines/geojson` | `ScopingCest`, `DashboardCest` |
| `GET /dashboard` | same payload, plus `/views/overview` | `DashboardCest`, `DemoScoreCest` |
| `GET /sensors`, `/sensors/breaches`, `/sensors/{id}`, `/sensors/{id}/trend` | same | `SensorCest` |
| `GET /sensors/live`, `/sensors/{id}/live` | **dropped** (the frontend never used them; charts poll the trend) | Phase 2 decision |
| per-tick IsolationForest `anomaly_score` | **not ported**: trained on the prototype's 5-mine seed, never shown; sensor anomalies are the evaluated flatline detector | `ai-service/ml/README.md`, `docs/AI_EVALUATION.md` |
| `GET /inspections` (queue) | `/v1/inspections/priority` (now ordered by the Governance Risk Index) | `DashboardCest`, `RiskCest` |
| `GET /violations`, `/alerts` + ack / directives / resolve / reopen, `/audit` | same (+ `?since=`) | `AlertCest`, `AuditTrailCest` |
| `POST /vision/analyze` | same, the API calls the ai-service | `VisionCest` |
| compliance, corrective actions (stubs) | built | `CorrectiveActionCest` |
| `WS /ws` | polling (Phase 2 decision) | - |
| simulator writing the database | `scripts/run_simulator.py` through `POST /v1/sensor-readings/ingest` (API key) | `SensorCest` |
| scoring, breach window, ranking | ported unchanged | `ComplianceScoreServiceTest` (the prototype's numbers) |

Moved before the removal: the Python environment (`backend\.venv` -> `ai-service\.venv`), the PPE
weights and training runs (-> `ai-service\ml\`, gitignored), the sample and held-out images
(-> `ai-service/samples/`), and the prototype's demo seed (-> `data/reference/prototype_seed/`, the data
track's source S01; same files, same SHA-256, `mines_base.csv` reproduces byte for byte).

Removed: `backend/` (tracked files are in git history; its untracked leftovers - `.env`,
`smartmine.db`, `sensor_anomaly.joblib`, two uploads - were moved to `D:\SIH_backend_removed_2026-09-29`,
outside the repo, for you to delete), and the prototype-only scripts (`demo_vision.py`,
`generate_sensor_data.py`, `seed_db.py`, `train_sensor_model.py`, `run_backend.ps1`). `setup.ps1`
was rewritten for the current stack. Every command and document that named `backend/` now names the
new place; the remaining mentions say it was removed.

### 1. Reliability

- `scripts/supervisor.ps1`, started by `run_all.bat` (window SIH-Supervisor): every 30 s it checks the
  API, the ai-service, the frontend and the field server; two failed checks in a row -> restart
  (Apache through `api_server.bat`, else `serve.bat`; the others in their SIH-* windows); every
  restart in `api\runtime\logs\supervisor.log`; after three failed restarts it retries every 5 min.
  Tested by killing the ai-service: logged, restarted, answering again.
- `run_all.bat` starts the field server when the field app is built, and `--stop` closes the
  supervisor first.
- `GET /v1/system/status` (cached 20 s) and a footer warning on every signed-in screen, in six
  languages, while detection runs on the PHP fallback (service down, or `AI_ENGINE=php`).

### 2. Demo reset

`scripts\demo_reset.bat`: a `pg_dump` snapshot of "seed demo + jobs/all" (23.5 MB, rebuilt when the
generated data, a migration, the seeder, the RBAC config or the rules change; `-Rebuild`, `-Status`),
restored with `pg_restore` as the application role - it ends the API's other sessions, drops the
application's own tables, sequences and functions, restores everything but the PostGIS extension and
its reference table, runs `ANALYZE` - then `yii demo/check`: the five mines, the fleet average and
bands, no breach in the window, the audit chain. **About 6 s** end to end.

### 3. Pre-demo check

`scripts\predemo_check.bat` changes nothing and prints READY or a numbered list, with the fix for
each: PostgreSQL, the API and a demo sign-in, the PPE weights and the model file, the ai-service
running the real model, detection not on the fallback, the dashboard, the field server, the
certificate (not expired, covering every current LAN address), the supervisor, fonts bundled (no web
font links, the @fontsource packages present), Windows sleep and hibernate on mains, mains power,
free disk space, the demo scores and the audit chain.

### 4. Problem statement coverage

`docs/PROBLEM_STATEMENT_COVERAGE.md`: 18 rows (feature, screenshot, evidence, limitations) and
questions judges may ask, answered from the docs.

### 5. Security pass (`docs/SECURITY.md`)

- `composer audit`: none. `npm audit`: 1 high (Vite dev server) -> **Vite 7.3.6**; 2 moderate left
  (React Router 6; not reachable in this app, v7 migration planned). `pip-audit` (ai-service and data
  environments): none.
- New: sign-in failure limits (10 per account, 50 per address, 15 min), security headers on every
  API answer and on the field server (CSP included), no exception details unless
  `API_DEBUG_ERRORS=1` (never a trace), PHP version hidden, uploads capped at 12 MB in PHP,
  `X-Forwarded-For` trusted only from the local proxy, a cryptographic JWT secret from `setup.ps1`.
- Checked, unchanged: CORS (explicit origins, no credentials), upload types by content, bcrypt,
  HS256 with a 256-bit key, the API listening on 127.0.0.1 only, parameterised SQL.

### 6. Fresh-clone test

Cloned to `D:\SIH_CLONE_TEST` and followed only the README and `docs/SETUP_WINDOWS.md`, reusing the
installed PostgreSQL (role, databases, extensions), PHP and Composer. Result:

| Step (as documented) | Result |
|---|---|
| `scripts\setup.ps1` | **Gap found and fixed:** `composer install` failed - XAMPP's PHP has the `zip` extension off and PowerShell has no `unzip` (the original install ran from Git Bash, which has one). `setup.ps1` now runs Composer with `-d extension=zip`, stops on failure, and skips pip when the environment is complete; `SETUP_WINDOWS.md` 4 lists `zip`. npm install and the ai-service environment (pip, from the cache) worked. |
| `api\.env` | created with a fresh random JWT secret (cryptographic generator); `DB_PASSWORD` copied as step 4 says |
| `dataun_data.bat setup`, `generate demo`, `validate demo` | ok; the mines extraction reads the moved prototype seed; validation 16/16 (demo scores 100/80/70/60/45, 83.2; determinism) |
| `apiun_tests.bat` | 187 tests, 3,551 assertions, all passing (the clone was at the part-1 commit) |
| `scripts\demo_reset.bat` (migrate, RBAC, seed, jobs, snapshot, restore, check) | ok, the same baseline and audit chain as the main checkout |
| `run_all.bat` | ok: Apache (the clone's own configuration), ai-service, dashboards, supervisor; field app not built, as documented |
| `scripts\predemo_check.bat` | everything ok except the PPE weights (not in git by design; the check prints the build command) and this laptop's sleep setting |
| `run_all.bat --stop` | **Bug found and fixed:** npm renames its window, so `--stop` left Vite running on 5173; it now also frees the stack's ports |

Also found then: issuing the simulator key from a second checkout replaces the first one's
(documented in `SETUP_WINDOWS.md` 8). Not re-run in the clone: building the PPE weights (25 min
and the S13 download; unchanged since Phase 4) and sections 1-3 (PostgreSQL, PostGIS, role and
databases were reused, as asked). Its secret copies (`api\.env`, the simulator key) were deleted; the
folder `D:\SIH_CLONE_TEST` (2 GB, dependencies) is left for you to delete - my tools may not remove a
top-level folder.

**Docker**: `docker-compose.yml` is **kept, marked UNTESTED** in its first lines and in
`SETUP_WINDOWS.md`; it covers the database and the API only.

### 7. Documentation

- `docs/API.md`: every endpoint (111) with its auth and the permission it checks, generated by
  `php yii docs/api`; `ApiDocTest` fails when it is stale or when a new token endpoint checks nothing.
- README and `docs/architecture.md` rewritten for the current system; `SETUP_WINDOWS.md` opens with the
  fresh-clone checklist and gains 6b (ai-service environment), 6c (demo data), 7c (reset and check);
  the demo script uses the reset and the check.
- Brief Phase 8 test list: all present; added `ScopingCest::mineHeadGets404ForAnotherMinesRecords`
  (contractors, production, grievances, alerts in one test) and `audit/verify` after the test run in
  `run_tests.bat`.

### Tests, browser checks, performance

- `apiun_tests.bat`: locale checks clean (1,418 keys x 6 languages); ai-service 19 pytest; API
  **188 tests, 3,571 assertions, all passing** (none skipped); `audit/verify` intact after the seed
  and again after the test run. New: `ApiDocTest`, `AuthCest` (sign-in limits, headers),
  `ScopingCest::mineHeadGets404ForAnotherMinesRecords`.
- **Every browser check, phases 2-7B** (`scriptsun_browser_checks.ps1`, a reset before each):
  all 8 passed - phase 2 (21 screenshots), 3 (16), 4 (15), 5 (16), 5B (21), 6 strict (57, no
  overflow), 7 (8), 7B strict (13); the browser errors they log are the ones they provoke (404, 403,
  422, offline). Summary: `docs/screenshots/browser_checks.txt`.
- **Performance**: every dashboard request under 150 ms, slowest p95 52 ms - after fixing a 4-5x
  slowdown caused by Windows power throttling of hidden processes (`scripts/no_throttle.ps1`,
  applied at start and by the supervisor every round; `docs/PERFORMANCE.md`, Phase 8).
- `scripts\predemo_check.bat` on this laptop: everything ok except Windows sleep on mains (45 min) -
  your setting to change.

### Known issues (Phase 8)

- Real-phone test still to do (see above).
- The PPE weights are not in git (AGPL, size): a fresh clone runs the test fixture until
  `scripts\build_ppe_model.py` has run (about 25 min, needs the S13 dataset from `data\run_data.bat download`).
- React Router 6 moderate advisories (above); Docker untested.
- Windows sleeps after 45 min on mains on this laptop - `predemo_check.bat` says how to turn it off.
- `D:\SIH_backend_removed_2026-09-29` holds the prototype's untracked leftovers until you delete it.

### How to verify (Phase 8)

```bat
cd api && run_tests.bat
scripts\demo_reset.bat
scripts\predemo_check.bat
powershell -ExecutionPolicy Bypass -File scripts\run_browser_checks.ps1
node scripts\perf_check.mjs
cd api && yii.bat docs/api --check
```

## Phase 7B: Offline field app (done, 2026-09-28)

An inspector or a mine head records an inspection where there is no signal, and syncs later.

### Secure context: HTTPS on the LAN, or USB forwarding

- `run_field.bat` builds the app for phones and starts `scripts/field_server.mjs`. The build
  (`npm run build:field`, `.env.field`) reaches the API through the same origin.
- The server uses Node only, no packages. It serves `frontend/dist` and proxies `/v1` to the API,
  so there is no CORS and no mixed content:
  - HTTPS on the LAN, `:5443`.
  - HTTP on `localhost:5180`, for this PC and for USB port forwarding.
  - The CA certificate for phones at `http://<LAN>:5080/ca.crt` (nothing else).
- `scripts/make_cert.mjs` makes a local CA and a server certificate, with no admin rights and
  nothing installed on the PC.
  - It uses mkcert when it is on the PATH, otherwise Git's OpenSSL.
  - The server certificate covers `localhost`, the PC's name and its LAN addresses, and is valid
    for 397 days.
  - `certs/` is git-ignored. The server warns when the LAN address is no longer covered.
- Checked with strict verification: Node's TLS client accepts `https://<LAN>:5443` with
  `certs/ca.crt`.
- `docs/FIELD_APP_SETUP.md` gives click-by-click steps for Pixel, Samsung and older Android, the USB
  forwarding fallback (`chrome://inspect`) and troubleshooting.
- I did not install the CA on this PC or change the firewall.

### Field app (`/field`, inspector and mine head, `field.capture`)

- **Installable PWA.** It has a manifest with icons (192, 512, maskable).
- **Service worker, no new dependency.** A small build plugin writes `sw.js` with a precache list of
  89 files (about 3.1 MB): the app shell, scripts, CSS, `.woff2` fonts for every script, and the
  icons. It serves them cache-first. API calls are never cached by the service worker.
- **Offline data.** After the first sign-in, `/v1/field/bootstrap` is kept in IndexedDB: the account,
  the mines in scope (assigned ones first, with their coordinates), open assigned inspections, the
  versioned checklist, the categories with their violation types, the relevant obligations and the
  product settings.
- **Token.** The token is kept encrypted at rest (AES-GCM, with a non-extractable key).
- **Capture.** A checklist of 23 items across the 11 categories (`data/reference/field_checklist.csv`),
  each linked to verified obligations where one clearly applies. For each finding:
  - severity and the obligation;
  - a note;
  - up to 4 photos from the camera, compressed on the phone (longest side 1600 px, JPEG 0.72; the
    497 KB test photo was stored at 278 KB);
  - GPS with its accuracy, or "underground - no fix";
  - optionally a violation of a listed type, and a corrective action with a due date.
- **Geo-check.** A finding more than 5 km from the mine's recorded point is warned on the phone and
  flagged by the server, not blocked. The phone's clock is compared with the server's at sync:
  a difference over 5 min is flagged.
- **Queue.** Visits, captures and photos sit in IndexedDB with their status: waiting, syncing,
  synced, failed (with the reason), or "already on the server".
  - Sync sends one ordered batch, then the photos.
  - Nothing is deleted until it has synced.
  - An expired login keeps the queue and asks for the same account's password before syncing.
  - Items of another account on the same phone wait for that account.
- **Screen sizes.** Built for 360 px. The dashboards show a synced violation as **Field capture**,
  with flags, device and receipt times, location, distance, note and photos.

### Sync API (`FieldController`, `FieldSyncService`)

- `GET /v1/field/bootstrap`, `POST /v1/field/sync`, `POST /v1/field/photos`
  (`docs/API_CHANGES.md`, `docs/api-contract.md`).
- **Idempotent by the phone's UUID.** The first sync stores its result in `field_sync`, and a retry
  returns it as `replayed`. `observation.client_uuid` and `inspection.client_uuid` are unique too.
  A concurrent duplicate loses the insert race and returns the winner's result. An id belonging to
  another account is refused.
- **One transaction per item.** A failed item leaves nothing behind and does not stop the batch.
- **Through the existing services.** Scoping (404 for another mine), inspection transitions
  (scheduled -> visited; `INVALID_TRANSITION` when it was closed meanwhile), and
  `InspectionService` (observation, promotion to violation with its alert) all apply.
  `CorrectiveActionService` creates the corrective actions. Audit and status history are kept; the
  GPS point is written with the insert, so it is in the audit record.
- Unscheduled visits create a `spot` inspection (inspector) or a new `self` inspection (mine head).
- Migration `m261006_000001_field_capture` is reversible (down and up tested). The seeder accepts
  the new app-only columns, which are listed explicitly.

### Tests, performance, browser check

- `run_tests.bat`: locale checks clean (1,415 keys in each of the six languages). ai-service: 19
  pytest. API: 183 tests, 3,487 assertions, all passing; the vision detection test needs the
  ai-service running. New: `FieldCest`, with 5 scenarios and 91 assertions:
  - idempotent sync and photos, including the photo limit and another account's id;
  - scoping: a mine head's self-inspection, another mine is 404, another inspector's inspection is
    refused, government and corporate get 403;
  - geo and clock flags, and a bad item failing alone;
  - an expired token with a queued capture: 401, nothing written, then one clean sync;
  - a closed inspection.
- `node scripts/browser_check.mjs phase7b` runs at 360 px (Android user agent, touch, GPS emulated).
  Its own field server is stopped and the network is set to offline. 13 screenshots are in
  `docs/screenshots/phase7b/`, plus `sync.json` and `overflow.json`. The run covers:
  - sign in, then the app opens with no server and no network;
  - two findings with photos recorded offline, one 200 km away (flagged);
  - the login dropped while offline, and recording goes on;
  - back online, sign in to sync: "5 new";
  - the government mine page and the mine head dashboard show +2 open violations within about 7 s,
    with no reload;
  - the same queue resent: "5 already on the server", and the violations stay at 17 -> 19 -> 19;
  - home, visit, capture and queue probed in all six languages at 360 px: 0 overflow findings
    (`--strict`).
- Performance: every dashboard request under 150 ms, the slowest p95 70 ms; field bootstrap 17 ms
  (`docs/PERFORMANCE.md`).

### Known issues (Phase 7B)

- **Not tested on a physical phone here.** Offline mode, the camera input, GPS and 360 px were
  exercised in headless Edge with mobile emulation. The Android certificate steps follow the
  Android and Samsung settings menus as documented; menu names vary by vendor.
- **No new scheduled task.** Phones sync when their users tap Sync, and nothing server-side runs on
  a clock.
- **Photos upload whole** (at most 5 MB each after compression). There is no chunked or resumable
  upload: a failed photo is retried from the start and is idempotent by its id.
- **Checklist items record findings only.** An item checked and found fine is not stored.
- **Visits are not closed from the phone.** They stay "visited"; closing, with its lock, is done
  on the dashboard.
- The token at rest is encrypted with a key the browser will not export. That protects a copied
  storage folder, not a phone that is unlocked in someone else's hands. Sign-out removes it.
- `FieldCapture` loads the details of every field capture once per request (two queries). That is
  fine for thousands of captures; Phase 8 should load them only for the violations on the page.
- The 113 new strings in hi, bn, or, te and mr are unreviewed drafts, like the rest.
- The browser check logs one expected `ERR_INTERNET_DISCONNECTED`: the dashboard's session check
  while offline.

### How to verify (Phase 7B)

```bat
cd api && run_tests.bat
run_field.bat
node scripts\browser_check.mjs phase7b
node scripts\perf_check.mjs
```

Re-seed afterwards (`api\yii.bat seed demo`, then `api\yii.bat jobs/all`): captures made during a
demo or a check change the live scores. The demo scores come back identical after a re-seed.

## Phase 7: Automation, anomaly detectors and predicted risk (done, 2026-09-28)

Also in this phase, committed first (0f19236): incident reporting at the law's time (RPT-05 12 h,
RPT-04 its own legal time, RPT-03 "forthwith" plus a 1 h grace labelled as a product setting).
The mine heads keep their seeded languages.

### A. Scheduled jobs

- `yii jobs/<name>`: `reminders`, `sla`, `escalate-alerts`, `score`, `anomaly`, `contractor`,
  `obligation`, `production`, `grievance`, and `jobs/all` / `jobs/status`
  (`api/commands/JobsController.php`).
- Each job is idempotent: a second run finds nothing to do. Each holds a PostgreSQL advisory lock,
  so an overlapping copy records `skipped`. Each is logged to `job_run` and
  `api/runtime/logs/jobs.log`, and runs as the system (no user in history or audit).
- New: alert escalation (level 1 after 24 h open, level 2 after 72 h) and the production-entry
  reminder (`PRODUCTION_ENTRY_PENDING`). `jobs/score` keeps a daily history of the score and the
  index per mine (`mine_risk_snapshot`) and refreshes the predictions.
- `run_all.bat` runs `jobs/all` once at startup (about 5 s). `scripts/register_tasks.ps1`
  registers the jobs in Task Scheduler for the current user, with no admin rights (`-DryRun`,
  `-Unregister`). Documented in `docs/SETUP_WINDOWS.md` 7a. **Not registered on this machine** -
  that is the owner's call; only the dry run was executed.
- Migration `m261005_000001_phase7_automation` (reversible): `anomaly_flag`, `job_run`,
  `mine_risk_snapshot`, `mine_risk_prediction`, and the two alert codes.

### B. Governance Risk Index (the compliance score is unchanged)

- A separate 0-100 measure: capped points for open violations, sensor breaches, overdue
  obligations, overdue contractor documents, grievances past SLA and overdue corrective actions,
  times a repeat-violation multiplier. Weights are product settings in `rules.yaml`
  (`governance_risk_index`). `api/services/GovernanceRiskService.php`.
- Shown beside the compliance score on both mine screens, with its components as
  "count × points = value (max)".
- The inspection priority queue is ordered by it (the first reason is `PRIORITY_GRI`; urgency
  breaks ties).
- A mine head's index leaves out the sensitive grievances it cannot see.
- Demo scores unchanged (100/80/70/60/45, fleet 83.2, before and after `jobs/all`), and
  `DemoScoreCest` is unchanged. Demo fleet: 6 high, 24 medium, 44 low.

### C. Detectors in the ai-service, PHP fallback kept

- Seven stateless detectors in `ai-service/detectors/` (`POST /anomaly/{name}`): production
  anomaly, flatlined sensor, night-shift concentration, repeat violations, late corrective
  actions, contractor outlier, grievance cluster.
- Identical PHP twins in `api/services/detectors/`, used when the service is down or answers an
  error (`AI_ENGINE=auto|php|ai-service`).
- Parity is tested on shared fixtures dumped from the demo database, and both sides must produce
  the same output number for number.
- Findings become `anomaly_flag` rows and `ANOMALY_DETECTED` alerts. A finding no longer made is
  cleared and its alert resolved. The engine that produced each finding is shown.
- The vision code moved into `ai-service/vision/`. The service still runs from `backend\.venv`
  (see `ai-service/requirements.txt`).

### D. Predicted risk (one trained model)

- `ai-service/risk/train.py`: a HistGradientBoosting model (monotonic, Platt-calibrated) on
  `data/reference/msha_rates.csv`, real US coal mine-years. It predicts a high-accident next year
  (lost-time and fatal accidents at 3 or more per 100 workers).
- Split by time: fit on 2017-2020, calibration on 2021, test on 2022-2024.
- Test results: AUC 0.816 against 0.766 for the baseline (this year's lost-time rate). Precision
  0.667 / recall 0.569 against 0.658 / 0.630. Brier 0.160 against 0.233; ECE 0.037.
- Exported to `model.json` and evaluated identically in Python and PHP.
- Applied through the crosswalk features. Violation rates are transferred by percentile, because
  US inspection intensity is about 5x ours.
- On screen: probability, band, fleet rank, the top factors in plain language, and the statement
  that it is trained on US regulator data and transferred. The same is in the docs and in
  `GET /v1/risk/model`.

### E. Evaluation

- `docs/AI_EVALUATION.md` has three sections: the detector table, the model's results with a
  reliability table, and the PPE model.
- The detector table is regenerated by `php yii ai/evaluate --write`.
- `AiEvaluationTest` recomputes the table and fails if the page is stale.

| Detector | Scenarios | Flags | True positives | Missed | False positives (flags / mines) | Decoys correctly ignored |
|---|---|---|---|---|---|---|
| production_anomaly | S2 (decoy N1) | 5 | 1 / 1 | 0 | 4 / 4 | 1 / 1 |
| sensor_flatline | S3 | 1 | 1 / 1 | 0 | 0 / 0 | - |
| night_shift | S5 (decoy N3) | 1 | 1 / 1 | 0 | 0 / 0 | 1 / 1 |
| repeat_violations | S1 | 5 | 1 / 1 | 0 | 4 / 4 | - |
| late_actions | S7 | 1 | 1 / 1 | 0 | 0 / 0 | - |
| contractor_outlier | S4 | 3 | 1 / 1 | 0 | 2 / 2 | - |
| grievance_cluster | S6 (decoy N2) | 1 | 1 / 1 | 0 | 0 / 0 | 1 / 1 |
| **All** | | 17 | **7 / 7** | 0 | 10 | **3 / 3** |

### API and frontend

- New endpoints: `GET /v1/views/priority` (queue plus findings in one request),
  `GET /v1/anomalies`, `GET /v1/mines/{id}/risk` and `GET /v1/risk/model`.
- `/v1/views/mine/{id}` gains a `risk` part.
- New permission `risk.view` for every role, scoped as usual (404 out of scope).
- Details are in `docs/API_CHANGES.md`, `docs/api-contract.md` and `docs/access-control.md`.
- UI: `frontend/src/components/risk/RiskPanel.jsx`. The index sits beside the score, and the
  panel below it shows the components, the predicted risk and the findings. The priority tab
  lists the fleet's findings. Alerts carry an "Escalated L1/L2" tag.
- 67 new strings, in all six locales. `check_locales` and `check_hardcoded_strings` are clean.

### Tests, performance, browser check

- `run_tests.bat`: locale checks clean. ai-service: 19 pytest (detectors and model against the
  fixtures, statelessness, monotonicity, model card). API: 178 tests, 3,406 assertions, all
  passing. New: `DetectorParityTest`, `AiEvaluationTest`, `JobsTest` and `RiskCest`.
  `DashboardCest` now checks the GRI order and `ViewCest` the `risk` part; `DemoScoreCest` is
  unchanged.
- Performance: every dashboard request is under 150 ms. The slowest p95 is 57 ms; the new
  priority view is 28 / 29 ms (`docs/PERFORMANCE.md`).
- `node scripts/browser_check.mjs phase7 --side-tabs`: 8 screenshots in
  `docs/screenshots/phase7/`, with no browser errors. The two side tabs polled with 0 failed
  requests. `jobs.txt` has the fallback run, the ai-service run and the idempotent rerun.

### Known issues (Phase 7)

- **Scheduled tasks are not registered** on this machine (the owner decides). They run only while
  the user is logged on, and they need PostgreSQL running.
- **An ai-service started before this update** answers 404 on the new endpoints, so the jobs
  silently use the PHP fallback. Restart the `SIH-AI` window after updating;
  `GET :8001/health` then shows `detectors` and `risk_model`.
- **With the service unreachable**, the first request waits for the connection to fail:
  `jobs/anomaly` takes about 6 s and `jobs/score` about 5 s, instead of 1-2 s.
- **10 false positives** on the demo data (4 production dips at the start of the month, 4 repeat
  violations followed by a matching incident by chance, 2 contractors with high violation
  rates). They are explained in `docs/AI_EVALUATION.md`.
- **Predicted risk is transferred, not validated here.** It was trained on US data, and our data
  is synthetic. It is a ranking prompt, not a calibrated probability for India.
- **Index weights are product settings**, not fitted to outcomes.
- **The 67 new strings in hi, bn, or, te and mr are unreviewed drafts**, like the rest of those
  files.
- Findings name a contractor by id ("Contractor #32"). `job_run` survives a reseed (it is a log).

### How to verify (Phase 7)

```bat
cd api && run_tests.bat
api\yii.bat jobs/all
api\yii.bat jobs/status
api\yii.bat ai/evaluate
api\yii.bat ai/evaluate --engine=ai-service
backend\.venv\Scripts\python -m pytest ai-service\tests -q
backend\.venv\Scripts\python ai-service\risk\train.py
node scripts\browser_check.mjs phase7 --side-tabs
node scripts\perf_check.mjs
powershell -ExecutionPolicy Bypass -File scripts\register_tasks.ps1 -DryRun
```

Re-seed after a browser check (`api\yii.bat seed demo` then `api\yii.bat jobs/all`).

## Phase 6: Multilingual (done, 2026-09-28)

### What changed

- **Languages:** en, hi, bn, or, te, mr (`frontend/src/i18n/locales/`). `en.json` is the source,
  with 1,236 keys; the other five are complete translations of it (details below).
- **Which language shows** (`docs/i18n.md`):
  - Signed out (login, raise and track a grievance): a small switcher on each page, kept in the
    browser until login.
  - After login the account's saved `preferred_language` wins, and signing out returns to the
    browser's choice.
  - `<html lang>` follows the language.
- **Profile page** (`/profile`, sidebar link "Profile and language", every role):
  - shows name, email, role, company, area and mine;
  - lists the six languages in their own scripts;
  - choosing one saves it (`PATCH /v1/users/me`) and switches the whole interface at once, with no
    reload.

  `/v1/users/me` adds `mine_code`, `subsidiary_name` and `area_name` (`docs/API_CHANGES.md`).
- **Every UI string through `t()`:** 146 hard-coded strings in 26 files were replaced, including:
  - chart axes, legends and tooltips;
  - map controls and credits;
  - toasts, aria labels and the tab lists (now built at render, so they follow a switch);
  - violation types, incident causes, obligation titles, evidence types, frequencies, audit field
    names, and the field names in API errors.

  Alerts and errors render from `{code, params}` in every language. **Stays as written:** legal
  citations and quotes (the labels around them are translated); grievance texts (with their
  language labelled), notes and reasons; names of mines, companies and people.
- **Numbers and dates:** Intl in the UI language as `<lang>-IN` with Latin digits, so Indian
  grouping (17,04,240) and localised month names apply everywhere (`utils/format.js`). Formatting
  that was scattered (`toLocaleString([])`, `toFixed`, `en-IN`-only helpers) now goes through it.
  - Chromium has no Odia locale data and silently printed English months. Such a language formats
    as `en-IN` with the month names from the locale file; the browser check guards it.
- **Fonts:** Noto Sans Devanagari, Bengali, Oriya and Telugu are bundled (`@fontsource`, 400-700),
  as fallbacks after Inter in the text and mono stacks. There are no external font requests, and
  the check confirms the faces load with the network blocked.
- **Checks in the test run** (`api\run_tests.bat`, also `npm run i18n:check`):
  - `scripts/check_locales.mjs` fails on a missing or extra key, a changed `{{placeholder}}`, or a
    missing `_meta.reviewed`;
  - `scripts/check_hardcoded_strings.mjs` parses the frontend and fails on hard-coded UI text.

### Translations

- Drafted with Claude by one agent per language, using `data/reference/glossary.csv` for the
  agreed terms. They were then checked mechanically:
  - all keys and placeholders present;
  - codes, Act names, product names and licence names kept;
  - demo mine names in Latin script, as the data shows them;
  - "mine head" made consistent in Telugu.
- Every file says `"_meta": {"reviewed": false}`, and the Profile page tells a non-English user the
  texts are drafts.
- Terms the drafts flagged for a native reviewer (per language; the same themes recur):
  - "Inspector-cum-Facilitator" (RPT-05 evidence), "bound paged book", "depillaring districts",
    "return air";
  - `status.promoted`;
  - "Core sample board";
  - "Call for Detailed Report" (tone);
  - how the two-part sentence `board.topN` + `board.ofMonitored` reads.

### Browser check (`node scripts/browser_check.mjs phase6 --side-tabs --strict`)

57 screenshots in `docs/screenshots/phase6/`, all steps passed:
- the login switcher (Telugu, kept in the browser);
- the saved preference winning after login (Gevra's head: browser Telugu, account Hindi, so
  Hindi);
- the Profile page switching to Odia at once and saving it;
- in all six languages: login, government overview, mine detail, production, grievances,
  obligations, an obligation task drawer, map, profile.

Every screen is probed for clipped, spilling or cut-off text and anything past the window's edge
(`overflow.json`). The probe proves itself first on three planted labels.

**Overflow findings:**
- First run: **0**, but only because the probe checked each element's own overflow. On review of
  the screenshots, the board's "100" gridline label was visibly cut off in every language,
  English included (an existing bug).
- The probe was extended to catch text cut by a clipping container, and then reported exactly that
  finding on 6 screens (`div.board-track > div.board-rule > span "100"`, cut by 9 px).
- Fixed with top padding on `.board-scroll`. Final run: **0 findings on 54 screens at 1440x900,
  and 0 at 1366x768** (`VIEWPORT=1366x768`).
- Also fixed from the check: English month names in Odia (Chromium lacks the locale).
- No request left the machine (network blocked), and the Noto faces loaded on every non-English
  screen.

The Phase 2, 3, 4, 5 and 5B browser checks were rerun on fresh seeds and all pass. `page.as()` now
saves the account's language first (English by default), because most seeded mine heads prefer
their state's language.

### Tests and performance

- API: 155 tests, 1,795 assertions, all passing, plus the two locale checks at the start of the run.
  `UsersMeCest` covers the new profile fields.
- Performance: every dashboard request under 150 ms, the slowest p95 85 ms (`docs/PERFORMANCE.md`).

### Known issues (Phase 6)

- **Translations are unreviewed drafts** (see above). Review, then set `_meta.reviewed` to `true`
  per file.
- **Data stays in its source language:** the obligation `due_rule` text, contractor and mine
  names, and the account's own name ("DGMS Compliance Authority").
- **Leaflet's own attribution prefix** and the OpenStreetMap credit stay in English. The OSM credit
  is the wording OSM requires.
- **Machine translation of user content** (grievances, notes) is roadmap (`docs/i18n.md`).
- **The mine heads' seeded languages** change the demo: Bhubaneswari opens in Odia; Moonidih, Gevra
  and Block-B in Hindi. `docs/demo-script.md` says how to switch to English for a step.

### How to verify (Phase 6)

```bat
cd api && run_tests.bat
cd frontend && npm run i18n:check
node scripts\browser_check.mjs phase6 --side-tabs --strict
set VIEWPORT=1366x768 && node scripts\browser_check.mjs phase6
node scripts\perf_check.mjs
```

Re-seed after a browser check (the checks save the accounts' languages).

## Phase 5B follow-ups (done, 2026-09-28)

- **Performance on mains power** (`docs/PERFORMANCE.md`): every dashboard request is under 150 ms
  (medians 11-51 ms, slowest p95 55 ms, government production). The battery run was the machine.
  Nothing needed fixing.
- **Incidents on the obligation register:**
  - Each incident has one on-event task for its reporting obligation (RPT-03 / RPT-04 / RPT-05),
    due 48 hours after it occurred. It is done at `reported_at`, on time or late.
  - The historical tasks come from the data track (`gen_obligations.py`): 83 in demo, 8 late,
    matching `reported_within_48h`. They are appended with no random draws, so no existing row
    moves, and V12 checks them.
  - The API creates the task when an incident is reported (`POST /v1/incidents`; migration
    `m261004_000001`).
  - Statutory compliance counts them by `reported_at`. The demo scores are unchanged (V8,
    `DemoScoreCest`).
  - The 48 h is a product setting labelled as such (`incident_notice_hours`), matching the
    incident's reporting check. The rules' own wording stays in the citation and is stricter for
    two of them: RPT-03 *forthwith*, RPT-05 *within twelve hours*.

## Phase 5B: Obligation register and GIS map, plus the grievance tracking code (done, 2026-09-28)

### Fix first: grievance tracking code

- At submission the API issues a random 8-character code (`ABCDEFGHJKMNPQRSTUVWXYZ23456789`, no
  0/O, 1/I/L), shown once on the confirmation screen next to the ticket number. Only an
  HMAC-SHA256 of it is stored (`grievance.tracking_code_hash`, migration `m261002_000001`).
- Tracking is now `POST /v1/grievances/track` with `{ticket_no, tracking_code}` (the old GET is
  removed), so the code never appears in a URL. A wrong code gets byte for byte the response of an
  unknown ticket; the comparison is constant-time, against a dummy hash for unknown tickets.
- Seeded grievances carry codes generated deterministically in the data track
  (`common.tracking_code(seed, ticket_no)`, validate check V13); `yii seed` stores only the hash.
- The confirmation screen hands the code to the tracking page in router state, not the URL.
- `GrievanceCest` (9 tests) and the phase5 browser check (new shot `04b-public-track-wrong-code`)
  cover it; the phase5 screenshots are refreshed.

### Data track

- `data/generators/gen_obligations.py` produces `obligation` (40), `obligation_applicability`,
  `obligation_task` and `obligation_submission`, plus evidence `file` rows. It comes with schema
  YAMLs, validate check V12, a card in `data/DATASETS.md` and the load order in `data/HANDOFF.md`.
- Only verified obligations with a calendar frequency get tasks; RPT-08 (TODO-VERIFY) never does.
  SAF-07/09/12 apply to underground or mixed mines, SAF-01 to a workforce of 500 or more.
- Filing discipline varies per mine: mostly on time, some late, some overdue and still open, and
  about 3 % of submissions rejected (60 % of those resubmitted). Demo preset: 4,506 tasks and
  3,595 submissions (108 rejected).
- `data/scripts/clean_state_boundaries.py` builds the offline map's outlines, deterministically:
  - `data/reference/state_boundaries.geojson`: 36 states and UTs from DataMeet, 148 KB;
  - `data/reference/map_districts.geojson`: the 28 Census 2011 districts that contain the real
    roster's 74 mines, placed by point in polygon, 125 KB.

  The older `district_boundaries.geojson` was built from the prototype seed's district names, and 6
  of today's mines (Giridih, Ranchi, Chhindwara, Paschim Bardhaman, Surajpur) had no outline in it.
  The script now runs after `clean_mines_real.py`.
- All presets regenerated and validated (14/16 checks pass as before, V12 and V13 included);
  determinism byte-identical. Among existing files only `grievance.csv` and `file.csv` changed.

### Obligation register (API)

- Migration `m261003_000001`: the four tables with CHECKs, FKs and indexes; alert codes
  `OBLIGATION_DUE_SOON` and `OBLIGATION_OVERDUE`.
- `yii seed` loads the register and backfills its history (submissions, reviews) into the audit
  chain.
- The mine head submits evidence (file required, note optional). Government or inspector accepts
  or rejects; a rejection needs a reason, which the mine sees; accepting resolves the overdue
  alert. Government may also waive a task for its period, with a reason (PLAN's `waived`). A waived
  task leaves statutory compliance, and its overdue alert is resolved. Corporate reads only.
- Reminders come 3 days before a due time (one alert per mine and due time). Past due, a task
  becomes `overdue` (level 1, `OBLIGATION_OVERDUE`); still nothing 168 h later, `escalated`
  (level 2). All alerts are `{code, params}`, with the citation in the params. The check runs on
  register reads (once per request), in `yii obligation/check [--at=ISO]` and in `run_all.bat`.
- The PHP schedule mirrors the generator exactly (`ObligationScheduleTest`).
- **Statutory compliance** is a separate metric (tasks due in the last 90 days, evidence on time
  and accepted), per mine, company and domain. **The compliance score formula is unchanged.** The
  demo scores (100/80/70/60/45, 6/21/47, 83.2) are identical and `DemoScoreCest` is green;
  `ObligationCest::statutoryComplianceLeavesTheScoreAlone` checks that obligation alerts do not
  move them.
- RBAC: `obligation.view` (all), `obligation.submit` (mine head), `obligation.review`
  (government, inspector), `obligation.waive` (government), `obligation.summary` (government,
  corporate, inspector).
- Obligations without dated tasks are listed under *Obligations not on the dated register*, each
  with its citation and how it is handled:
  - limits the sensor rules watch (SAF-11, HLT-04, HLT-05; `monitored_by` comes from `rules.yaml`);
  - continuous duties;
  - every shift;
  - on an event;
  - once or on renewal.

### GIS map

- Works with no internet: Leaflet 1.9.4 is bundled, and the state and district outlines come from
  local data through `/v1/geo/states` and `/v1/geo/districts` (ETag, cached a day).
- Mines are at their real coordinates, coloured **and** labelled by risk band. The tooltip gives
  score, open alerts, district and location quality, with approximate locations dashed. District
  outlines are limited to the districts of the mines in scope, and a shared district never names
  another company's mine. A click opens the mine
  (a mine head goes to their overview). Scope is the API's: government sees all 74 demo mines,
  corporate SECL 17, a mine head one.
- The OpenStreetMap basemap is optional and off by default. When on, it carries the OSM
  attribution and loads only the tiles in view; offline it says the tiles are unavailable and the
  outlines stay. Global Energy Monitor (CC BY 4.0) and DataMeet are credited on the map.

### Frontend

- Obligations and Map tabs on the mine head dashboard and on the government / corporate /
  inspector dashboard; each is one request per polling cycle (`/v1/views/obligations`,
  `/v1/views/map`).
- Mine head: statutory compliance, then due soon, overdue, open, submitted and recently accepted.
  Every row shows act and section. The task drawer shows the verbatim quote with source file and
  page, the due time and its basis (law or product setting), the evidence history and the upload
  form.
- Government / corporate:
  - compliance per company;
  - the most overdue items;
  - evidence awaiting review (oldest first; the state filter narrows it);
  - compliance per mine (lowest first) and per domain;
  - review in the drawer.
- All new UI text goes through i18n (`obligation.*`, `map.*`, alert and status keys).

### Tests, performance, browser check

- API: 154 tests, 1,785 assertions, all passing (new: `ObligationCest` 7 and
  `ObligationScheduleTest` 2; `GrievanceCest` updated). One test depended on today's date (a
  reminder for another due time at the same mine) and is fixed.
- New screens (`scripts/perf_check.mjs`): obligations 73-87 ms median (p95 at most 90), map
  34-41 ms, district outlines 58 ms on first load. This run was on battery power, and every screen was about three times slower than in
  Phase 5, including untouched ones. The Phase 5 commit and this code, run side by side on the same
  database, gave identical times. So the slowdown is the machine, not the code
  (docs/PERFORMANCE.md).
- `node scripts/browser_check.mjs phase5b --side-tabs`: 21 screenshots in
  `docs/screenshots/phase5b/`, all steps passed:
  - the mine head submits evidence;
  - government rejects (refused without a reason, then with one) and accepts the resubmission;
  - government waives an overdue task with a reason;
  - the obligations without dated tasks, with how each is handled;
  - an item goes overdue with its alert, then escalates to level 2 (`yii obligation/check --at`);
  - the register views for mine head, government and corporate;
  - the map for all three roles with every off-machine request blocked (28 tile requests failed;
    the outlines stayed).

  The side tabs polled throughout with no failure.

### Known issues (Phase 5B)

- **Where the owner's rules override PLAN.md:**
  - PLAN had on-event notice tasks created from incidents. The owner's rule for this phase was
    that only verified obligations with a calendar frequency get tasks. The owner then asked for
    incident tasks as a follow-up (done, see above); other on-event duties are listed without
    tasks.
  - PLAN had an optional "overdue obligations" score component (weight 0). The owner's rule is
    not to touch the score formula, so there is none.
- **Source PDF pages are named, not linked:** the legal PDFs (`raw/legal/`) are not in the
  repository.
- **`obligation/check --at`** simulates a later time for statuses and alerts, but the alerts and
  history record the real time at which the command ran. It also creates the tasks of the periods
  started by then. Reseed after using it.
- **Evidence files of the seeded history are not stored.** The drawer says *File not stored (demo
  history)*; only evidence uploaded through the app has a file.
- **The review queue shows the 50 oldest** items in scope, with a note and the state filter to
  narrow it; there is no paging yet.
- **The street map needs internet** and follows the OSM tile usage policy: interactive use only,
  off by default, no prefetching or bulk download. A deployment with real traffic should use its
  own tile server or a commercial provider.
- **Performance was measured on battery**; rerun `node scripts/perf_check.mjs` on mains power for
  numbers comparable with earlier phases.

### How to verify (Phase 5B)

```bat
run_all.bat
api\yii.bat seed demo
api\yii.bat obligation/check
api\yii.bat obligation/summary
cd api && run_tests.bat
node scripts\perf_check.mjs
node scripts\browser_check.mjs phase5 --side-tabs
node scripts\browser_check.mjs phase5b --side-tabs
```

Re-seed after a browser check (phase5b moves the obligation clock forward).

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
- **Anyone with a ticket number sees its status.** Fixed in Phase 5B: tracking needs the ticket
  number and its tracking code.
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
