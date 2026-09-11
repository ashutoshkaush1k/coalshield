# AI-Based Smart Governance and Compliance Monitoring System for Coal Mines

**SIH26024 — Team REGEX (Ashutosh, Yug)** · Round 3 Prototype

Requirements live in [PRD_Smart_Mine_Governance.md](PRD_Smart_Mine_Governance.md). This README covers the
repository layout and how to run it.

## Stack

| Layer | Choice |
|---|---|
| Frontend | React 18 + Vite + Recharts |
| Backend | Python + FastAPI |
| Database | SQLite via SQLAlchemy (one-line swap to PostgreSQL) |
| Computer Vision | Pretrained YOLO (ultralytics) + OpenCV |
| IoT | Seeded dataset replayed by a simulator — no hardware |

SQLite and a local frontend mean the whole demo runs offline at the venue.

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
├── run_all.bat                one-click stack launcher (--sim adds the sensor feed)
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

One-time setup per machine:

```bash
powershell -ExecutionPolicy Bypass -File scripts/setup.ps1
```

Then start everything with a single double-click on **`run_all.bat`**, or:

```bash
run_all.bat
```

It checks the venv and `node_modules` are present, opens `SIH-Backend` and `SIH-Frontend`
windows, waits until both ports are actually listening, and opens the dashboard. Add `--sim` to
also start the IoT simulator. Close the `SIH-*` windows to shut the stack down.

To start the pieces individually instead:

```bash
powershell -File scripts/run_backend.ps1
```

```bash
powershell -File scripts/run_frontend.ps1
```

Backend on `http://localhost:8000` (docs at `/docs`), frontend on `http://localhost:5173`.

## Demo accounts

Seeded by `scripts/seed_db.py`. Demo fixtures only — not real credentials.

| Role | Email | Password | Scope |
|---|---|---|---|
| Government | `gov@dgms.gov.in` | `demo123` | All 5 mines |
| Mine Head | `head.jh-dhn-01@coalmine.in` | `demo123` | Jharia (88, green) |
| Mine Head | `head.cg-krb-03@coalmine.in` | `demo123` | Korba (70, yellow) |
| Mine Head | `head.od-tlc-05@coalmine.in` | `demo123` | Talcher (46, red) |

The seed spreads five mines across all three risk bands so the cross-mine comparison has
something to compare. Regenerate with `python scripts/generate_sensor_data.py` — it is seeded
with a fixed RNG value, so the numbers are identical on every machine and every re-run.

## Notes

- Copy `backend/.env.example` → `backend/.env` and `frontend/.env.example` → `frontend/.env`.
- Scoring weights and sensor thresholds are env-driven so they can be tuned live (PRD 8.1).
- Model weights are downloaded, never committed — see `backend/ml/README.md`.
