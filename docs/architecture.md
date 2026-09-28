# Architecture

## The pieces

```
 Browsers (dashboards)            Phones (field app, /field)
   React + Vite :5173               installable PWA, offline queue in IndexedDB
        |                                     |
        |  HTTP /v1 (JSON)                    |  HTTPS :5443 (LAN) or http://localhost:5180 (USB)
        |                          scripts/field_server.mjs - serves frontend/dist, proxies /v1
        v                                     v
 Yii2 API  (api/, Apache + mod_php on 127.0.0.1:8080)  <----  scripts/run_simulator.py (API key,
   scoping, RBAC, audit chain, workflows, scores,              POST /v1/sensor-readings/ingest)
   alerts, jobs (yii jobs/*), demo check
        |                    \
        |  SQL                \  HTTP, short timeouts, PHP fallback when down
        v                      v
 PostgreSQL 16 + PostGIS    ai-service (ai-service/, FastAPI :8001, stateless)
   partitioned sensor data     PPE vision (YOLO), 7 anomaly detectors, predictive model
   hash-chained audit log

 scripts/supervisor.ps1 (window SIH-Supervisor): every 30 s checks the API, the ai-service, the
 frontend and the field server, restarts one that stops answering, logs it.
 Windows Task Scheduler (scripts/register_tasks.ps1): yii jobs/* on a clock.
 data/ (Python): builds the reference data and the demo data that `yii seed` loads.
```

| Port | What | Listens on |
|---|---|---|
| 5432 | PostgreSQL (`scripts\db.bat`) | 127.0.0.1 |
| 8080 | API (`scripts\api_server.bat`: Apache + OPcache; fallback `api\serve.bat`) | 127.0.0.1 |
| 8001 | ai-service (`ai-service\run_ai_service.bat`) | 127.0.0.1 |
| 5173 | dashboards, Vite dev server (`npm run dev`) | localhost |
| 5180 | field app over HTTP, for this PC and USB forwarding | 127.0.0.1 |
| 5443 / 5080 | field app over HTTPS for phones / the CA certificate download | LAN |

Only the field server listens on the network; it serves static files and passes `/v1` to the API.
Everything else answers on this machine only.

## The API (`api/`)

- **Thin controllers, services for logic** (`modules/v1/controllers` -> `services/`): e.g.
  `ComplianceScoreService`, `InspectionPriorityService`, `GovernanceRiskService`, `ContractorService`,
  `ProductionService`, `GrievanceService`, `ObligationService`, `AlertService`, `AnomalyService`,
  `RiskModelService`, `FieldSyncService`. Console jobs (`commands/`) call the same services.
- **One scoping rule** (`components/ScopedActiveQuery.php`): government and inspector see every mine,
  corporate its company's, a mine head its own; out of scope is 404, never an empty list. Rules that
  depend on the data (production detail only after a fulfilled "Call for Detailed Report", sensitive
  grievances hidden from the mine head) are declared once in `components/AccessRule.php`.
  See [access-control.md](access-control.md).
- **Permissions** per action through RBAC (`config/rbac.php`, `yii rbac/init`).
- **Audit** (`components/AuditBehavior.php`, `AuditChain.php`): every insert, update and delete of
  an audited model is a row in `audit_log`, SHA-256 chained; `yii audit/verify` proves the chain.
- **Workflows** through `components/StatusTransition.php`: allowed transitions per model, 422 for
  others, each transition in the status history and the audit log.
- **No display text**: errors, alerts and history carry `{code, params}`; the frontend words them in
  six languages.
- **Rules from data, not code**: legal limits and deadlines in `data/schema/rules.yaml` (`legal:`,
  each tied to a cited obligation) and product settings beside them (`product:`, labelled as such).
- Contract: [api-contract.md](api-contract.md), every endpoint: [API.md](API.md), changes from the
  prototype: [API_CHANGES.md](API_CHANGES.md).

## The ai-service (`ai-service/`)

Stateless: it never sees users, permissions or the database. The API sends the data, stores what
comes back, and keeps working when the service is down:

| Capability | Endpoint | When the service is down |
|---|---|---|
| PPE photo analysis (YOLO11n fine-tuned, `ml/weights/ppe.pt`) | `POST /vision/ppe` | the upload answers 503 `AI_SERVICE_UNAVAILABLE` and raises a low alert |
| Seven anomaly detectors | `POST /anomaly/{name}` | identical PHP twins in `api/services/detectors/` run instead |
| Predicted risk (gradient boosting on US MSHA data) | `POST /risk/predict` | the same exported trees are evaluated in PHP |

The dashboards' footer says when detection is on the PHP fallback (`GET /v1/system/status`).
Parity of the twins is tested on shared fixtures; the evaluation is in
[AI_EVALUATION.md](AI_EVALUATION.md).

## Data

- `data/` (its own Python venv) builds `data/reference/` (real, cited, committed) and
  `data/out/<preset>/` (synthetic demo operations, gitignored, deterministic by seed).
- `yii seed <preset>` loads `data/out/<preset>/*.csv` with `COPY` in foreign-key order and backfills
  the audit chain; `scripts\demo_reset.bat` restores a snapshot of "seed demo + jobs/all" in seconds.
- `sensor_reading` is partitioned by month; composite indexes on (`mine_id`, date) and
  (`mine_id`, `status`) and on every foreign key.

## Automation

`yii jobs/<name>` - reminders, SLAs, alert escalation, the daily score and risk history, the anomaly
detectors, contractor / obligation / production / grievance checks. Each is idempotent, holds an
advisory lock and is logged in `job_run`. `run_all.bat` runs them once at start; Task Scheduler runs
them on a clock (`scripts/register_tasks.ps1`, [SETUP_WINDOWS.md](SETUP_WINDOWS.md) 7a).

## The field app (Phase 7B)

Offline-first: the service worker keeps the app, IndexedDB keeps the reference data and the queue.
Each visit, finding and photo carries an id made on the phone; `POST /v1/field/sync` acts once per
id and returns the stored result on a retry, through the same services, scoping and audit as the
dashboards. See [FIELD_APP_SETUP.md](FIELD_APP_SETUP.md).

## Real-time

Dashboards poll every 10 s, one request per screen (`GET /v1/views/...`), plus the footer's status
every 30 s; every dashboard request answers in well under 150 ms ([PERFORMANCE.md](PERFORMANCE.md)).
There is no websocket: polling survives flaky venue networks and needs no extra server.

## The compliance score

`score = 100 - (open violations x WEIGHT_PPE) - (breaches in the window x WEIGHT_ENV)`, clamped to
0-100 and rounded to 0.1, bands low from 80, medium from 50, else high (ported unchanged from the prototype; weights in
`api/.env`). It is recomputed on every read, never stored as a running total, so a weight change
takes effect at once and every number can be explained.

**Violations count until they are resolved**: by a corrective action closed with proof, or - for PPE
findings from vision - by a clean re-inspection frame that shows workers with their PPE (a frame
with nobody in it is not evidence). **Breaches age out**: a breach counts only inside
`BREACH_WINDOW_HOURS` (12 s by default, six ticks of the simulator's replay, so a live demo shows
scores falling and recovering). Nothing is deleted; history, charts and the audit trail keep it all.

The Governance Risk Index (Phase 7) is a separate measure beside the score, built from overdue
obligations, contractor documents, grievances past their deadline and ageing corrective actions
([api-contract.md](api-contract.md)); it orders the inspection queue and changes nothing in the score.

### A known simplification

A mine head can clear PPE findings from vision with a compliant photograph. The evidence check stops
an empty frame, but not a photo of another place or time. The field app's findings carry time and
location, and resolution by an inspector is the way to close this gap (roadmap).
