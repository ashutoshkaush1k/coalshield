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
| GET/POST | `/incidents`, `/incidents/{id}` | `incident.view` / `.create` | `?late=1`; each with `reporting_check` (the law's time for its obligation: RPT-05 12 h, RPT-04 60 h, RPT-03 forthwith + 1 h grace) and `reported_late` |
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
| GET | `/views/overview?state=` | `dashboard.view` | one request per overview poll: `{dashboard, contractor_summary}` - each part exactly its endpoint's response (`contractor_summary` null without `contractor.summary`) |
| GET | `/views/mine/{id}` | `mine.view` (+ each part's own) | one request per mine-screen poll: `{mine, trend, violations, alerts, audit, corrective_actions, incidents}`; 404 out of scope |
| GET | `/views/production?month=` | `production.manage` | mine head's production screen: `{month, today, entries, charts, requests}` |
| GET | `/views/production-overview?state=&date=` | `production.summary` | `{summary, requests}` |
| GET | `/production/summary?state=&date=` | `production.summary` | numbers only, per mine: day and month-to-date target / actual / achievement %, `anomaly {flagged, days[]}`, latest `request`; runs the deadline check |
| GET | `/production?mine_id=&from=&to=`, `/production/detail?...` | detail rule (below) | entries (with edit log); `detail` adds `charts` and the covering `request`. Range at most 92 days; the current month by default |
| GET | `/production/{id}` | detail rule | one entry with its edit log |
| POST | `/production` | `production.manage` | a draft for the own mine: `{date, shift, coal_target_t, ..., remarks?}`; 422 `ALREADY_REPORTED` / `IN_FUTURE` |
| PATCH | `/production/{id}` | `production.manage` | a draft freely; a submitted / locked entry only with `reason` (422 `REASON_REQUIRED`, `NOTHING_CHANGED`) - each changed field → `production_edit_log` |
| POST | `/production/{id}/submit`, DELETE `/production/{id}` | `production.manage` | draft → submitted; delete a draft only (422 `ENTRY_LOCKED`) |
| GET | `/detail-requests?mine_id=&status=`, `/detail-requests/{id}` | `detailRequest.view` | "Call for Detailed Report" in scope, newest first (detail with history); runs the deadline check |
| POST | `/detail-requests` | `detailRequest.create` | `{mine_id, date_from, date_to, reason, due_at}` (range ≤ 92 days, ending by today; due in the future, ≤ 60 days) |
| POST | `/detail-requests/{id}/respond` | `detailRequest.respond` | mine head: multipart `response_note`, `file?` - also late (overdue / escalated); resolves the overdue alert |
| POST | `/detail-requests/{id}/close` | `detailRequest.create` | submitted → closed |
| GET | `/public/mines` | public (rate-limited) | mines to choose from on the public grievance form (id, code, name, district, state) |
| POST | `/grievances/public` | public (rate-limited: 5 per hour per IP) | multipart or JSON: `mine_id, submitter_type, name?, contact?, is_anonymous, category, safety_category (safety), language, description, against_mine_head, latitude?, longitude?, file?` (PDF/JPEG/PNG, 5 MB), `website` (honeypot, must be empty → 400 `SUBMISSION_REJECTED`) → `{ticket_no, tracking_code, status, sla_due_at, created_at}` - the tracking code is returned this once only; 429 `RATE_LIMITED` with `Retry-After` |
| POST | `/grievances/track` | public (rate-limited: 20 per minute per IP) | `{ticket_no, tracking_code}` → status, category, dates, public timeline (action, status, time), resolution note once resolved - no text, no people. A wrong code and an unknown ticket get the same 404 `NOT_FOUND` |
| GET | `/grievances?mine_id=&state=&status=&category=&language=&open=&escalated=&sensitive=` | `grievance.view` | scoped and routed (a mine head never sees a sensitive grievance); open first, earliest SLA due first; runs the SLA check |
| GET | `/grievances/{id}` | `grievance.view` | with `timeline`; 404 for a sensitive grievance to a mine head |
| GET | `/grievances/stats?state=` | `grievance.stats` | totals, by category / mine / language, average hours to first resolution, SLA breaches, breach clusters |
| GET | `/grievances/{id}/assignees` | `grievance.manage` | users who may handle it (never a mine head for a sensitive one) |
| POST | `/grievances/{id}/transition` | `grievance.manage` | `{to, note}` - `resolved` needs a note (shown to the complainant); `reopened` starts a new SLA period |
| POST | `/grievances/{id}/assign` | `grievance.manage` | `{user_id}` - one of the assignees |
| GET | `/views/grievances?state=` | `grievance.view` | the grievance screen in one request: `{stats, escalated, grievances}` (stats and escalated null for a mine head) |
| GET | `/obligations` | `obligation.view` | the catalogue, each with `citation {instrument, clause, quote, source_file, page, verified}`, `generates_tasks` and `monitored_by` |
| GET | `/obligation-tasks?view=&state=&status=&domain=&per_page=` | `obligation.view` | tasks in scope (`view`: `due_soon`, `open`, `overdue`, `submitted`, `accepted`), earliest due first (`accepted`: latest first); runs the obligation check |
| GET | `/obligation-tasks/{id}` | `obligation.view` | with `obligation` (citation), `due_basis`, `submissions` (each with its review and a signed `file_url`) |
| POST | `/obligation-tasks/{id}/submissions` | `obligation.submit` (mine head) | multipart `file` (required; PDF/JPEG/PNG/WEBP) + `note?` → the task, now `submitted`; 422 `INVALID_TRANSITION` for an accepted or already submitted task |
| POST | `/obligation-tasks/{id}/waive` | `obligation.waive` (government) | `{reason}` - reason required (field `reason`: `REASON_REQUIRED`, `TOO_SHORT`); open, rejected, overdue or escalated → `waived`; resolves the overdue alert; 422 `INVALID_TRANSITION` otherwise |
| POST | `/obligation-submissions/{id}/review` | `obligation.review` (government, inspector) | `{decision: accept\|reject, note}` - reject needs a reason (field `note`: `REASON_REQUIRED`, `TOO_SHORT`); 422 `ALREADY_REVIEWED` |
| GET | `/obligations/summary?state=` | `obligation.summary` | statutory compliance per mine (lowest first), company and domain, totals, the 20 most overdue items, pending-review count |
| GET | `/views/obligations?state=` | `obligation.view` | the register in one request - mine head: `{summary, due_soon, overdue, open, submitted, accepted}`; others: `{summary, pending_review}` (the 50 oldest) |
| GET | `/views/map?state=` | `mine.view` | `{mines}` - the mines in scope as GeoJSON with score, band, district and location quality |
| GET | `/geo/states`, `/geo/districts` | `mine.view` | local GeoJSON outlines; `ETag`, 304, cached a day; 503 `BOUNDARIES_MISSING`. Districts are the 2011 districts holding a mine in scope, each listing only those mines |
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

## Production (Phase 4)

**Entries.** `draft` (free to edit) → `submitted` (the mine head submits; closed to direct
editing) → `locked` (the reporting period closed, `product.production_lock_after_days` = 7 after
the date, by `yii production/check`). A submitted or locked entry changes only with a reason:
every changed field gets a `production_edit_log` row (old, new, reason, who, when) in the same
transaction, and the audit chain has the change.

**Detail rule** (`api/components/AccessRule.php`): the mine must be in scope (404 otherwise). A
mine head (`production.viewDetail`) sees its own mine in full. A multi-mine role
(`production.viewRequested`) sees a mine's entries for `[from, to]` only when a detail request at
that mine with status `submitted` or `closed` covers the whole range; otherwise **403
`DETAIL_REQUEST_REQUIRED`** with `params {mine_id, from, to}`. The summary is numbers only.

**Call for Detailed Report.** `pending → submitted → closed`; `pending → overdue` when `due_at`
passes, raising `DETAIL_REQUEST_OVERDUE` (escalation level 1); `overdue → escalated` after
`product.detail_request_escalate_after_hours` (72), the alert moving to level 2; overdue and
escalated requests can still be answered, which resolves the alert. The deadline check runs on
every read of the requests or the summary and in `yii production/check`, as a system action (no
user in the history or the audit chain), and is idempotent.

**Anomaly** (`ProductionService::anomalies`, PHP fallback until the ai-service in Phase 7), per
mine and day on the day's total: `OVER_TARGET` (output more than `over_target_pct` = 50 % above
target), `ROLLING_MEAN_SPIKE` / `ROLLING_MEAN_DROP` (output at least 1.5x - or at most 1/1.5x - the
mean of the mine's producing days in the previous 30, and 4 standard deviations away, **and the same
for output ÷ target**, so a move the target explains is not flagged; needs 14 producing days).
Settings: `data/schema/rules.yaml` `product.production_anomaly` (product settings, not law). On the
demo data it flags 5 of 6,142 mine-days: S2 (Gevra, 4 Sep, 2.0x, z 14.4) and four month-boundary
steps; the decoys N1 and N3 are not flagged (`api/tests/unit/ProductionAnomalyTest.php`).

## Grievances (Phase 5)

**Routing and visibility** (`api/components/AccessRule.php`): a grievance is *sensitive* when its
category is `harassment` or `against_mine_head` is true. A sensitive grievance is assigned to the
government and **does not exist for a mine head**. It is not listed, 404 by id, and neither its
`GRIEVANCE_SLA_BREACHED` alert, nor the open-alert count, nor its audit entries reach the mine
head. The complainant's `name` and `contact` are serialised only to government and inspector, and
to corporate for grievances that are not sensitive; for everyone else the keys are absent. The
audit log stores them as `[redacted]`.

**SLA** (`rules.yaml` `product.grievance_sla_hours`, product settings): safety 48 h, harassment
72 h, working conditions and environment 120 h, wages and other 168 h, land compensation 336 h.
A grievance still open at `sla_due_at` is an SLA breach: escalation level 1, an `escalate` step in
its timeline and `GRIEVANCE_SLA_BREACHED` (high). Still open one more SLA period later
(`grievance_escalate_again_after_sla`), it reaches level 2. The check runs on every read and in
`yii grievance/check`; it is idempotent and recorded as a system action. A reopened grievance gets
a new SLA period.

**Breach clusters** (`grievance_breach_cluster`: at least 5 grievances raised within 10 days, all
breaching their SLA): on the demo seed only Kulda (OD-SUN-07, scenario S6) is flagged. Jhanjra's
burst of six grievances handled in time (N2) is not (`api/tests/unit/GrievanceClusterTest.php`).

**Safety grievances** that are not sensitive open an observation (category from the form, severity
high, status open) for the inspection flow. Sensitive ones do not, because an observation is
visible to the mine head.

**Tickets** `GRV-YYYY-NNNNNN` come from a per-year counter (`next_grievance_ticket()`) that never
goes below the highest ticket already stored.

## Obligation register (Phase 5B)

**Source.** The 40 obligations, their applicability to each mine and the demo window's tasks and
submissions come from the data track (`data/generators/gen_obligations.py`, `data/DATASETS.md`),
loaded by `yii seed`. Only verified obligations with a calendar frequency get tasks; RPT-08
(TODO-VERIFY) never does. Every screen shows each obligation's citation (act, section or rule,
and the verbatim quote on hover or expand, with the source file and page). The source PDFs are
not in the repository (`raw/legal/` is not distributed), so the page is named, not linked.
Obligations without dated tasks are listed under *Obligations not on the dated register*, with how
each is handled: continuous limits the sensor rules watch (SAF-11, HLT-04, HLT-05 -
`monitored_by`), continuous duties, every shift (SAF-08), on an event, once or on renewal.

**Schedule** (`ObligationService::periods()`, the same rules as the generator - proven by
`api/tests/unit/ObligationScheduleTest.php`): weekly = ISO weeks, fortnightly = pairs of ISO weeks,
monthly / quarterly / half-yearly / annual = calendar periods. The task is due at 23:59:59 IST on
the period's last day - a product setting (`rules.yaml` `obligation_schedule.due`), labelled so on
screen - except where the law names the date: ENV-03 (financial year, due 30 September) and RPT-06
(calendar year, due end of February).

**Workflow.** `open` → `submitted` (mine head uploads evidence) → `accepted` or `rejected` (government
or inspector; a rejection needs a reason, which the mine sees) → `submitted` again. Government may
waive a task that has no accepted evidence (`waived`, with a reason kept in the history); a waived
task leaves statutory compliance. Past its due
time an open or rejected task becomes `overdue` (escalation level 1, `OBLIGATION_OVERDUE` high);
still unsubmitted `escalate_after_hours` (168) later it becomes `escalated` (level 2). Evidence
submitted late is accepted as late. `OBLIGATION_DUE_SOON` reminds a mine `reminder_days` (3) before
a due time, one alert per mine and due time. The check runs on register reads (once per request),
in `yii obligation/check` and in `run_all.bat`; it is idempotent and recorded as a system action.
New periods' tasks are created once a day.

**Incidents.** Each incident has one task for its reporting obligation (RPT-03 / RPT-04 / RPT-05
by severity), due at the **law's time** (`components/IncidentDeadline.php`, the same rule as the data
track):
- RPT-05 *within twelve hours*: 12 h, law;
- RPT-04 *within twelve hours after the completion of forty-eight hours*: 60 h from the incident,
  law;
- RPT-03 *forthwith*: immediate with a 1 h grace, a product setting (labelled so in the UI).

The incident's own `reporting_check` uses the same deadline: `REPORTED_ON_TIME` / `REPORTED_LATE`,
with params `hours`, `limit_hours`, `basis`, `rule` (the law's wording, shown as written) and
`obligation_code`. The task is created done: `accepted` at `reported_at`, `reported_late` when that
is after the due time.
The incident record is the report, so there is no upload and no review. Seeded incidents' tasks come
from the data track; `POST /v1/incidents` creates the task for a new one.

**Statutory compliance** = tasks due in the last 90 days whose evidence was submitted by the due
time and accepted, divided by the tasks due. It is a **separate metric**: the compliance score and
its formula are unchanged (`api/tests/api/ObligationCest.php`
`statutoryComplianceLeavesTheScoreAlone`, and the demo-score test).

## Map (Phase 5B)

Works with no internet: Leaflet is bundled, and the state and district outlines are served by the
API from `data/reference/` (DataMeet, CC BY 4.0 / CC BY 2.5 IN, simplified in the data track). The
districts are the Census 2011 districts containing the real roster's mines
(`map_districts.geojson`), limited to the caller's scope. Mines are drawn at their coordinates, coloured and labelled by risk band, with the location
quality (exact GEM, approximate GEM, Wikidata, district centre - approximate ones dashed). The
OpenStreetMap basemap is optional and off by default; when on, it carries OSM's attribution and
only loads the tiles in view. GEM (CC BY 4.0) and DataMeet are credited on the map.

## Inspection ranking

Since Phase 7 the queue is ordered by the **Governance Risk Index** (below), highest first.
Ties: urgency, severity, recent events, mine id - so the queue never reorders between identical
requests. `urgency = (100 - score) + max(0, recent events - previous events) x WEIGHT_TREND`,
events being violations plus breaches in the last `TREND_WINDOW_HOURS` (24) against the 24 hours
before; it is still returned with each candidate. `GET /dashboard` embeds the top 3 for multi-mine roles; a mine head gets none.

## Governance Risk Index (Phase 7)

A separate measure beside the compliance score, which it does not change. 0-100, higher is worse.
Points per item, each component capped, the sum times a repeat multiplier, capped at 100. All
weights are product settings (`rules.yaml` product.governance_risk_index):

| Component | Counts | Points | Cap |
|---|---|---|---|
| `open_violations` | unresolved violations | 2 | 25 |
| `sensor_breaches` | threshold breaches in the compliance score's window | 1 | 10 |
| `overdue_obligations` | obligation tasks overdue or escalated | 2 | 20 |
| `overdue_contractor_docs` | monthly contractor documents past their due day | 1 | 10 |
| `grievances_past_sla` | open grievances past their response time | 3 | 15 |
| `ageing_corrective_actions` | open corrective actions past due | 2 | 20 |

Multiplier: 1 + 0.1 per violation category with 5 or more violations in 45 days, at most 1.5.
Bands: high >= 50, medium >= 20, else low. A mine head's index leaves out the sensitive grievances
it cannot see (the regulator's can be higher). `yii jobs/score` keeps a daily history
(`mine_risk_snapshot`).

## Predicted risk (Phase 7)

`prediction.probability` is the model's chance of a high-accident next year (lost-time and fatal
accidents at 3 or more per 100 workers), from a gradient-boosting model **trained on US MSHA coal
mine-years and transferred** to these mines (docs/AI_EVALUATION.md section 2). Bands: high >= 0.5,
medium >= 0.25. `factors` are the features that raise it most: `points` = percentage points added
compared with a typical mine; `fleet_percentile` = the mine's rank in our fleet on that violation
rate (`typical` instead, for features not transferred). Stored by `yii jobs/score`; the screens
never run the model.

## Field sync (Phase 7B)

The field app works offline and sends its queue later: `POST /v1/field/sync` with the items in the
order they were made, then `POST /v1/field/photos` for the photos of synced captures.

- **Idempotent by the phone's id.** Each item's `client_id` (a UUID made on the phone) is processed
  once: its result is stored with it, and a retry - a lost answer, a second tap - returns that result
  (`replayed`) without acting again. An id already used by another account is refused
  (`CLIENT_ID_CONFLICT`).
- **One transaction per item.** A failing item (validation, scope, a closed inspection) is reported with
  its `{code, params, fields}` and leaves nothing behind; the other items of the batch go through. A
  capture whose visit has not synced fails with `VISIT_NOT_SYNCED` and syncs once the visit has.
- **The normal rules.** Mines and inspections are read through the caller's scope (another mine: 404
  `NOT_FOUND` in the item's result); the inspection moves scheduled -> visited through its status
  transitions; the violation, its `VIOLATION_RECORDED` alert and the corrective action come from the
  same services as the dashboards'; everything is in the audit chain and the status history.
- **An expired login** refuses the whole batch with 401 and writes nothing; the phone keeps the queue
  and sends it after the next sign-in.
- **Times.** `recorded_at` is the phone's time as reported, `received_at` the server's. `device_now`
  (the phone's clock at sending) against the server's clock gives `clock_skew_s`; beyond 300 s the
  capture is flagged and its observation time is the receipt time.
- **Place.** The distance from the mine's recorded point (PostGIS, geography) is stored; beyond 5 km the
  capture is flagged, not refused. The mines have points, not boundaries, so a radius is the check.
