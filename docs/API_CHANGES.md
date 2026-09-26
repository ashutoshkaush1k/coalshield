# API contract changes (FastAPI `backend/` → Yii2 `api/`)

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
