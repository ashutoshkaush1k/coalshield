# PLAN — CoalShield governance backend

Branch `feat/governance-backend` (local only, created from `live-sprint` at `2509d16`). **Nothing is
committed or pushed.** Status: **draft, waiting for approval before Phase 1** (brief §0.2).

Written 2026-09-24 after reading README, PRD, COORDINATION.md, all of `docs/`, the whole `backend/`
tree, `scripts/`, and `frontend/src/{api,auth,routes,pages}`.

---

## 0. Read this first — questions that block Phase 1

Brief §0.7 says to ask rather than guess when the brief and the repo disagree. Four of the
disagreements change what Phase 1 builds, so I need answers to these before starting. Everything
else in §3 has a recommended default I will use unless you say otherwise.

| # | Question | Why it blocks |
|---|---|---|
| **B1** | **Ownership.** `COORDINATION.md` ground rules: *Agent 1 (Naman) owns `backend/`, Agent 2 owns `frontend/`*. This brief creates `api/` + `ai-service/` and finally deletes `backend/`. Has Naman agreed, and who owns `api/` and `ai-service/`? He is still committing to `backend/` on `live-sprint` (5 commits since 11 Sep), so the thing being ported keeps moving. | Wrong answer = two people rewriting the same system, or a port of a stale backend. |
| **B2** | **Toolchain is not installed on this machine** (checked 2026-09-24): no PostgreSQL/PostGIS, no Composer, no Docker, no WSL. PHP **8.2.12** exists only as XAMPP (`C:\xampp\php`), not on PATH; `pdo_pgsql`/`pgsql` DLLs are present but **disabled** in `php.ini`; `intl` and `sodium` are not loaded. May I (a) install PostgreSQL 16 + PostGIS (EDB installer + StackBuilder) and Composer, and (b) edit `C:\xampp\php\php.ini`? Or do you prefer Docker Desktop (needs WSL2)? | Brief §0.3 requires every phase to end with "migrations from scratch + seed + all tests". None of that can run today. I will not install software or change system PHP config without a yes. |
| **B3** | **5 mines or 74?** The brief (and the stale README) say 5 seeded mines. The repo seeds **74 mines across 10 states and 10 operating companies**; the national dashboard, region filter, "top 5 highest-risk nationally" board and the demo script (national avg **83.2**, 6 High / 21 Medium / 47 Low) are all built around 74. Phase 8's "`yii seed` … 5 mines" would delete that. | Recommend **keep all 74**, with the five named mines as the anchored demo set. Decides the Phase 1 seed. |
| **B4** | **403 → 404 for out-of-scope records.** `docs/access-control.md` chose 403 deliberately, `tests/test_access_control.py` asserts 403, and `docs/demo-script.md` step 11 tells presenters to run that test in front of judges. The brief mandates 404. | Recommend **follow the brief (404)** — it answers the doc's real concern (a filtered empty list), and hides existence too. I will rewrite the doc, the demo script and `client.js` handling to match. Needs your explicit yes because it reverses a documented decision. |

**Also: "DO NOT PUSH".** The brief asks for a commit at the end of every phase. I will make those
commits **locally on `feat/governance-backend` only** and never push, unless you tell me otherwise.

---

## 1. What exists today

### 1.1 Stack as it actually runs

| Piece | Reality |
|---|---|
| Backend | FastAPI + SQLAlchemy, SQLite file `backend/smartmine.db`, Python 3.14, venv at `backend/.venv` |
| Frontend | React 18 + Vite 5 + Recharts 2, axios, react-router 6, `@fontsource/*` fonts bundled locally (no CDN — offline demo) |
| Realtime | **Polling only** (`hooks/usePolling.js`, 5 s + refetch on tab focus). `realtime/ws.py` is a 1-line docstring and the frontend never opens a socket. The brief's "alerts over websocket" does not match the code. |
| CV | `ultralytics` YOLO, weights `backend/ml/weights/ppe.pt` (gitignored) |
| Sensor anomaly | IsolationForest (scikit-learn), `backend/ml/weights/sensor_anomaly.joblib` (gitignored), threshold currently 0.59 |
| Launch | `run_all.bat` (pre-flight, SIH-Backend + SIH-Frontend windows, `--sim`), `scripts/setup.ps1` |
| Tests | 280 pytest tests, all passing today |

### 1.2 Data model (8 tables)

| Table | Key columns |
|---|---|
| `mines` | code (unique), name, location (display string), district, state (indexed), region, **operator** (company name string) — **no coordinates, no subsidiary/area FK** |
| `users` | email, password_hash (PBKDF2), full_name, role `GOVERNMENT`/`MINE_HEAD`, mine_id (NULL = Government) |
| `violations` | mine_id, violation_type, confidence, source, frame_ref, detected_at, resolved, resolved_at |
| `sensor_readings` | mine_id, sensor_type, value, unit, recorded_at, breached, resolved, resolved_at; index (mine_id, breached, resolved) |
| `alerts` | mine_id, source (VISION/SENSOR/GOVERNMENT), severity, **message (display text)**, reference_id, alert_type (SYSTEM/DIRECTIVE), status (OPEN/RESOLVED), raised_by, acknowledged, created_at |
| `corrective_actions` | mine_id, violation_id, alert_id, description, status, created_by, proof_image_path, created_at, resolved_at |
| `compliance_scores` | mine_id, score, risk_level, violation_count, breach_count, weight_ppe, weight_env, computed_at (history) |
| `audit_logs` | mine_id, actor (string), action, entity_type, entity_id, **detail (display text)**, created_at — **no hash chain, no old/new values** |

There is **no `inspection` table**. "Inspections" today is a computed ranking, not records.

### 1.3 Endpoints (20 mounted, base `/api/v1`)

| Method | Path | Access | Response |
|---|---|---|---|
| POST | `/auth/login` | public | `{access_token, token_type, user:{id,email,full_name,role,mine_id}}` |
| GET | `/auth/me` | any | `CurrentUserOut` |
| GET | `/mines` | scoped | `MineSummary[]` (mine fields + `compliance` + `open_alerts`) |
| GET | `/mines/{id}` | scoped | `MineDetail` (+ `total_readings`) |
| GET | `/dashboard?state=` | any, role-aware | `{role, scope, state, scope_label, states[], showing, is_truncated, stats{…}, mines[], inspection_queue[]}` |
| GET | `/sensors?state=` | Government | `FleetSensorOut` (per-mine standing, `status_label`, anomaly fields) |
| GET | `/sensors/breaches?state=&mine_id=` | scoped | 6-hour breach buckets by category |
| GET | `/sensors/live?after=&limit=&state=` | Government | cursor feed, `reset` flag |
| GET | `/sensors/{id}/live?after=&limit=` | scoped | cursor feed |
| GET | `/sensors/{id}?sensor_type=&breached_only=&limit=` | scoped | `SensorReadingOut[]` |
| GET | `/sensors/{id}/trend?points=` | scoped | 3 series with threshold, `points[].id` (charts append by id) |
| GET | `/inspections?limit=&state=` | Government | ranked queue with `urgency`, `trend`, **`reasons[]` (display text)** |
| GET | `/violations?mine_id=&limit=` | scoped | `ViolationOut[]` |
| GET | `/alerts?mine_id=&unacknowledged_only=&alert_type=&limit=` | scoped | `AlertOut[]` (+ `resolutions[]`) |
| POST | `/alerts/{id}/ack` | scoped | `AlertOut` |
| POST | `/alerts/directives` | Government | one-click flag; message + severity composed server-side |
| POST | `/alerts/{id}/resolve` | scoped | multipart `proof_text` + optional `file` |
| POST | `/alerts/{id}/reopen` | Government | `{reason}` |
| GET | `/audit?mine_id=&action=&limit=` | scoped | `AuditLogOut[]` |
| POST | `/vision/analyze` | scoped | multipart image/video → detections, violations, `score_before/after`, `annotated_url`, resolution fields |

Plus `GET /health`, `/static/annotated/*`, `/static/proof/*`.

**Documented but not implemented** (1-line docstring stubs, not mounted): `/compliance/{id}`,
`/compliance/{id}/history`, `/corrective-actions` CRUD, `/ws`. Their frontend modules
(`api/compliance.js`, `api/correctiveActions.js`) are empty too.

Pagination today is `limit` only — no `page`/`per_page`, no `X-Total-Count`. Errors are FastAPI's
`{"detail": "..."}`, which `client.js` reads.

### 1.4 Seed data and demo accounts

- 74 mines, 10 states, 10 operating companies: CCL 12, BCCL 9, NCL 8, SECL 8, MCL 7, WCL 7, ECL 6,
  NLC India 6, Singareni 6, Coal India Ltd 5.
- 75 users: 1 Government + 74 Mine Heads (one per mine). Password `demo123` for all.
- `sensor_readings.csv` (2,664 readings), `violations.json`. Generated with a fixed RNG seed.
- The five named mines — and the brief's subsidiary mapping is **already satisfied by `operator`**:

| Code | Mine | Operator | Clean score |
|---|---|---|---|
| JH-DHN-01 | Jharia Coalfield Block A | Bharat Coking Coal Ltd (BCCL) | 100 LOW |
| MP-SGR-02 | Singrauli Opencast Mine | Northern Coalfields Ltd (NCL) | 80 LOW |
| CG-KRB-03 | Korba Gevra Expansion | South Eastern Coalfields Ltd (SECL) | 70 MEDIUM |
| WB-RNG-04 | Raniganj Deep Shaft | Eastern Coalfields Ltd (ECL) | 60 MEDIUM |
| OD-TLC-05 | Talcher Underground Unit 2 | Mahanadi Coalfields Ltd (MCL) | 45 HIGH |

Demo logins: `gov@dgms.gov.in`, `head.<code-lowercase>@coalmine.in` (e.g. `head.od-tlc-05@coalmine.in`).
The README's older table (Jharia 88 / Korba 70 / Talcher 46, "all 5 mines") is stale.

### 1.5 Scoring and risk bands

```
score = clamp(100 − (open_ppe_violations × WEIGHT_PPE + in_window_breaches × WEIGHT_ENV), 0, 100)
        rounded to 1 decimal; band taken from the ROUNDED score
WEIGHT_PPE = 5, WEIGHT_ENV = 3, BREACH_WINDOW_HOURS = 0.0033 (12 s; 0 = all-time)
LOW ≥ 80 · MEDIUM 50–79 · HIGH < 50
```

- Violations count until a clean vision re-run resolves them (`services/compliance/resolution.py`,
  which requires a detected workforce so an empty frame cannot clear a mine).
- Breaches are not resolved; they age out of the rolling window. Every simulator tick re-scores
  every mine and writes a history row on change.
- All values env-driven (`backend/.env`). Thresholds: gas 50 ppm, dust 10 mg/m³, temp 45 °C.

### 1.6 Alert logic

- **Vision**: one alert per violation; severity by confidence (≥0.80 HIGH, ≥0.60 MEDIUM, else LOW).
- **Sensor**: one alert per breaching reading; severity by margin over the limit (≥30 % HIGH,
  ≥10 % MEDIUM, else LOW).
- **Directive**: Government raises against a mine; with no body the server composes message and
  severity from the mine's band at click time. Mine Head resolves with text + optional photo;
  Government can reopen (earlier attempts kept). Every step audited.
- No escalation levels, no `since` cursor, no reminders, no notification table.

### 1.7 Inspection prioritisation

`urgency = (100 − score) + trend_pressure × WEIGHT_TREND (2.0)`, trend = rise in violation +
breach events over the last 24 h vs the 24 h before, floored at 0. Ties: urgency, severity, recent
events, mine_id. `/dashboard` embeds the top 3 for Government.

### 1.8 Access control

One choke point, `api/deps.py` + `services/access/scope.py`. Scope comes from the JWT/DB user,
never a query param. Out-of-scope → **403** before the query runs. Government-only: `/inspections`,
`/sensors`, `/sensors/live`, directive raise and reopen.

### 1.9 Audit

`services/audit/recorder.record()` — explicit calls from vision, IoT, directives, scoring. Free-text
`detail`. No automatic capture of model changes, no tamper evidence.

### 1.10 Frontend contract surface

Every call lives in `frontend/src/api/*.js`: `auth` (login, me), `mines` (list, get), `dashboard`,
`sensors` (readings, trend, fleet, breaches), `inspections`, `violations`, `alerts` (list, ack,
directive, resolve, reopen), `audit`, `vision` (analyze). Things the UI depends on that a port
must keep: `points[].id` stability (charts append by id), `stats.breach_window_hours` (labels),
`status_label` strings, `scope_label`/`states`/`is_truncated` (region filter), `score_before/after`
(vision panel), role strings `GOVERNMENT`/`MINE_HEAD` (`auth/roles.js`), error `detail` text and the
403 branch in `client.js`. Routes: `/login`, `/gov`, `/gov/inspections`, `/gov/mines/:id`, `/mine`.
No i18n library; all strings hard-coded.

---

## 2. Gaps against the brief

| Brief | Today | Work |
|---|---|---|
| Yii2 API on PostgreSQL + PostGIS | FastAPI on SQLite | New `api/`, new DB |
| Roles government / corporate / mine_head / inspector | 2 roles | Add corporate + inspector; RBAC via `DbManager` |
| Subsidiary / area / mine hierarchy, geometry | `operator` string, no coordinates | New tables; see Q8–Q10 |
| Scoping in `ScopedActiveQuery`, 404 | Scope guard, 403 | Rebuild; B4 |
| Per-action RBAC permissions | Role checks only | New |
| Hash-chained audit on every model, `audit/verify` | Explicit free-text calls | New `AuditBehavior` |
| `StatusTransition`, history tables | Ad-hoc status strings | New |
| `{code, params}`, error envelope | Display text in 6 shapes, `{"detail"}` | Q7 |
| `page`/`per_page`/`filter[]`/`sort`/`X-Total-Count` | `limit` | New listing layer, keep `limit` alias |
| Locked records with edit reason | None | Production (Phase 4), inspections (Q11) |
| Monthly-partitioned `sensor_reading` | Plain table | Partitioned table |
| Sensor ingest over HTTP with API key | Simulator writes the DB directly | New endpoint, rewrite simulator |
| Corrective actions, compliance endpoints | Stubs | Build (not "port") |
| Contractors, production, grievances | None | Phases 3–5 |
| i18n, 6 locales, profile page | None | Phases 1, 6 |
| Queue jobs, reminders, SLA, escalation, notifications | None | Phase 7 |
| Stateless ai-service | CV and anomaly in-process | Phase 7 (and Q13) |
| Docker compose | None | Phase 1 |

---

## 3. Conflicts and open questions (with the default I will use)

B1–B4 are in §0. The rest have a default; say if you want otherwise.

| # | Conflict | Default |
|---|---|---|
| Q5 | Base path: brief `/v1`, repo `/api/v1`. Env var: brief `VITE_API_URL`, repo `VITE_API_BASE_URL`. | Yii2 serves **`/v1`**; frontend reads `VITE_API_URL`, falling back to `VITE_API_BASE_URL` for one release. Listed in `API_CHANGES.md`. |
| Q6 | Role values: the frontend compares `"GOVERNMENT"`/`"MINE_HEAD"`; the brief uses lowercase names. | RBAC item names lowercase as in the brief; **API emits lowercase**; `auth/roles.js` updated in the Phase 2 switch. |
| Q7 | Rule 7 (`{code, params}`, no display text) vs rule 12 (keep shapes). Six shapes carry text today: `alert.message`, `audit.detail`, `inspection.reasons[]`, `sensor.status_label`, `vision.resolution_reason`, the composed directive message. | Phase 2 emits **both** `code` + `params` **and** the legacy text field, so the dashboards keep working unchanged. Phase 6 moves the UI to codes and drops the legacy fields. Both steps recorded in `API_CHANGES.md`. |
| Q8 | `mine.location geometry(Point)` — the seed has **no coordinates**. | Column **nullable**. I will not invent coordinates for named mines. If you want points on a map, give me a source; otherwise district-level approximations can be added, each marked `TODO-VERIFY` and flagged approximate. |
| Q9 | Brief hierarchy subsidiary → area → mine; the repo has **no area data**, and real area names would need verification. | `area` table built; seed **one placeholder area per subsidiary**, named `"<SUB> Area 1 (TODO-VERIFY)"`, listed in `PROGRESS.md`. |
| Q10 | 3 of 10 operators are not Coal India subsidiaries (NLC India, Singareni) or are the holding company itself (5 mines under "Coal India Ltd"). | `subsidiary` = **operating company**, with a `parent_company` column. Corporate scoping works per operating company. No claim about corporate structure beyond what the seed already says. |
| Q11 | Brief rule 9 ("closed inspections are locked") and Phase 5 ("safety grievance → observation/violation candidate in the inspection flow") assume inspection records. None exist. | Phase 2 adds a **minimal** `inspection` (mine, inspector, scheduled / visited / closed, findings) and `observation` (candidate finding, can be promoted to a violation), with `StatusTransition` and locking. The computed priority queue stays as it is. |
| Q12 | "Port with parity" includes corrective actions and compliance endpoints, which are stubs. | **Build** them in Phase 2 (CRUD + transitions for corrective actions; current + history for compliance). |
| Q13 | Vision is part of the Mine Head dashboard, which Phase 2 must verify end to end, but the ai-service is Phase 7. | Stand up a **minimal `ai-service/` in Phase 2** with only `POST /vision/ppe`, importing the existing vision code in place. Phase 7 completes the move and adds the other endpoints. |
| Q14 | The simulator's `--check-only` / `--require-clean` pre-flight reads SQLite directly. | Re-implement against a small `GET /v1/admin/baseline-check` (government only), keeping the same banner and exit codes. |
| Q15 | Demo timing: 12 s breach window, 2 s ticks. | Unchanged, still env-driven. |
| Q16 | Existing Python tests (280). | Keep them running until Phase 8 parity; the service-level vision and anomaly tests move with the code into `ai-service/tests`. |

---

## 4. Design decisions (how the non-negotiables get implemented)

- **Scoping** (rule 2): `ScopedActiveQuery::forCurrentUser()` on a base `ScopedActiveRecord`. Each
  model declares its mine path: `'mine_id'` directly, or a relation chain (`contract.mine_id` for
  workers and documents). government = no filter; corporate = `mine.subsidiary_id =
  user.subsidiary_id`; mine_head = `mine_id = user.mine_id`. A `findScoped()` helper throws
  `NotFoundHttpException` → **404** (per B4). No controller builds a mine filter itself; a test
  greps the controllers for raw `mine_id` conditions.
- **Production detail gate** (Phase 4): a second, declared rule type in the same layer —
  `AccessRule::detailRequestCovers($mineId, $from, $to)`, used by the permission check and
  returning 403 `DETAIL_REQUEST_REQUIRED`.
- **RBAC** (rule 3): `yii\rbac\DbManager`, roles → permissions per action; a `RbacController`
  console command installs them idempotently; controllers call `checkAccess('contractor.create')`.
- **Audit chain** (rule 4): `AuditBehavior` on every ActiveRecord, rows written in the same
  transaction. `row_hash = sha256(prev_hash ‖ canonical_json(row))`. The chain head is serialised
  with `pg_advisory_xact_lock` so concurrent writes cannot fork it. `yii audit/verify` recomputes
  the chain and reports the first broken id. Audit summaries are `{code, params}`.
- **Workflows** (rule 5): `StatusTransition` component; models declare `transitions()`; an invalid
  transition → 422 `INVALID_TRANSITION`; each transition writes the entity's history table and
  the audit log.
- **Errors** (rule 7): one `ApiErrorHandler`: `{"error": {"code", "fields"?, "params"?}}`.
- **Listing** (rule 8): one `ListingQuery` helper for `page` / `per_page` / `filter[x]` / `sort` and
  `X-Total-Count`; accepts the legacy `limit` as an alias for `per_page`.
- **Locking** (rule 9): `LockableBehavior` — edits to a locked record need a `reason`, stored in an
  edit-log table, else 422 `REASON_REQUIRED`.
- **Partitioning** (rule 10): `sensor_reading` declaratively partitioned by month on
  `recorded_at`; PK `(id, recorded_at)`; a job creates the coming months' partitions ahead of time.
  Nothing FK-references it (alerts keep a loose `reference_id`, as today).
- **Secrets** (rule 11): `api/.env` loaded with `vlucas/phpdotenv`; `.env.example` committed.
- **ai-service down**: `AiClient` with timeout + retries; on failure it logs, raises a system alert
  `AI_SERVICE_UNAVAILABLE`, and returns partial data. No dashboard depends on it.

---

## 5. Dependencies

**API (Composer)** — the brief's own list: `yiisoft/yii2` ^2.0, `yiisoft/yii2-queue` (DB driver),
`yiisoft/yii2-httpclient`, `bizley/jwt`, Codeception (bundled with the app template). One
addition, justified:

| Package | Why |
|---|---|
| `vlucas/phpdotenv` | Rule 11 (secrets only in `.env`); Yii2 basic has no `.env` loader. |

Nothing else: GeoJSON via PostGIS `ST_AsGeoJSON`, MIME sniffing via PHP's built-in `fileinfo`,
password hashing via `Yii::$app->security`, rate limiting via Yii2's `RateLimiter` with a
DB-backed identity for anonymous IPs.

**Frontend (npm)** — `i18next`, `react-i18next` (brief), plus `@fontsource/noto-sans-devanagari`,
`-bengali`, `-oriya`, `-telugu` (Phase 6). The app already bundles fonts through `@fontsource`
rather than a CDN so the demo works offline; Hindi and Marathi share Devanagari.

**ai-service (pip)** — a subset of today's `requirements.txt`: fastapi, uvicorn, python-multipart,
ultralytics, opencv-python, numpy, scikit-learn, pillow. No database drivers.

---

## 6. File-by-file plan

Paths are relative to the repo root. Each phase ends with: migrate from scratch → seed → all tests
→ update `PROGRESS.md` → local commit `phase N: …` → stop.

### Phase 1 — Foundation

`api/` scaffold:
- `composer.json`, `yii`, `yii.bat`, `web/index.php`, `.env.example`, `codeception.yml`
- `config/web.php` (JSON only, `v1` module, CORS for `http://localhost:5173`, URL rules),
  `config/console.php`, `config/db.php`, `config/params.php`, `config/test.php`
- `modules/v1/Module.php`
- `components/ApiController.php` (JSON, auth, CORS, verbs), `components/ApiErrorHandler.php`,
  `components/ApiException.php`, `components/JwtAuth.php`, `components/ListingQuery.php`
- `components/ScopedActiveRecord.php`, `components/ScopedActiveQuery.php`
- `components/AuditBehavior.php`, `components/AuditChain.php`
- `components/StatusTransition.php`, `components/LockableBehavior.php`
- `components/FileStorage.php` (local disk outside `web/`, sha256, size + MIME whitelist)
- `models/Subsidiary.php`, `Area.php`, `Mine.php`, `User.php`, `AuditLog.php`, `File.php`
- `modules/v1/controllers/AuthController.php` (`POST /v1/auth/login`),
  `UserController.php` (`GET`/`PATCH /v1/users/me`, language only)
- `commands/RbacController.php` (roles: government, corporate, mine_head, inspector),
  `commands/AuditController.php` (`verify`), `commands/SeedController.php` (Phase 1 slice)
- `migrations/`: enable `postgis`, `pg_trgm`, `pgcrypto` · `subsidiary` · `area` · `mine` ·
  `user` · RBAC (Yii's own migration path) · `audit_log` · `queue` (yii2-queue's migration) · `file`
- `tests/api/AuthCest.php`, `UsersMeCest.php`, `ScopingCest.php` (all 3 roles),
  `AuditChainCest.php`; `tests/unit/ScopedActiveQueryTest.php`

Repo root: `docker-compose.yml` (`postgis/postgis:16`, api, frontend), `docs/SETUP_WINDOWS.md`
(native PostgreSQL + PostGIS + XAMPP PHP + Composer), `PROGRESS.md`.

Seed: 10 operating companies, placeholder areas (Q9), all 74 mines with `subsidiary_id` taken from
`operator`, all 75 existing users unchanged, plus `corporate.secl@coalmine.in` / `demo123`.

Frontend: `package.json` (+ `i18next`, `react-i18next`), `src/i18n/index.js`,
`src/i18n/locales/en.json`, `src/i18n/t.js` (for non-component code), `src/main.jsx` wrapped.
The UI still talks to FastAPI during Phase 1.

### Phase 2 — Port existing modules

- Migrations: `sensor_reading` (partitioned) · `violation` · `alert` (`code`, `params` JSONB,
  severity, mine_id, entity ref, status, `ack_by`, `escalation_level`, legacy `message`) ·
  `alert_resolution` · `corrective_action` (+ history) · `compliance_score` · `inspection` +
  `observation` (Q11) · `api_key` (simulator ingest) · indexes per rule 10
- Models for each of the above
- Services: `ComplianceScoreService` (formula, window, bands — identical numbers),
  `AlertService`, `DirectiveService`, `InspectionPriorityService`, `SensorService` (thresholds,
  ingest, fleet standing, breach buckets, live cursor feed), `VisionService` (via `AiClient`)
- Controllers: `MineController` (index, view, `geojson`), `DashboardController`,
  `SensorReadingController` (`ingest`, history, trend, fleet, breaches, live),
  `ViolationController`, `CorrectiveActionController`, `InspectionController` (priority queue +
  records), `AlertController` (`?since=`, ack, directives, resolve, reopen), `AuditController`,
  `ComplianceController`, `VisionController`, `AdminController` (`baseline-check`, Q14)
- `components/AiClient.php`
- `ai-service/` minimal: `app/main.py` with `POST /vision/ppe` (Q13), `requirements.txt`
- `scripts/run_simulator.py` rewritten to POST to `/v1/sensor-readings/ingest` with the API key
- Frontend: `api/client.js` (base URL, error envelope, 404), `auth/roles.js` (Q6),
  `hooks/usePolling.js` alert `since`, `.env.example`; both dashboards verified end to end
- Tests: every behaviour in `tests/test_access_control.py`, scoring, directives, fleet sensors,
  live feed, breach window and prioritisation ported to Codeception
- `docs/API_CHANGES.md` started

### Phase 3 — Contractor management

- Migrations: `contractor` · `contract` · `contract_worker` · `contractor_compliance_doc` ·
  nullable `contractor_id` added to `violation` and `corrective_action`
- Models + `services/ContractorService.php` (`score()`, alert checks)
- Controllers: `ContractorController` (+ `summary` for government/corporate),
  `ContractController`, `ContractWorkerController`, `ContractorDocController` (upload)
- RBAC: `contractor.*`, `contract.*`, `contractorDoc.*`, `contractor.summary`
- Alert codes: `CONTRACTOR_LICENCE_EXPIRING`, `WORKER_VT_EXPIRED`, `WORKER_MEDICAL_EXPIRED`,
  `CONTRACTOR_DOC_MISSING` (due day in config), `CONTRACT_WORKER_CAP_EXCEEDED`
- Frontend: `api/contractors.js`; `pages/minehead/contractors/ContractorList.jsx`,
  `ContractorDetail.jsx` (tabs), `DocumentUpload.jsx`; `components/contractors/ContractorSelect.jsx`
  in the violation / corrective-action forms; contractor summary card on the Government overview
- Tests: scoping (404), summary role access, score rules

### Phase 4 — Production reporting

- Migrations: `daily_production` (unique mine/date/shift) · `production_edit_log` ·
  `production_detail_request`
- `services/ProductionService.php` (submit/lock, edit with reason, summary, PHP anomaly fallback),
  `services/DetailRequestService.php`; `AccessRule::detailRequestCovers()` (§4)
- Controllers: `ProductionController` (CRUD, `submit`, `summary`, `detail`),
  `DetailRequestController` (create, respond, close)
- Frontend: `pages/minehead/production/` (entry form, target-vs-actual line, monthly cumulative
  bars, shift split, request inbox + response form); `pages/government/production/` (numeric table,
  anomaly flag, "Request detailed report" modal, status column, detail view)
- Tests: `DETAIL_REQUEST_REQUIRED` before / 200 after, locked edit 422 / edit-log row, transitions

### Phase 5 — Grievance handling

- Migrations: `grievance` (ticket from a per-year sequence, `GRV-YYYY-NNNNNN`) ·
  `grievance_action` · `rate_limit` (anonymous IP buckets)
- `services/GrievanceService.php` (SLA from config, escalation, sensitive routing, safety →
  observation candidate) and a role-aware serializer that never emits name/contact to mine_head
- Controllers: `GrievancePublicController` (no auth: `public`, `track/{ticket}`; rate limit,
  honeypot, file whitelist), `GrievanceController` (queue, assign, transition, stats)
- Frontend: `pages/public/RaiseGrievance.jsx`, `TrackGrievance.jsx` (linked from Login),
  `pages/minehead/grievances/`, government analytics card + escalated queue
- Tests: harassment invisible to mine head, identity never serialized, public endpoint works
  unauthenticated and is rate-limited, SLA breach escalates

### Phase 6 — Multilingual

- `src/i18n/locales/{hi,bn,or,te,mr}.json`, each with `"_meta": {"reviewed": false}`
- `scripts/check-locales.mjs` + `npm run i18n:check` (fails on any key missing from a locale)
- `pages/Profile.jsx` (both roles), `components/common/LanguageSwitcher.jsx`
- `utils/format.js` → `Intl.NumberFormat` / `Intl.DateTimeFormat` with `en-IN` grouping
- Every hard-coded string → `t()`, including Recharts axes, legends and tooltips; alerts and errors
  rendered from `{code, params}`; legacy text fields dropped from the API (Q7)
- Noto fonts via `@fontsource`; a layout pass for text expansion
- A `docs/` note: machine translation of user content is roadmap

### Phase 7 — Automation and ai-service

- `ai-service/` in full: `vision/`, `anomaly/` (production + sensor) and `risk/trend` moved from
  `backend/app/services/`; `POST /vision/ppe`, `/anomaly/production`, `/risk/trend`; tests moved
- `commands/JobsController.php`: `reminders`, `sla`, `escalate-alerts`, `score`, `anomaly`
- Migrations: `notification` table (brief §6: log instead of SMS/email) · score-component
  columns · a materialised view for current scores
- Extended score (weights in `.env`), same bands
- `scripts/register_tasks.ps1` (Task Scheduler) + `docs/CRON.md`

### Phase 8 — Hardening, seed, docs, cleanup

- `commands/SeedController.php` in full, fixed RNG seed: 74 mines (B3) keeping the named five's
  scores, ~15 contractors, ~60 contracts, ~600 workers, 90 days × 3 shifts with 3–4 injected
  anomalies (one just before a scheduled inspection), ~120 grievances, detail requests (one overdue)
- The full Codeception list from brief §8
- `run_all.bat` (postgres check, api, ai-service, frontend, `--sim`), `README.md`,
  `docs/ARCHITECTURE.md`, `docs/API.md`, `docs/ACCESS_CONTROL.md`, `docs/DEMO_SCRIPT.md`
- Parity sign-off against §7, then delete `backend/`

---

## 7. Parity matrix (old → new)

| Old (`/api/v1`) | New (`/v1`) | Phase |
|---|---|---|
| `POST /auth/login` | `POST /auth/login` | 1 |
| `GET /auth/me` | `GET /users/me` (+ `/auth/me` alias until the frontend switches) | 1 |
| `GET /mines`, `/mines/{id}` | same | 2 |
| — | `GET /mines/geojson` | 2 |
| `GET /dashboard` | same, same payload | 2 |
| `GET /sensors`, `/sensors/breaches`, `/sensors/live`, `/sensors/{id}/live`, `/sensors/{id}`, `/sensors/{id}/trend` | `/sensor-readings/…` equivalents, same shapes | 2 |
| — (the simulator wrote the DB) | `POST /sensor-readings/ingest` (API key) | 2 |
| `GET /inspections` | `GET /inspections/priority` (+ old path alias) | 2 |
| `GET /violations` | same | 2 |
| `GET /alerts` + ack / directives / resolve / reopen | same, plus `?since=` | 2 |
| `GET /audit` | same | 2 |
| `POST /vision/analyze` | same (Yii2 → ai-service) | 2 |
| stubs: compliance, corrective-actions | built | 2 |

---

## 8. `TODO-VERIFY` register (initial)

Nothing below will be written as fact. Each becomes a config value or a placeholder, listed in
`PROGRESS.md` until someone confirms it.

- Vocational-training certificate validity period for contract workers.
- Periodic medical examination interval for mine workers.
- Due day for the monthly wage register / EPF / ESI documents.
- Contract labour licence renewal rules (the brief's ≤30-day warning is a product setting, not law).
- Grievance SLA per category — the brief's examples (safety 48 h, wages 7 d) used as **product
  defaults**, not legal deadlines; the other categories need values.
- Area names per subsidiary (Q9); mine coordinates (Q8).
- Any statutory return or form number, should one be requested for production reporting.

---

## 9. Risks

- **Toolchain (B2).** Nothing in Phases 1–8 can be verified until PostgreSQL/PostGIS and Composer
  exist on this machine.
- **Moving target (B1).** `backend/` is still changing on `live-sprint`; the port has to rebase or
  re-check parity at every phase.
- **Scale.** This replaces ~3,500 lines of backend and adds four modules plus full i18n. Every
  phase stops for review, so problems surface early.
- **Demo regression.** The demo script depends on exact numbers (avg 83.2, 100/80/70/60/45) and the
  12 s window. Phase 2 asserts them in tests before the frontend is switched over.
- **Postgres partitioning limits** (no FK into `sensor_reading`, PK must include the partition
  key) — designed around in §4.
- **Audit chain under concurrency** — an advisory lock per transaction; `audit/verify` in the test
  run.
