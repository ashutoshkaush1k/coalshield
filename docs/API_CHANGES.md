# API contract changes (FastAPI prototype `backend/` → Yii2 `api/`)

The prototype was removed in Phase 8 (it is in git history); the table below is the record of what changed.

Brief rule 12: every difference between the prototype API and the new one is listed here. Since
Phase 2 the frontend talks only to the new API (`VITE_API_URL`, default
`http://localhost:8080/v1`); the adaptations it needed are noted per row. Full contract:
[api-contract.md](api-contract.md).

## Global

| # | Prototype (`backend/`, port 8000) | New (`api/`, port 8080) | Frontend |
|---|---|---|---|
| G1 | Base path `/api/v1`, env `VITE_API_BASE_URL` | Base path `/v1`, env `VITE_API_URL` (the old variable is read as a fallback for one release) | `src/api/client.js` |
| G2 | Errors `{"detail": "..."}` with English text | `{"error": {"code", "params"?, "fields"?}}`, codes only | `client.js` normalises; `i18n/t.js` `errorMessage()` translates |
| G3 | Roles `GOVERNMENT`, `MINE_HEAD` | `government`, `corporate`, `mine_head`, `inspector` | `auth/roles.js`; corporate and inspector use the overview screens |
| G4 | Out-of-scope mine: 403 | **404 `NOT_FOUND`**, identical to a missing record (owner decision 2026-09-27) | `ErrorNotice` shows "Not available" |
| G5 | Lists returned whole arrays, some with `limit` | Paged: `page`, `per_page` (`limit` alias), `X-Total-Count`, `filter[]`, `sort` | callers pass `per_page` |
| G6 | Timestamps without zone | ISO-8601 UTC with `Z` | unchanged formatters |
| G7 | Five seed mines (Jharia, Singrauli, Korba, Raniganj, Talcher) | 74 real mines (`data/reference/mines_real.csv`); the five demo codes kept, now Moonidih, Jayant, Gevra, Sonepur Bazari, Bhubaneswari | login quick-fill, demo script, docs |
| G8 | Risk levels `LOW`/`MEDIUM`/`HIGH`, severities upper case | lower case everywhere | `utils/risk.js`, CSS accepts both |
| G9 | Display text in payloads (`alert.message`, `audit.detail`, `inspection.reasons[]`, `status_label`, `resolution_reason`) | `{code, params}` only (brief rule 7). PLAN Q7's transitional legacy text fields were **not** kept: the owner required alerts to store `{code, params}` only | `src/i18n/labels.js` + `locales/en.json` |
| G10 | Sensors `gas` (ppm), `dust`, `temperature` with demo thresholds 50 / 10 / 45 | `ch4`, `ch4_return_air`, `co`, `dust`, `temperature` (wet bulb), `humidity`; limits from `data/schema/rules.yaml` (SAF-11, HLT-04 as an 8-hour mean, HLT-05); CO and humidity have no limit, so `breached` is `null` | fleet table columns, chart categories gas / dust / temperature |

## Endpoints

| Prototype | New | Change |
|---|---|---|
| `POST /auth/login` | `POST /v1/auth/login` | adds `expires_in`; `user` adds `subsidiary_id`, `area_id`, `preferred_language`, `status`, `mine_name`, `subsidiary_code`, `permissions`, timestamps; wrong credentials 401 `INVALID_CREDENTIALS` |
| `GET /auth/me` | `GET /v1/users/me` (alias `/v1/auth/me`) | as login's `user`; `PATCH /v1/users/me` changes `preferred_language` only |
| `GET /mines`, `/mines/{id}` | same under `/v1` | `location` is a GeoJSON Point (was free text; the text is `district` + `state`); `operator` is the company code, `operator_name` its name; adds `type`, `status`, `subsidiary_id`, `area_id`, `boundary`, `location_quality`, `capacity_mtpa`, `gem_id`. `compliance` and `open_alerts` as before (`risk_level` lower case, adds `is_demo_value: true`). New `GET /v1/mines/geojson` |
| `GET /dashboard` | `GET /v1/dashboard` | `scope` is `all_mines` / `subsidiary` / `mine`; `scope_label` text replaced by `scope_label_code` (`NATIONAL`, `SUBSIDIARY`, `STATE`, `THIS_MINE`); `mines[]` as `/mines`; queue preview for every multi-mine role |
| `GET /inspections` (queue) | `GET /v1/inspections/priority` | candidates carry `district`, `state` instead of `location`; `reasons` are `{code, params}`; `direction` lower case. `GET /v1/inspections` now lists inspection **records** |
| `GET /sensors` (fleet) | `GET /v1/sensors` | allowed for every multi-mine role (403 for a mine head); per sensor `status_code` replaces `status_label`, plus `compare` and `obligation`; `threshold` may be `null`; sensor types per G10; top-level `thresholds`. Anomaly scores are not served until the ai-service gets them (Phase 7) |
| `GET /sensors/breaches` | same | unchanged shape; categories gas / dust / temperature from the new types |
| `GET /sensors/{id}`, `/sensors/{id}/trend` | same | trend: one series per sensor type the mine reports, with `category`, `compare`, `obligation`, `threshold` possibly `null`; readings paged; no `anomaly_score` |
| `GET /sensors/live`, `/sensors/{id}/live` | removed | not used by the frontend; live charts poll the trend |
| `WS /ws` | removed | the dashboards poll (brief Phase 2: "keep it simple"); a websocket is optional later |
| (simulator wrote to SQLite) | `POST /v1/sensor-readings/ingest` (API key) | machine endpoint; `GET /v1/sensor-readings/baseline` for the pre-flight |
| `GET /violations` | `GET /v1/violations` (+ `/{id}`) | adds `category` (11 categories), `inspection_id`, `observation_id`, `contractor_id`; `confidence` and `frame_ref` may be `null` (inspection findings) |
| `GET /alerts` | `GET /v1/alerts` (+ `/{id}`) | `{code, params, severity, status (open/acknowledged/resolved), entity_type, entity_id, ack_by, escalation_level, is_directive, history[]}` replaces `message`, `source`, `alert_type`, `acknowledged`, `raised_by`, `reference_id`, `resolutions[]`; `?since=` for polling |
| `POST /alerts/{id}/ack` | same | open → acknowledged (422 `INVALID_TRANSITION` otherwise) |
| `POST /alerts/directives` | same | creates `INSPECTION_DIRECTIVE`; the snapshot (score, band, counts, author) is in `params` |
| `POST /alerts/{id}/resolve`, `/reopen` | same | proof and reasons are in `history[].context`; proof images as signed `proof_url` |
| `GET /audit` | `GET /v1/audit` | entries are `{entity, entity_id, action (insert/update/delete/...), old_values, new_values, actor, row_hash, mine_id}` from the hash-chained audit log instead of `{actor, action, entity_type, detail}` text |
| `POST /vision/analyze` | `POST /v1/vision/analyze` | via ai-service; `annotated_url` is a signed `/v1/files/...` link; `resolution_reason` text → `resolution: {code, params}`; a clean frame resolves open **PPE vision** findings only (the prototype resolved every open violation); ai-service down → 503 `AI_SERVICE_UNAVAILABLE` |
| (stubs) | `/v1/corrective-actions`, `/v1/compliance/{id}`, `/{id}/history`, `/v1/incidents`, `/v1/inspections` records, `/v1/observations`, `/v1/admin/baseline-check`, `/v1/files/{id}/content`, `/v1/health` | new |

## Phase 3 additions (no prototype equivalent)

| New | Notes |
|---|---|
| `/v1/contractors`, `/summary`, `/{id}`, `/{id}/status` | contractor register, per-mine summary, detail, status workflow |
| `/v1/contracts`, `/{id}/workers`, `/{id}/documents`, `/v1/contract-workers/{id}`, `/v1/contractor-docs/{id}/verify` | contracts, workers, monthly documents (multipart upload through FileStorage) |
| `PATCH /v1/violations/{id}/contractor`; `contractor_id` on `POST /v1/corrective-actions` | link findings to the contractor responsible |
| alert code `CONTRACT_WORKER_CAP_EXCEEDED` | new; the other contractor codes existed in the data and are now also raised by `yii contractor/check` |
| `GET /v1/audit` `source` field and filter | `seed_history` entries: the seeded records' history, backfilled with original timestamps and actors |
| `/v1/vision/analyze` `backend` | now `yolo` when `ai-service/ml/weights/ppe.pt` exists (fine-tuned model, docs/AI_EVALUATION.md), `fixture` otherwise |

## Performance (before Phase 4, docs/PERFORMANCE.md)

| New / changed | Notes |
|---|---|
| `GET /v1/views/overview?state=` | `{dashboard, contractor_summary}` - the government / corporate overview in one request (was `/dashboard` + `/contractors/summary`) |
| `GET /v1/views/mine/{id}` | `{mine, trend, violations, alerts, audit, corrective_actions, incidents}` - the mine screen in one request (was seven) |
| polling | every 10 s (was 5 s); at most two requests per screen and cycle |
| `/v1/sensors/{id}/trend` | same response; one query for all sensor types |
| `/v1/alerts` | same response; the histories are loaded in one query |

## Phase 4 additions (no prototype equivalent)

| New | Notes |
|---|---|
| `/v1/production`, `/{id}`, `/{id}/submit`, `/summary`, `/detail` | daily entries (draft, submit, correction with reason → `production_edit_log`), numbers-only summary with the anomaly flag, detail behind the detail rule (403 `DETAIL_REQUEST_REQUIRED`) |
| `/v1/detail-requests`, `/{id}`, `/{id}/respond`, `/{id}/close` | "Call for Detailed Report"; overdue and escalated automatically with `DETAIL_REQUEST_OVERDUE` (levels 1 and 2) |
| `/v1/views/production`, `/v1/views/production-overview` | one request per production screen |
| error codes | `DETAIL_REQUEST_REQUIRED` (403), `ENTRY_LOCKED`, `NOTHING_CHANGED` (422); field codes `REASON_REQUIRED`, `ALREADY_REPORTED`, `IN_FUTURE`, `IN_PAST`, `TOO_FAR`, `RANGE_TOO_LONG`, `BEFORE_START`, `OVER_SHIFT_HOURS`, `NEGATIVE`, `TOO_LARGE`, `INVALID_NUMBER`, `INVALID_DATE` |
| audit | seeded production history (`submitted`, `edited`, `requested`, `responded`) in the chain as `seed_history` |

## Phase 5 additions (no prototype equivalent)

| New | Notes |
|---|---|
| `/v1/public/mines`, `POST /v1/grievances/public`, `GET /v1/grievances/track/{ticket}` | public, no token; rate-limited per IP (`rate_limit` table), honeypot, file type and size checks |
| `/v1/grievances`, `/{id}`, `/stats`, `/{id}/assignees`, `/{id}/transition`, `/{id}/assign`, `/v1/views/grievances` | staff queue and analytics; sensitive routing and identity rules in `AccessRule` |
| `GRIEVANCE_SLA_BREACHED` | now raised by the API (it existed in the seeded history); escalation levels 1 and 2 |
| error codes | `RATE_LIMITED` (429), `SUBMISSION_REJECTED` (400); field codes `FILE_EMPTY`, `UPLOAD_FAILED` |
| `grievance_action.action` | adds `assign` to the data schema's list (an assignment step in the timeline) |
| `file.uploaded_by` | nullable, only for a public grievance attachment (a CHECK enforces it); the data schema says not null |
| `/v1/alerts`, `/v1/audit`, `open_alerts` | for a mine head, exclude everything about sensitive grievances |

## After Phase 5: grievance tracking code

| Changed | Notes |
|---|---|
| `POST /v1/grievances/public` | the response adds `tracking_code` (8 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789`), returned once and never again; only an HMAC-SHA256 of it is stored (`grievance.tracking_code_hash`) |
| `GET /v1/grievances/track/{ticket}` | **removed**. Now `POST /v1/grievances/track` with `{ticket_no, tracking_code}` in the body, so the code never appears in a URL or an access log. A wrong code gets exactly the same 404 `NOT_FOUND` as an unknown ticket |
| seeded grievances | carry a code too, generated deterministically in the data track (`grievance.tracking_code`); `yii seed` stores only its hash |

## Phase 5B additions (no prototype equivalent)

| New | Notes |
|---|---|
| `GET /v1/obligations` | the catalogue (40 obligations), each with `citation {instrument, clause, quote, source_file, page, verified}`, `generates_tasks`, and `monitored_by` (the sensor types whose limit it gives) |
| `GET /v1/obligation-tasks?view=due_soon\|open\|overdue\|submitted\|accepted&state=&status=&domain=` | tasks in scope, `X-Total-Count` |
| `GET /v1/obligation-tasks/{id}` | one task with its obligation, due time and basis, and every submission |
| `POST /v1/obligation-tasks/{id}/submissions` | mine head: multipart `file` (required) + `note`; the task becomes `submitted` |
| `POST /v1/obligation-submissions/{id}/review` | government or inspector: `{decision: accept\|reject, note}`; reject needs a reason (field code `REASON_REQUIRED`, `TOO_SHORT`); accepting resolves the task's `OBLIGATION_OVERDUE` alert |
| `POST /v1/obligation-tasks/{id}/waive` | government only: `{reason}` (field code `REASON_REQUIRED`, `TOO_SHORT`) - the mine is not bound for the period; the reason is kept in the task's history, the overdue alert is resolved, and the task leaves statutory compliance |
| `GET /v1/obligations/summary?state=` | statutory compliance (tasks due in the last 90 days submitted on time and accepted) per mine, company, domain, plus the most overdue items. A separate metric - the compliance score is unchanged |
| `GET /v1/views/obligations?state=` | one request per register screen: a mine head gets `{summary, due_soon, overdue, open, submitted, accepted}`, multi-mine roles `{summary, pending_review}` |
| `GET /v1/views/map?state=` | `{mines}`: the `/v1/mines/geojson` part |
| `GET /v1/geo/states`, `/v1/geo/districts` | local GeoJSON outlines (DataMeet via the data track), `ETag` + `Cache-Control: private, max-age=86400`; 503 `BOUNDARIES_MISSING` if the file is absent. Districts: the 2011 districts holding a mine in the caller's scope, each listing only those mines (`data/reference/map_districts.geojson`). PLAN named it `/v1/districts/geojson` |
| `/v1/mines/geojson` | properties add `district` and `open_alerts` |
| alert codes | `OBLIGATION_DUE_SOON` (params `{due_at, count, obligations, task_ids}`, entity `mine`), `OBLIGATION_OVERDUE` (params `{task_id, obligation, period, due_at, instrument, clause}`, levels 1 and 2) |
| error codes | `ALREADY_REVIEWED` (422), `BOUNDARIES_MISSING` (503) |
| audit | seeded submissions and reviews in the chain as `seed_history` |
| console | `yii obligation/check [--at=ISO]`, `yii obligation/summary` |
| incidents on the register (after 5B) | `obligation_task.incident_id` (migration `m261004_000001`); `POST /v1/incidents` also creates the incident's reporting task (RPT-03/04/05), due 48 h after it occurred (product setting), `accepted` at `reported_at`. Tasks add `incident_id` and `reported_late`, and the task detail adds `incident`; the catalogue adds `from_incidents`. Statutory compliance counts them on time or late by `reported_at` |

## Phase 6 (multilingual)

| Changed | Notes |
|---|---|
| `GET /v1/users/me` (and `/auth/me`, the login response's `user`) | adds `mine_code`, `subsidiary_name`, `area_name` (the account's area, or its mine's) for the Profile page |
| `PATCH /v1/users/me` | unchanged: `preferred_language` only (`en`, `hi`, `bn`, `or`, `te`, `mr`); the frontend now uses it and applies the saved language after login |
| errors and alerts | unchanged `{code, params}`; every code now has text in all six languages |

## Phase 7: incident reporting at the law's time (owner decision)

| Changed | Notes |
|---|---|
| incident `reporting_check` | codes `REPORTED_WITHIN_48H` / `REPORTED_AFTER_48H` replaced by `REPORTED_ON_TIME` / `REPORTED_LATE`; params add `basis` (law / product) and `rule` (the law's wording); `limit_hours` is the obligation's own: RPT-05 12, RPT-04 60, RPT-03 1 (forthwith + grace) |
| incident fields | add `reported_late`; `reported_within_48h` stays as recorded data |
| `GET /v1/incidents?late=1` | late by the obligation's deadline, not 48 h |
| incident reporting tasks | due at the law's time (`due_basis` law), RPT-03 forthwith + 1 h grace (`due_basis` product) |

## Phase 7: automation, Governance Risk Index, predicted risk

| New | Notes |
|---|---|
| `GET /v1/views/priority[?state=]` | the priority tab in one request: `{queue, patterns}` - the inspection queue (as `/v1/inspections/priority`) and the active detector findings in scope (as `/v1/anomalies`); multi-mine roles (`inspection.viewQueue`) |
| `GET /v1/anomalies?status=active\|cleared\|all&detector=&mine_id=&per_page=` | the detectors' findings in scope: `{id, detector, mine_id, mine_name, mine_code, subject, from, to, score, reasons: [{code, params}], entities, engine, status, first_detected_at, last_seen_at}`; permission `risk.view` |
| `GET /v1/mines/{id}/risk` | `{mine_id, governance_risk, prediction, patterns}`; `governance_risk` = `{gri, band, raw, multiplier, repeat_categories, components: [{key, count, points, cap, value}]}`; `prediction` = `{probability, band, fleet_percentile, factors: [{feature, value, fleet_percentile, typical, points}], predicted_at, model_version, engine, target, test_auc, baseline_auc}` or null before the first `jobs/score`; 404 out of scope |
| `GET /v1/risk/model` | the model card: training data, split, test metrics against the baseline, reliability, transfer statement |
| alert codes | `ANOMALY_DETECTED` (params `detector, subject, reason, reason_params, from, to`; entity `anomaly_flag`), `PRODUCTION_ENTRY_PENDING` (params `date, missing_shifts, draft_shifts`) |
| ai-service | `GET /anomaly` (detector list and version), `POST /anomaly/{detector}` (payload -> `{flags}`; 404 `UNKNOWN_DETECTOR`, 422 `INVALID_PAYLOAD`), `GET /risk/model`, `POST /risk/predict` (`{mines: [{mine_id, features}]}` -> predictions; 503 `MODEL_MISSING`); the vision code now lives in `ai-service/vision/` |

| Changed | Notes |
|---|---|
| `GET /v1/inspections/priority` | candidates add `governance_risk`; the queue is **ordered by the Governance Risk Index** (then urgency, severity, recent events, mine id); the first reason is `PRIORITY_GRI` `{gri, band, component, count, multiplier}`. `urgency` and the compliance score are still returned and unchanged |
| `GET /v1/views/mine/{id}` | adds `risk` (the `/v1/mines/{id}/risk` body; null without `risk.view`) |
| alerts | `escalation_level` is now raised by `jobs/escalate-alerts` (1 after 24 h open, 2 after 72 h) |
| compliance score | **unchanged**; the demo scores (100/80/70/60/45, fleet 83.2) and `DemoScoreCest` are as before |
| RBAC | `risk.view` for every role (scoped as usual; a mine head's index leaves out the sensitive grievances it cannot see) |

## Phase 7B: the offline field app

| New | Notes |
|---|---|
| `GET /v1/field/bootstrap` | what the phone keeps offline: `{server_now, user, settings, categories: [{key, types}], checklist: {version, items: [{code, category, obligations}]}, obligations: [{code, title, instrument, clause}], mines: [{id, code, name, district, state, lat, lon, assigned}], inspections: [{id, mine_id, inspection_type, status, scheduled_for}]}` - the mines in scope (an inspector's assigned ones first), open inspections assigned to this inspector (none for a mine head) |
| `POST /v1/field/sync` | `{device_now, items: [...]}` (1-200 items, in order). `visit` `{client_id, mine_id, inspection_id?, recorded_at}`: puts the inspection in progress (the inspector's scheduled one, or a new one: inspector `spot`, mine head `self`). `capture` `{client_id, visit_client_id, category, severity, checklist_item?, obligation_code?, note?, recorded_at, location_status: gps\|unknown_underground\|denied\|unavailable, location?: {lat, lon, accuracy_m}, violation_type?, corrective_action?: {description, due_days}}`: an observation, and with a violation type the violation (with its alert) and optionally a corrective action. Answers 200 `{server_now, clock_skew_s, results: [{client_id, kind, status: created\|replayed\|failed, result?, error?: {code, params?, fields?, http_status}}]}` |
| `POST /v1/field/photos` | multipart `{client_id, capture_client_id, file}` (JPEG, PNG or WebP, at most 5 MB, at most 4 per capture): 201 `created`, 200 `replayed`; 409 `CAPTURE_NOT_SYNCED`, 422 `TOO_MANY_PHOTOS` |
| error codes | `VISIT_NOT_SYNCED` (409), `CAPTURE_NOT_SYNCED` (409), `CLIENT_ID_CONFLICT` (409, the id belongs to another account or kind), `INSPECTION_NOT_ASSIGNED` (422), `TOO_MANY_PHOTOS` (422) |
| RBAC | `field.capture`: inspector, mine head |

| Changed | Notes |
|---|---|
| idempotency | every visit, capture and photo carries the phone's UUID; the first sync stores its result (`field_sync`), a retry returns it with `status: replayed` and changes nothing. `observation.client_uuid` and `inspection.client_uuid` are unique as well |
| violations and observations | add `field` (null unless made with the field app): `{client_id, checklist_item, obligation_code, note, recorded_at, received_at, location: {lat, lon, accuracy_m}\|null, location_status, distance_m, geo_flag, clock_skew_s, clock_flag, recorded_by, photos: [{id, url}]}` (signed photo links) |
| flags | `geo_flag`: more than `product.field_capture.geo_radius_m` (5 km) from the mine's recorded point; `clock_flag`: the phone's clock off by more than `clock_skew_s` (300 s) at sync, and then the observation's time is the receipt time. Flagged captures are stored like any other |
| inspection types | adds `self` (a mine head's inspection from the field app) |
| migration | `m261006_000001_field_capture` (reversible): observation capture columns, `inspection.client_uuid`, `field_sync` |
