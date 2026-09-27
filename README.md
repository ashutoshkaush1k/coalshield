# AI-Based Smart Governance and Compliance Monitoring System for Coal Mines

**SIH26024 — Team REGEX (Ashutosh, Yug)** · Round 3 Prototype

Requirements live in [PRD_Smart_Mine_Governance.md](PRD_Smart_Mine_Governance.md). This README covers the
repository layout and how to run it.

## Stack

| Layer | Choice |
|---|---|
| Frontend | React 18 + Vite + Recharts, i18next (`frontend/`) |
| API | PHP 8.2 + Yii2 pure JSON API, module `v1` (`api/`) |
| Database | PostgreSQL 16 + PostGIS (monthly-partitioned sensor readings, hash-chained audit log) |
| AI | `ai-service/` (FastAPI, stateless): pretrained YOLO PPE detection |
| IoT | Calibrated demo data replayed through the API by a simulator - no hardware |

The FastAPI prototype in `backend/` is kept until Phase 8 confirms parity but is no longer started
(fallback: `docs/SETUP_WINDOWS.md` section 9). Everything still runs offline at the venue.

## Layout

```
SIH/
├── backend/
│   ├── app/
│   │   ├── main.py            FastAPI app factory
│   │   ├── core/              config, security, roles, logging
│   │   ├── db/                session, base, init, seed
│   │   ├── models/            SQLAlchemy tables
│   │   ├── schemas/           Pydantic request/response contracts
│   │   ├── api/
│   │   │   ├── deps.py        auth + mine-scope guards  ← access control choke point
│   │   │   └── v1/endpoints/  auth, mines, dashboard, sensors, vision,
│   │   │                      violations, alerts, compliance, inspections,
│   │   │                      audit, corrective_actions
│   │   ├── services/          business logic, framework-free
│   │   │   ├── vision/        YOLO detector, PPE rules, video pipeline, annotate
│   │   │   ├── iot/           generator, simulator, thresholds
│   │   │   ├── compliance/    scoring, weights, risk bands
│   │   │   ├── alerts/        engine, dispatcher
│   │   │   ├── risk/          trend-based predictive indicator
│   │   │   ├── audit/         audit-trail recorder
│   │   │   └── access/        mine_id scoping rules
│   │   ├── realtime/          websocket push for live alerts
│   │   └── utils/
│   ├── ml/weights/            pretrained YOLO .pt (gitignored)
│   ├── data/seed/             mines, users, 150–200 sensor readings
│   ├── data/samples/          demo images and video
│   ├── storage/annotated/     CV output frames served to the dashboard
│   └── tests/
├── frontend/src/
│   ├── api/                   one module per backend resource
│   ├── auth/                  AuthContext, ProtectedRoute, role constants
│   ├── routes/                role-based route table
│   ├── pages/
│   │   ├── Login.jsx          both roles; the account decides the scope
│   │   ├── government/        Overview (grid), InspectionPriority, MineDetail
│   │   └── minehead/          Dashboard (own mine), AccessScopeCard
│   ├── components/            layout, common, charts, compliance, alerts,
│   │                          inspections, vision (upload + detection preview)
│   ├── hooks/                 useAuth, usePolling
│   ├── utils/  styles/
├── api/                       Yii2 API: config, migrations, models, services, v1 controllers, tests
├── ai-service/                stateless PPE vision service (port 8001)
├── data/                      reference data and demo-data generators (data/out is loaded by yii seed)
├── run_all.bat                one-click launcher: PostgreSQL, API, AI, frontend (--sim, --stop)
├── docs/                      architecture, API contract, access control, demo script
└── scripts/                   setup, run, seed, sensor-data generation
```

## PRD traceability

Every must-have and should-have maps to a home:

| PRD | Requirement | Where it lives |
|---|---|---|
| 4.1 | CV module, pretrained PPE detection | `backend/app/services/vision/`, `backend/ml/weights/`, `POST /vision` |
| 4.2 | IoT simulation, 150–200 readings, thresholds | `backend/app/services/iot/`, `backend/data/seed/sensor_readings.csv` |
| 4.3 | Compliance scoring engine | `backend/app/services/compliance/` |
| 4.4 | Two role-based dashboards | `frontend/src/pages/government/` (Overview, InspectionPriority, MineDetail), `frontend/src/pages/minehead/` (Dashboard, AccessScopeCard) |
| 4.5 | Alerts on violation or breach | `backend/app/services/alerts/`, `backend/app/realtime/ws.py` |
| 4 (6) | Digital audit trail | `backend/app/services/audit/`, `models/audit_log.py` |
| 4 (7) | Predictive risk indicator | `backend/app/services/risk/trend.py` |
| 4.1 | Inspection prioritisation | `backend/app/services/risk/prioritisation.py`, `GET /inspections` |
| 4.1 | Government: grid, drill-down, inspection priority | `pages/government/*` + `endpoints/inspections.py` |
| 4.1 | Mine Head: own score, violations, trends, corrective actions | `pages/minehead/*` + `endpoints/corrective_actions.py` |
| 4.2 | Server-side access control | `backend/app/api/deps.py`, `services/access/scope.py`, `tests/test_access_control.py` |
| 6.1 | Scoring formula and risk bands | `services/compliance/scoring.py`, `risk.py`, `weights.py` |

## Running it

One-time setup per machine: `docs/SETUP_WINDOWS.md` (PostgreSQL + PostGIS, PHP extensions,
Composer, `api\.env`), then `cd frontend && npm install` and
`powershell -ExecutionPolicy Bypass -File scripts/setup.ps1` for the Python venv the ai-service uses.

Then double-click **`run_all.bat`**, or:

```bat
run_all.bat
```

It starts PostgreSQL if needed (and says clearly if it cannot), migrates and seeds on the first
run, serves the API through XAMPP's Apache on 8080 (`scripts\api_server.bat`, with OPcache and
persistent database connections - `docs/PERFORMANCE.md`), opens `SIH-AI` (8001) and
`SIH-Frontend` (5173), waits for the ports and opens the dashboard. `--sim` adds the live sensor
replay; `run_all.bat --stop` shuts everything down cleanly. API health:
`http://127.0.0.1:8080/v1/health`.

Before a demo: `api\yii.bat seed demo` (about 50 s) - see `docs/demo-script.md`. Tests:
`api\run_tests.bat` (rebuilds the test database, seeds demo, runs every suite). PPE model
weights (gitignored): `backend\.venv\Scripts\python.exe scripts\build_ppe_model.py` - see
`docs/AI_EVALUATION.md`.

## Demo accounts

Loaded by `yii seed`. Demo fixtures only - not real credentials. Mine names are real (Global
Energy Monitor, Global Coal Mine Tracker, August 2026, CC BY 4.0); every score is a demo value
computed from synthetic data.

| Role | Email | Password | Scope |
|---|---|---|---|
| Government | `gov@dgms.gov.in` | `demo123` | All 74 mines |
| Corporate | `corporate.secl@coalmine.in` (one per company) | `demo123` | SECL's 17 mines |
| Mine Head | `head.jh-dhn-01@coalmine.in` | `demo123` | Moonidih, BCCL (100, low) |
| Mine Head | `head.mp-sgr-02@coalmine.in` | `demo123` | Jayant, NCL (80, low) |
| Mine Head | `head.cg-krb-03@coalmine.in` | `demo123` | Gevra, SECL (70, medium) |
| Mine Head | `head.wb-rng-04@coalmine.in` | `demo123` | Sonepur Bazari, ECL (60, medium) |
| Mine Head | `head.od-tlc-05@coalmine.in` | `demo123` | Bhubaneswari, MCL (45, high) |
| Inspector | `inspector.01@dgms.example` | `demo123` | All mines (reads like government) |

Every mine has a head account: `head.<code in lower case>@coalmine.in`. National average 83.2,
6 high / 21 medium / 47 low - asserted by `api/tests/api/DemoScoreCest.php`.

## Data

`data/` builds the project's datasets: real reference data (74 real coal mines, company
production, DGMS accident statistics, CPCB air quality, cited legal duties) and calibrated demo
operations data. Windows, from the repo root:

```bat
data\run_data.bat all
```

That runs `setup`, `download`, `clean`, `generate` and `validate` in order; each can also run on
its own, e.g. `data\run_data.bat generate demo`. Some sources need a browser or a free key first;
see `data\MANUAL_STEPS.md`.

- **Reference data:** `data/reference/`, committed. Where it comes from: `data/SOURCES.md`.
- **Generated data:** `data/out/<preset>/*.csv`, gitignored. Presets: `small` (5 mines, 14 days),
  `demo` (74 mines, 90 days, the default) and `full` (74 mines, 365 days). Each folder also has
  `_manifest.json`, `_checks.json`, `_validation.json` and the scenario ground truth.
- **Loading into the backend:** `yii seed` loads `data/out/<preset>/*.csv` with PostgreSQL `COPY`
  in foreign-key order. The order and the columns are in `data/HANDOFF.md`; the loader belongs to
  the backend (`api/`).
- **What is real and what is synthetic:** `data/DATASETS.md`, starting with "Data provenance for
  judges".

## Notes

- Copy `api/.env.example` → `api/.env` (secrets: `docs/SETUP_WINDOWS.md` section 8) and
  `frontend/.env.example` → `frontend/.env`.
- Scoring weights and the breach window are env-driven (`api/.env`); legal sensor limits come
  from `data/schema/rules.yaml`, each tied to a verified obligation.
- Model weights are downloaded, never committed - see `backend/ml/README.md`.
- API contract: `docs/api-contract.md`; changes from the prototype: `docs/API_CHANGES.md`.
