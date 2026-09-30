# AI-Based Smart Governance and Compliance Monitoring System for Coal Mines

**SIH26024 — Team REGEX (Ashutosh, Yug)**

One platform for the regulator (DGMS), coal companies and mine heads: statutory compliance,
inspections and violations, contractors, production, grievances, alerts and escalations, a
tamper-evident audit trail, AI detection and prediction, an offline field app, in six languages.
Requirements: [PRD_Smart_Mine_Governance.md](PRD_Smart_Mine_Governance.md). How each part of the
problem statement is met, with screenshots, evidence and limits:
[docs/PROBLEM_STATEMENT_COVERAGE.md](docs/PROBLEM_STATEMENT_COVERAGE.md).

## Stack

| Layer | Choice |
|---|---|
| Dashboards | React 18 + Vite 7 + Recharts + Leaflet, i18next in en, hi, bn, or, te, mr (`frontend/`) |
| Field app | the same build as an installable offline PWA at `/field` (service worker, IndexedDB), served to phones by `scripts/field_server.mjs` |
| API | PHP 8.2 + Yii2, pure JSON, module `v1` (`api/`), served by XAMPP's Apache |
| Database | PostgreSQL 16 + PostGIS: partitioned sensor readings, hash-chained audit log |
| AI | `ai-service/` (FastAPI, stateless): PPE vision (YOLO11n, fine-tuned), seven anomaly detectors, a predictive model; the API has PHP twins when it is down |
| Data | `data/`: real reference data (74 coal mines, legal obligations with citations, accident statistics) and calibrated synthetic operations data |

Everything runs offline on one Windows laptop. Architecture: [docs/architecture.md](docs/architecture.md).

## Layout

```
api/            Yii2 API: config, migrations, models, services, commands (jobs, seed, demo), v1 controllers, tests
ai-service/     FastAPI: vision/, detectors/, risk/ (model.json), ml/ (weights, gitignored), samples/, tests/
frontend/       React: pages/ (government, minehead, public), field/ (offline PWA), components/, i18n/ (6 locales)
data/           reference/ (committed), generators/, schema/ (rules.yaml: legal limits and product settings), out/ (generated)
scripts/        db.bat, api_server.bat, supervisor.ps1, demo_reset.bat, predemo_check.bat, field_server.mjs,
                make_cert.mjs, register_tasks.ps1, run_simulator.py, browser_check.mjs, perf_check.mjs, setup.ps1
docs/           architecture, API.md (every endpoint), api-contract, access-control, demo-script, AI_EVALUATION,
                PERFORMANCE, SECURITY, FIELD_APP_SETUP, SETUP_WINDOWS, PROBLEM_STATEMENT_COVERAGE, screenshots/
run_all.bat     starts everything; run_field.bat builds and serves the field app for phones
```

## Getting started

From a fresh clone, on Windows: follow [docs/SETUP_WINDOWS.md](docs/SETUP_WINDOWS.md), whose first
table is the whole checklist - tools (PostgreSQL + PostGIS, XAMPP PHP, Composer, Node.js, Python),
the database, `scripts\setup.ps1`, the demo data (`data\run_data.bat setup` and `generate demo`),
then:

```bat
run_all.bat
```

It starts PostgreSQL, migrates and seeds on the first run, runs the scheduled jobs once, starts the
API (8080), the ai-service (8001), the dashboards (5173), the field server when built, and a
supervisor that restarts any of them that stops answering. `run_all.bat --sim` also replays live
sensor data; `run_all.bat --stop` stops everything cleanly.

Before a demo:

```bat
scripts\demo_reset.bat
scripts\predemo_check.bat
```

The first restores the demo board in seconds and checks it; the second prints READY or what to fix.
The demo itself: [docs/demo-script.md](docs/demo-script.md).

Tests (rebuilds the test database, then every suite):

```bat
cd api && run_tests.bat
```

**Online version** (Vercel + Render + Supabase, free plans): [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).
The laptop setup above is unchanged and stays the main demo.

## Demo accounts

Loaded by `yii seed`, on the laptop. Demo fixtures only - not real credentials. The online version
uses other passwords, never published (docs/DEPLOYMENT.md, "Sign-ins"). Mine names are real (Global Energy
Monitor, Global Coal Mine Tracker, August 2026, CC BY 4.0); every score is a demo value computed from
synthetic data.

| Role | Email | Password | Scope |
|---|---|---|---|
| Government (DGMS) | `gov@dgms.gov.in` | `demo123` | All 74 mines |
| Corporate | `corporate.secl@coalmine.in` (one per company) | `demo123` | SECL's 17 mines |
| Inspector | `inspector.07@dgms.example` (01-08) | `demo123` | All mines; field app |
| Mine Head | `head.jh-dhn-01@coalmine.in` | `demo123` | Moonidih, BCCL (100, low) |
| Mine Head | `head.mp-sgr-02@coalmine.in` | `demo123` | Jayant, NCL (80, low) |
| Mine Head | `head.cg-krb-03@coalmine.in` | `demo123` | Gevra, SECL (70, medium) |
| Mine Head | `head.wb-rng-04@coalmine.in` | `demo123` | Sonepur Bazari, ECL (60, medium) |
| Mine Head | `head.od-tlc-05@coalmine.in` | `demo123` | Bhubaneswari, MCL (45, high) |

Every mine has a head account: `head.<code in lower case>@coalmine.in`. National average 83.2,
6 high / 21 medium / 47 low - asserted by `api/tests/api/DemoScoreCest.php` and `yii demo/check`.

## Data

`data\run_data.bat all` runs every stage (setup, download, clean, generate, validate); the demo needs
only `setup` and `generate demo`. What is real and what is synthetic: `data/DATASETS.md` ("Data
provenance for judges"); sources: `data/SOURCES.md`; how `yii seed` loads it: `data/HANDOFF.md`.
Legal limits and deadlines cite the OSH Code 2020, the OSH (Central) Rules 2026 and the Coal Mines
Regulations 2017 (`data/reference/obligations.csv`); anything not yet verified is marked TODO-VERIFY.

## Licence note: Ultralytics YOLO (AGPL-3.0)

PPE detection uses Ultralytics YOLO, licensed AGPL-3.0; Ultralytics treats models trained with it
as AGPL-3.0 too. **This repository is public and open-source for SIH, which satisfies AGPL-3.0 for
that use** (owner decision, 2026-09-27; details and the weights question in
`docs/AI_EVALUATION.md`). **A closed deployment would need a licence review first** - an Ultralytics
Enterprise License, or a permissively licensed detector behind the same `ai-service/` boundary.
