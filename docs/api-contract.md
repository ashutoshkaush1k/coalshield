# API contract (v1, Yii2 `api/`)

Base: `http://127.0.0.1:8080/v1`. JSON only. All routes except `POST /auth/login`, `GET /health`,
the machine ingest (API key) and signed file links need `Authorization: Bearer <token>`.
Differences from the FastAPI prototype: [API_CHANGES.md](API_CHANGES.md).

## Conventions (brief rule 8)

- Lists: `page`, `per_page` (max 200; `limit` accepted as an alias), headers `X-Total-Count`,
  `X-Page`, `X-Per-Page`; `filter[attr]=value`; `sort=-attr`. Unknown filter or sort attributes
  are a 422.
- Timestamps ISO-8601 UTC with `Z`; field names snake_case.
- Errors, always: `{"error": {"code": "VALIDATION_FAILED", "params"?: {...}, "fields"?: {"due_at": ["INVALID_DATETIME"]}}}`.
  Codes, not text: the frontend translates (`frontend/src/i18n/`).
- `401 UNAUTHENTICATED` no or bad token · `403 FORBIDDEN` missing permission ·
  `404 NOT_FOUND` missing **or outside your scope** · `422` validation, `INVALID_TRANSITION`,
  `RECORD_LOCKED` · `503 AI_SERVICE_UNAVAILABLE`.

## Endpoints

"Scoped" = filtered to the caller's mines ([access-control.md](access-control.md)); a `mine_id`
outside scope is 404. Permissions are in `api/config/rbac.php`.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| POST | `/auth/login` | public | `{access_token, token_type, expires_in, user}` |
| GET | `/users/me` (alias `/auth/me`) | `user.viewOwn` | profile incl. `permissions`, `mine_name`, `subsidiary_code` |
| PATCH | `/users/me` | `user.updateOwnLanguage` | `preferred_language` only |
| GET | `/mines`, `/mines/{id}` | `mine.view` | scoped; with `compliance`, `open_alerts` (`total_readings` on detail) |
| GET | `/mines/geojson` | `mine.view` | FeatureCollection with score and band per mine |
| GET | `/dashboard?state=` | `dashboard.view` | role-aware overview: stats, worst mines, queue preview |
| GET | `/compliance/{mine_id}`, `/compliance/{mine_id}/history` | `compliance.view` | score with inputs; recorded points |
| GET | `/sensors?state=` | `sensor.viewFleet` | latest reading per sensor per mine (multi-mine roles) |
| GET | `/sensors/breaches?state=&mine_id=` | `sensor.view` | breach counts in 6-hour buckets by gas / dust / temperature |
| GET | `/sensors/{mine_id}`, `/sensors/{mine_id}/trend?points=` | `sensor.view` | readings (paged); one series per sensor type with its legal limit |
| GET | `/sensors/thresholds` | `sensor.view` | limits in use, from `data/schema/rules.yaml` |
| POST | `/sensor-readings/ingest` | API key (`X-Api-Key`) | `{readings: [{mine_id|mine_code, sensor_type, value, recorded_at?}]}` |
| GET | `/sensor-readings/baseline` | API key | simulator pre-flight (same as `/admin/baseline-check`) |
| GET | `/violations`, `/violations/{id}` | `violation.view` | scoped; `?mine_id=&resolved=`, `filter[category]` |
| GET/POST | `/corrective-actions` | `correctiveAction.view` / `.create` | list (`?overdue=1`); record for an own violation |
| GET | `/corrective-actions/{id}` | `correctiveAction.view` | with violation and history |
| POST | `/corrective-actions/{id}/resolve` | `correctiveAction.resolve` | multipart `proof_text`, `file?`; resolves the violation |
| GET | `/inspections/priority?state=&limit=` | `inspection.viewQueue` | ranked queue, reasons as `{code, params}` |
| GET/POST | `/inspections`, `/inspections/{id}` | `inspection.view` / `.manage` | records; schedule |
| PATCH | `/inspections/{id}` | `inspection.manage` | edit; a closed (locked) inspection needs `reason` → `record_edit_log` |
| POST | `/inspections/{id}/visit`, `/close` | `inspection.manage` | transitions (422 `INVALID_TRANSITION`) |
| POST | `/inspections/{id}/observations` | `inspection.manage` | record an observation (visited inspections) |
| GET | `/observations` | `inspection.view` | scoped list |
| POST | `/observations/{id}/promote`, `/dismiss` | `inspection.manage` | promote creates the violation and its alert |
| GET | `/alerts`, `/alerts/{id}` | `alert.view` | `{code, params}`; open directives first; `?status=&directives=&since=` |
| POST | `/alerts/{id}/ack` | `alert.acknowledge` | open → acknowledged |
| POST | `/alerts/directives` | `directive.create` | `{mine_id, message?, severity?, reference_id?}` |
| POST | `/alerts/{id}/resolve` | `alert.resolve` | multipart `proof_text`, `file?` |
| POST | `/alerts/{id}/reopen` | `directive.reopen` | `{reason}` - directives only |
| GET/POST | `/incidents`, `/incidents/{id}` | `incident.view` / `.create` | `?late=1`; each with `reporting_check` (48 h, obligation code) |
| PATCH | `/incidents/{id}/violation` | `incident.linkViolation` | `{related_violation_id: id|null}` (same mine) |
| GET | `/audit?mine_id=&entity=&action=&source=` | `audit.view` | scoped audit trail (hash-chained); `source` = `app`, `seed` or `seed_history` (the seeded records' own history) |
| GET | `/contractors?mine_id=&band=&status=` | `contractor.view` | contractors of the mines in scope, worst first, each with `compliance` (score, band, reasons, penalties) |
| GET | `/contractors/summary?state=` | `contractor.summary` | per mine: contractors, compliance %, flagged, blacklisted; flagged contractors worst first |
| GET | `/contractors/{id}` | `contractor.view` | detail: contracts, workers, documents, violations, alerts, status history (all in scope) |
| POST | `/contractors` | `contractor.manage` | register with its first contract at the own mine: `{name, registration_no, ..., contract: {...}}` |
| PATCH | `/contractors/{id}` | `contractor.manage` | edit registration details |
| POST | `/contractors/{id}/status` | `contractor.manage` | `{status, reason}` - active / suspended / blacklisted (422 `INVALID_TRANSITION`) |
| GET/POST | `/contracts`, PATCH/DELETE `/contracts/{id}` | `contractor.view` / `.manage` | contracts at the own mine; delete only without workers or documents (422 `IN_USE`) |
| POST | `/contracts/{id}/workers`, PATCH/DELETE `/contract-workers/{id}` | `contractor.manage` | contract workers (VT and medical dates) |
| POST | `/contracts/{id}/documents` | `contractor.manage` | multipart `doc_type`, `period` (YYYY-MM), `file`; 422 `ALREADY_UPLOADED` per contract, type and month |
| POST | `/contractor-docs/{id}/verify`, DELETE `/contractor-docs/{id}` | `contractor.manage` | verify or remove a document |
| PATCH | `/violations/{id}/contractor` | `violation.linkContractor` | `{contractor_id: id|null}`; the contractor must hold a contract at the violation's mine |
| POST | `/vision/analyze` | `vision.analyze` | multipart `mine_id`, `file` → detections, violations, score before/after |
| GET | `/files/{id}/content?expires=&signature=` | signed link | stored image (annotated frame, proof) |
| GET | `/admin/baseline-check` | `admin.baselineCheck` | live scores vs seeded baseline |
| GET | `/health` | public | `{status, database, time}` |

## Compliance score

`score = 100 - (open violations x WEIGHT_PPE + in-window breaches x WEIGHT_ENV)`, clamped 0..100,
rounded to 0.1. Bands: low >= 80, medium >= 50, else high. Breaches count only while younger than
`BREACH_WINDOW_HOURS` (12 s in the demo). Weights and window are env-driven (`api/.env`). Every
score is a demo value: `compliance.is_demo_value` is always `true`.

## Contractor score (Phase 3)

`100 - penalties`, clamped 0..100, recomputed on every read (`api/services/ContractorService.php`):
violations per active worker x 40 (max 40; all violations linked to the contractor over its
active workers), 2 per missing monthly document (wage register, EPF challan, ESI challan, due by
day 10 of the next month; max 30), 30 for an expired / 10 for an expiring licence (30 days),
up to 15 for the share of active workers with an expired VT certificate or an overdue medical,
5 per contract over its worker limit. Band: compliant >= 80, watch >= 50, flagged below or when
suspended / blacklisted. Legal bases cited from `data/schema/rules.yaml`: LAB-02 (OSH Code 2020
s.48(3)), SAF-04 (OSH (Central) Rules 2026 r.159), HLT-01 (r.109(1)). The weights and the due day
are product settings. Contractor alerts (`CONTRACTOR_LICENCE_EXPIRING`, `WORKER_VT_EXPIRED`,
`WORKER_MEDICAL_EXPIRED`, `CONTRACTOR_DOC_MISSING`, `CONTRACT_WORKER_CAP_EXCEEDED`) are raised by
`yii contractor/check` (idempotent; run by `run_all.bat`).

## Inspection ranking

`urgency = (100 - score) + max(0, recent events - previous events) x WEIGHT_TREND`, events being
violations plus breaches in the last `TREND_WINDOW_HOURS` (24) against the 24 hours before.
Ties: urgency, severity, recent events, mine id - so the queue never reorders between identical
requests. `GET /dashboard` embeds the top 3 for multi-mine roles; a mine head gets none.
