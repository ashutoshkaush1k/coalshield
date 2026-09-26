# API contract changes (FastAPI `backend/` → Yii2 `api/`)

Brief rule 12: every difference between the old API the React app consumes and the new one is
listed here. The frontend keeps talking to the old backend until Phase 2 switches it to
`VITE_API_URL`; entries marked **frontend: Phase 2** are adapted in that switch.

## Global

| # | Old (`backend/`, port 8000) | New (`api/`, port 8080) | Frontend |
|---|---|---|---|
| G1 | Base path `/api/v1` | Base path `/v1` | Phase 2 (`VITE_API_URL`) |
| G2 | Errors `{"detail": "..."}` (FastAPI) with English text | `{"error": {"code": "NOT_FOUND", "params"?: {...}, "fields"?: {"attr": ["CODE"]}}}`; codes only, translated by the frontend (`src/i18n/t.js` `errorMessage()`) | Phase 2 |
| G3 | Roles `GOVERNMENT`, `MINE_HEAD` | Lower case `government`, `corporate`, `mine_head`, `inspector` | Done in Phase 1: `src/auth/roles.js` normalises both spellings |
| G4 | Out-of-scope mine: 403 on some routes, 404 on others | Always **404 `NOT_FOUND`**, identical to a missing record (brief rule 2, owner decision 2026-09-27) | Phase 2 (demo script, docs) |
| G5 | Lists return the whole array, some with `limit` | Paged: `page`, `per_page` (max 200, `limit` accepted as alias), headers `X-Total-Count`, `X-Page`, `X-Per-Page`; `filter[attr]=`, `sort=-attr` (unknown attributes → 422) | Phase 2 |
| G6 | Timestamps without zone | ISO-8601 UTC with `Z` | Phase 2 (formatters already parse ISO) |
| G7 | Five seed mines (Jharia, Korba, Talcher, …) | 74 real mines from `data/reference/mines_real.csv`; old codes mapped by `data/reference/mine_code_mapping.csv` | Phase 2 (login quick-fill labels, demo script) |

## Endpoints available after Phase 1

| Endpoint | Change |
|---|---|
| `POST /v1/auth/login` | Same body `{email, password}`. Response adds `expires_in` (seconds); `user` now carries `subsidiary_id`, `area_id`, `preferred_language`, `status`, `created_at`, `updated_at`. Wrong credentials: 401 `INVALID_CREDENTIALS` (unknown e-mail, wrong password and inactive account look the same). E-mail is matched case-insensitively. |
| `GET /v1/auth/me` | Kept as an alias of `GET /v1/users/me`; same `user` shape as above. |
| `GET /v1/users/me` | New. |
| `PATCH /v1/users/me` | New. Only `preferred_language` (`en`, `hi`, `bn`, `or`, `te`, `mr`); any other field → 422 with `READ_ONLY` for that field. |
| `GET /v1/mines`, `GET /v1/mines/{id}` | Minimal Phase 1 version (scoping). `location` is a GeoJSON Point (`{"type": "Point", "coordinates": [lon, lat]}`) instead of the old free-text location; the old text is `district` + `state`. `operator` is the subsidiary code (e.g. `SECL`). New: `type`, `status`, `subsidiary_id`, `area_id`, `boundary`, `location_quality`, `capacity_mtpa`, `gem_id`. Compliance fields return in Phase 2. |
| `GET /v1/health` | New, public: `{status, database, time}`. |
