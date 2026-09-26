CoalShield — Backend Rebuild & Feature Expansion
Task brief for Claude Code
0. How to work on this task (read first)

1. Explore before changing anything. Read `README.md`, `PRD_Smart_Mine_Governance.md`, `COORDINATION.md`, everything in `docs/`, the whole `backend/` tree, `scripts/`, and `frontend/src/api/`, `frontend/src/auth/`, `frontend/src/routes/`, `frontend/src/pages/`.
2. Write `PLAN.md` and stop. It must contain: what exists today (models, endpoints, seed data, the 5 demo mines and accounts, scoring weights, alert logic, the API shapes the frontend consumes), gaps against this brief, a file-by-file plan per phase, and open questions. Wait for my approval before Phase 1.
3. Work one phase at a time. At the end of every phase:
   * run migrations from scratch + seed + all tests,
   * update `PROGRESS.md` (done / pending / known issues / how to verify),
   * commit with message `phase N: <summary>`,
   * stop and give me a short summary plus the exact commands to verify.
4. Branch: create `feat/governance-backend` from `live-sprint`. Never commit to `live-sprint` directly.
5. Do not delete the existing FastAPI backend until Phase 8 confirms parity. Its CV/IoT/risk code moves into `ai-service/` (Phase 7).
6. Never invent regulatory facts. Where a real legal clause, form number or deadline is needed and you are not certain, insert a clearly marked placeholder (`TODO-VERIFY:`) and list it in `PROGRESS.md`.
7. If something in this brief conflicts with what you find in the repo, ask instead of guessing.
8. The team develops on Windows (PowerShell, `run_all.bat`). All scripts must work there; Docker is the portable fallback.

1. Context
Problem statement (SIH26024): a centralized AI-enabled governance and compliance monitoring platform for Indian coal mines covering statutory compliance, inspections/observations/violations/corrective actions, contractor management, production reporting, worker/labour compliance, grievance handling, automated alerts/reminders/escalations, audit trails, dashboards for mine officials, corporate management and regulators, multilingual access, and scalability across subsidiaries.
Current state: React 18 + Vite + Recharts frontend; FastAPI + SQLAlchemy + SQLite backend; pretrained YOLO PPE detection; simulated IoT sensor feed; rule-based compliance score with risk bands; two roles (Government, Mine Head) with server-side mine scoping; alerts over websocket; basic audit log; 5 seeded mines.
Goal: replace the thin backend with a solid governance backend, and add four features: contractor management, production reporting, grievance handling, multilingual support.
2. Target architecture

```
frontend/      React (existing) — talks only to the Yii2 API
api/           Yii2 REST API  — core governance backend (NEW)
ai-service/    Python FastAPI — stateless AI only: PPE vision, anomaly, risk trend (MOVED from backend/)
docker-compose.yml  postgres+postgis, api, ai-service, frontend

```

* Database: PostgreSQL 16 + PostGIS. SQLite is removed.
* API: PHP 8.2+, Yii2 (`yii2-app-basic` restructured as a pure JSON API with module `v1`), Composer.
* Packages: `yiisoft/yii2-queue` (DB driver, no Redis), `yiisoft/yii2-httpclient`, a maintained JWT package (e.g. `bizley/jwt`), Codeception (bundled) for tests. Justify any other dependency in `PLAN.md`.
* ai-service never touches users, permissions or the database. Yii2 calls it over HTTP with a timeout; if it is down, Yii2 degrades gracefully (logs, returns partial data, raises a system alert) — the dashboard must never crash.
* The sensor simulator posts readings to `POST /v1/sensor-readings/ingest` (API-key auth), not to the database.

3. Non-negotiable rules (apply to every phase)

1. Schema changes only via Yii2 migrations. One migration per logical change, all reversible (`safeDown`). No manual SQL outside migrations.
2. Mine scoping in exactly one place: a base `ScopedActiveQuery::forCurrentUser()`:
   * `government` → all mines
   * `corporate` → mines in the user's subsidiary
   * `mine_head` → own `mine_id` only Every scoped model uses it. Out-of-scope record access returns 404 (not 403) so existence is not leaked.
3. RBAC via `yii\rbac\DbManager`, permissions per action (e.g. `contractor.create`, `production.requestDetail`). Controllers check permissions; never rely on the frontend hiding things.
4. Audit everything. An `AuditBehavior` attached to every model writes `audit_log` rows on insert/update/delete: entity, entity_id, action, old_values/new_values (JSONB), user_id, ip, timestamp, `prev_hash`, `row_hash` (SHA-256 chain → tamper-evident). Provide `yii audit/verify` to validate the chain.
5. Workflows via one `StatusTransition` component: each model declares allowed transitions; invalid transitions → 422; every transition logged to the entity's history table and the audit log.
6. Business logic in service classes (`ContractorService`, `ProductionService`, `GrievanceService`, `ComplianceScoreService`, `AlertService`, `EscalationService`). Controllers stay thin; console jobs reuse the same services.
7. No display text from the API. Alerts, validation errors, notifications and audit summaries carry `{code, params}`; the frontend translates. Error format everywhere: `{"error": {"code": "VALIDATION_FAILED", "fields": {"coal_actual_t": ["REQUIRED"]}}}`
8. API conventions: `/v1/...`, JSON only, pagination (`page`, `per_page`, `X-Total-Count`), filtering (`filter[status]=open`), sorting (`sort=-created_at`), ISO-8601 timestamps in UTC, `snake_case` fields.
9. Locked records: submitted production entries and closed inspections are locked; editing requires a `reason`, stored in an edit log.
10. Indexes: composite indexes on (`mine_id`, `date`), (`mine_id`, `status`) and every FK. Partition high-volume time series (`sensor_reading`) by month.
11. Secrets only in `.env` (provide `.env.example`). Demo passwords stay demo-only.
12. Keep the frontend working. Where possible preserve the response shapes the current React code consumes; list every contract change in `docs/API_CHANGES.md`.

4. Phases
Phase 1 — Foundation

* Scaffold `api/` (Yii2 basic → API: JSON responses, CORS for the Vite dev origin, `v1` module, base `ApiController` with error format, pagination, filtering).
* `docker-compose.yml` with `postgis/postgis:16`; also document a native Windows setup.
* Migrations:
   * enable extensions: `postgis`, `pg_trgm`, `pgcrypto`
   * `subsidiary`, `area`, `mine` (code, name, type opencast/underground, `location geometry(Point,4326)`, `boundary geometry(Polygon,4326)` nullable, status)
   * `user` (email, password_hash, role, `subsidiary_id`, `area_id`, `mine_id`, `preferred_language` default `en`, status, timestamps)
   * RBAC tables, `audit_log`, `queue`, `file` (path, mime, size, sha256, uploaded_by, entity, entity_id)
* Roles: `government`, `corporate`, `mine_head` (+ `inspector` defined, unused for now).
* JWT login `POST /v1/auth/login`, `GET /v1/users/me`, `PATCH /v1/users/me` (language only for now).
* `ScopedActiveQuery`, `AuditBehavior`, `StatusTransition`, `FileStorage` service (local disk, checksum, size/type whitelist).
* Seed: map the existing 5 mines into real subsidiaries (Jharia → BCCL, Korba → SECL, Talcher → MCL; place the other two appropriately), keep existing demo accounts and add `corporate.secl@coalmine.in` / `demo123`.
* Frontend: install `react-i18next` + `i18next`, create `src/i18n/` with `en.json` only, wrap the app, add a `t()` helper — so all new UI from Phase 2 onward uses translation keys.
* Tests: login, `/users/me`, scoping for each role, audit chain verification.

Phase 2 — Port existing modules to Yii2
Port with parity: mines (list, detail, GeoJSON), sensor readings (ingest + history, monthly partitions), violations, corrective actions, inspections, alerts, audit trail endpoints, compliance score & risk bands, inspection prioritisation.

* Alerts table: `code`, `params` JSONB, severity, `mine_id`, entity ref, status (open/acknowledged/resolved), `ack_by`, `escalation_level`.
* Realtime: keep it simple — frontend polls `GET /v1/alerts?since=` (the existing `usePolling` hook). Websocket is optional later.
* Scoring weights stay env-driven.
* Switch the frontend API base URL to `VITE_API_URL`; verify both existing dashboards work end-to-end.

Phase 3 — Contractor management (mine head side + government read-only summary)
Tables:

* `contractor`: name, registration_no, labour_licence_no, licence_valid_to, epf_code, esi_code, contact, status (active / suspended / blacklisted). Global (not per mine).
* `contract`: contractor_id, mine_id, work_type (ob_removal, transport, loading, civil, security, other), work_order_no, value, start_date, end_date, max_workers.
* `contract_worker`: contract_id, name, worker_code, vt_cert_valid_to, medical_exam_date, active.
* `contractor_compliance_doc`: contract_id, doc_type (wage_register, epf_challan, esi_challan, insurance, other), period (YYYY-MM), file_id, verified, verified_by.
* Add nullable `contractor_id` to `violation` and `corrective_action`.

Behaviour:

* Mine head: full CRUD scoped via `contract.mine_id`; document upload; link violations to contractors.
* Government & corporate: `GET /v1/contractors/summary` (count, compliance %, flagged/blacklisted per mine) — read-only.
* `ContractorService::score()`: violations per worker, overdue docs, expired licence, workers over `max_workers`.
* Alert codes: `CONTRACTOR_LICENCE_EXPIRING` (≤30 days), `WORKER_VT_EXPIRED`, `WORKER_MEDICAL_EXPIRED`, `CONTRACTOR_DOC_MISSING` (monthly docs not uploaded by due day — due day configurable), `CONTRACT_WORKER_CAP_EXCEEDED`.

Frontend (`pages/minehead/`): contractors list with compliance badge; contractor detail (tabs: contracts, workers, documents, linked violations); upload form; contractor selector in violation/corrective-action forms. Government overview: contractor summary card.
Phase 4 — Production reporting
Tables:

* `daily_production`: mine_id, date, shift (A/B/C), coal_target_t, coal_actual_t, ob_target_m3, ob_actual_m3, dispatch_t, closing_stock_t, breakdown_hours, manpower_present, remarks, status (draft/submitted/locked), submitted_by, submitted_at. Unique (mine_id, date, shift).
* `production_edit_log`: production_id, field, old_value, new_value, reason, edited_by, edited_at.
* `production_detail_request`: mine_id, requested_by, date_from, date_to, reason, due_at, status (pending/submitted/overdue/escalated/closed), response_note, response_file_id, responded_by, responded_at.

Access rules (this is a new rule type — implement it in the scoping/permission layer, not ad hoc):

* Mine head: create/edit own drafts, submit (locks), edit locked only with reason; full detail of own mine.
* Government/corporate: `GET /v1/production/summary` — per mine: today and month-to-date target, actual, achievement %, anomaly flag. Numbers only.
* Government/corporate: `GET /v1/production/detail?mine_id=&from=&to=` — allowed only for date ranges covered by a request with status `submitted`/`closed`; otherwise 403 with code `DETAIL_REQUEST_REQUIRED`.
* Framing: this is a regulator's "Call for Detailed Report" with a deadline, not a permission request. Overdue requests auto-escalate.

Anomaly: `ProductionService` flags days where actual > target by a configurable % or deviates strongly from the mine's 30-day rolling mean (ai-service `/anomaly/production` in Phase 7; implement a PHP fallback now).
Frontend:

* Mine head: daily entry form; Recharts — target vs actual line, monthly cumulative bars, shift split; inbox of detail requests with response form.
* Government: numeric table across mines with anomaly flag and a "Request detailed report" button per row → modal (date range, reason, due date) → status column → detail view (charts + response note) once fulfilled.

Phase 5 — Grievance handling
Tables:

* `grievance`: ticket_no (`GRV-YYYY-NNNNNN`), mine_id, submitter_type (employee, contract_worker, community, anonymous), name/contact nullable, is_anonymous, category (wages, safety, working_conditions, harassment, environment, land_compensation, other), severity, language, description, file_id nullable, location geometry nullable, status (received/acknowledged/under_investigation/resolved/closed/reopened), assigned_to, sla_due_at, escalation_level, against_mine_head bool, resolution_note, satisfaction_rating.
* `grievance_action`: grievance_id, action, from_status, to_status, note, actor_id, created_at.

Endpoints:

* Public, unauthenticated: `POST /v1/grievances/public`, `GET /v1/grievances/track/{ticket_no}` (status + public timeline only). Rate limit by IP, file type/size whitelist, basic honeypot field.
* Mine head: list/assign/transition for own mine.
* Government: all grievances, stats by category/mine, avg resolution time, SLA breaches, escalated queue.

Rules:

* SLA per category (config): e.g. safety 48h, wages 7d. Breach → escalate one level + alert `GRIEVANCE_SLA_BREACHED`.
* Sensitive routing: `harassment` or `against_mine_head = true` → routed to government, complainant identity hidden from the mine head (serialize without name/contact for that role), mine head cannot view the record at all.
* `safety` category → automatically creates a linked observation/violation candidate in the inspection flow.

Frontend: public "Raise a grievance" page and "Track grievance" page (linked from Login); mine head grievance queue with timeline; government analytics card + escalated queue.
Phase 6 — Multilingual support

* Languages: `en`, `hi`, `bn`, `or`, `te`, `mr`.
* Profile page (new, both roles): name, role, subsidiary/area/mine, language selector → `PATCH /v1/users/me` → switches immediately and persists.
* Login page, public grievance and tracking pages: small language switcher (stored in the browser until login); after login the saved `preferred_language` wins.
* Replace every hard-coded UI string, including Recharts axis labels, legends and tooltips, with `t()` keys. Add a script that fails if a key exists in `en.json` but is missing in another locale.
* Render alert/error `{code, params}` through translation keys.
* Numbers and dates via `Intl.NumberFormat('en-IN' / locale)` and `Intl.DateTimeFormat` (Indian digit grouping).
* Load Noto Sans Devanagari / Bengali / Oriya / Telugu with fallbacks; check layouts for text expansion.
* Non-English translations may be machine-generated first; mark each locale file `"_meta": {"reviewed": false}`.
* User-entered content (grievance text) is not translated; note machine translation as roadmap in `docs/`.

Phase 7 — Automation & AI service

* Move `backend/app/services/vision`, IoT generator/simulator and risk trend code into `ai-service/` as stateless endpoints: `POST /vision/ppe` (image/video → detections + annotated frame), `POST /anomaly/production`, `POST /risk/trend`. Keep the pretrained YOLO weights gitignored as today.
* Yii2 `AiClient` component (httpclient, timeout, retries, graceful fallback).
* Console jobs (Yii2 commands, reusing services):
   * `yii jobs/reminders` — licence/doc/VT/medical expiries, pending production entries
   * `yii jobs/sla` — grievance and detail-request deadlines → escalations
   * `yii jobs/escalate-alerts` — unacknowledged alerts after N hours → next level (mine head → area/corporate → government)
   * `yii jobs/score` — recompute compliance scores, refresh materialized views
   * `yii jobs/anomaly` — production & sensor anomaly detection Provide `scripts/register_tasks.ps1` (Windows Task Scheduler) and a cron example.
* Extended score (weights in `.env`): `score = 100 − (ppe + environmental + overdue_contractor_docs + grievances_past_sla + aging_open_corrective_actions) × repeat_violation_multiplier`, clamped 0–100, same risk bands as today.

Phase 8 — Hardening, seed, docs

* `yii seed` with fixed RNG seed: 5 mines across all three risk bands (preserve current demo scores as closely as possible), ~15 contractors, ~60 contracts, ~600 workers (some expiring certs), 90 days × 3 shifts of production with 3–4 injected anomalies (incl. a spike just before a scheduled inspection), ~120 grievances across categories with some SLA breaches and one sensitive case, open detail requests (one overdue).
* Codeception API tests — at minimum:
   * mine head cannot read another mine's contractors, production, grievances, alerts (expect 404)
   * corporate sees only its subsidiary's mines
   * government gets `DETAIL_REQUEST_REQUIRED` before a request is fulfilled and 200 after
   * harassment grievance invisible to mine head; complainant identity never serialized to mine head
   * editing locked production without reason → 422; with reason → edit log row
   * invalid status transitions → 422
   * public grievance endpoint works without auth and is rate-limited
   * `yii audit/verify` passes after the seed + test run
* Update `run_all.bat` (starts postgres check, api, ai-service, frontend; `--sim` starts the simulator), `README.md`, `docs/ARCHITECTURE.md`, `docs/API.md` (all endpoints), `docs/ACCESS_CONTROL.md`, `docs/DEMO_SCRIPT.md` (updated demo flow covering the four new features).
* Only after all of the above pass: remove the old `backend/` (everything useful now lives in `api/` or `ai-service/`).

5. Definition of done

* Fresh clone → documented setup → `run_all.bat` → working demo, offline, on Windows.
* `yii migrate/fresh && yii seed` succeeds; `yii migrate/down all` succeeds.
* All tests green; `yii audit/verify` green; locale key check green.
* Both original dashboards work, plus corporate role, contractors, production (with detail-request flow), grievances (public + internal), profile page with 6 languages.
* `PROGRESS.md` lists every `TODO-VERIFY` placeholder and known limitation.

6. Out of scope (do not build now)
Real IoT hardware, native mobile app (a PWA offline inspection app is a later phase), OCR, blockchain, SMS/email gateways (log notifications to a `notification` table instead), production-grade secrets management, machine translation of user content.

7. Legal update (verified in D1)
Added 2026-09-25 by the dataset track (stage D1). Every status below was read from the official notification itself, not from commentary; the evidence, file links and page references are in `data/sources.yaml` (source S10) and `data/SOURCES.md`. This section supersedes any assumption elsewhere in this brief that the Mines Act, 1952 or the Contract Labour Act, 1970 is the governing law.

Status as of 2026-09-25:

* All four labour codes came into force on 21 November 2025 (Gazette of India Extraordinary, 21.11.2025):
   * Occupational Safety, Health and Working Conditions Code, 2020 — all provisions, S.O. 5321(E).
   * Industrial Relations Code, 2020 — all provisions, S.O. 5320(E).
   * Code on Social Security, 2020 — S.O. 5319(E), as corrected by S.O. 5936(E) dated 19.12.2025; some provisions commenced earlier under S.O. 2060(E) dated 03.05.2023.
   * Code on Wages, 2019 — S.O. 5322(E), with a few sub-provisions left out of the schedule.
* Mines Act, 1952 and Contract Labour (Regulation and Abolition) Act, 1970 — repealed from 21.11.2025 by section 143(1)(c) and (h) of the OSH Code. Section 143(3) saves rules, regulations and notifications made under them "to the extent they are not contrary to the provisions of this Code till they are repealed by the Central Government".
* Occupational Safety, Health and Working Conditions (Central) Rules, 2026 — in force from 08.05.2026, G.S.R. 345(E) dated 08.05.2026 (rule 1(3): in force on publication). In the same notification they supersede, among others, the Mines Rules, 1955, the Mines Vocational Training Rules, 1966 and the Contract Labour (Regulation and Abolition) Central Rules, 1971.
* Coal Mines Regulations, 2017 — still in force under OSH Code section 143(3). A replacement, the OSH&WC (Coal Mines) Regulations, 2026, has been published only as a draft (DGMS, 31.01.2026); no final notification was found.
* Coal Mines Provident Fund and Miscellaneous Provisions Act, 1948 — in force; it is not among the Acts repealed by the Code on Social Security.
* Employees' Provident Funds and Miscellaneous Provisions Act, 1952 — TODO-VERIFY. Its status depends on serial (vi) of S.O. 2060(E) dated 03.05.2023, which could not be retrieved from an official source. Do not treat the EPF Act as either repealed or in force until this is confirmed.
* Environment (Protection) Act, 1986; Air Act, 1981; Water Act, 1974 — in force; not affected by the labour codes.

Rule for every phase from now on:

* Contractor licences, contract labour obligations, worker vocational training, medical examinations, and all other statutory obligations must cite the OSH Code, 2020 and the OSH (Central) Rules, 2026 — plus the Coal Mines Regulations, 2017 where they are still in force — and never the repealed Mines Act, 1952, Contract Labour (Regulation and Abolition) Act, 1970 or Mines Rules, 1955.
* This applies to seed data, alert codes, reminders, UI help text, obligation records and documentation alike.
* The specific rule numbers, validity periods and deadlines are not stated here on purpose. They are extracted with clause and page citations into `data/reference/obligations.csv` in dataset stage D3; until a value has a citation there it stays a `TODO-VERIFY` placeholder, as rule 6 of section 0 already requires.
