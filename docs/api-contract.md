# API contract (v1)

Base: `/api/v1`. All routes except `/auth/login` require `Authorization: Bearer <token>`.
"Scoped" means the response is filtered to the caller's mine by `deps.resolve_mine_scope`.

| Method | Path | Role | Purpose |
|---|---|---|---|
| POST | `/auth/login` | public | Both login types; returns token with `role` + `mine_id` |
| GET | `/auth/me` | any | Current identity and scope |
| GET | `/mines` | any | All mines (Government) / own mine only (Mine Head) |
| GET | `/mines/{mine_id}` | scoped | Mine detail, drill-down |
| GET | `/dashboard` | any | Role-aware payload: multi-mine grid or own-mine summary |
| GET | `/compliance/{mine_id}` | scoped | Current score + risk level |
| GET | `/compliance/{mine_id}/history` | scoped | Score trend |
| GET | `/sensors/{mine_id}` | scoped | Readings, filterable by sensor type and window |
| GET | `/sensors/{mine_id}/trend` | scoped | Gas/dust/temperature series with thresholds |
| POST | `/vision/analyze` | scoped | Upload image/video → detections, violations, annotated frame |
| GET | `/violations` | scoped | Violation log |
| GET | `/alerts` | scoped | Alerts feed (aggregated for Government) |
| POST | `/alerts/{id}/ack` | scoped | Acknowledge an alert |
| GET | `/inspections` | **Government** | Auto-ranked inspection queue (`?limit=N`) |
| GET | `/audit` | scoped | Audit trail |
| GET/POST/PATCH | `/corrective-actions` | **Mine Head** | Remediation tracker for own site |
| WS | `/ws` | any | Live alert and sensor push |

### Inspection ranking

`urgency = (100 - compliance_score) + trend_pressure x weight_trend`

Severity orders the risk bands on its own, since bands are score ranges. `trend_pressure` is the
rise in violation + breach count over the trailing `TREND_WEIGHT` window versus the window before
it, floored at zero so an improving mine falls down the queue rather than leapfrogging a worse one.

Ties resolve in a fixed order — urgency, then severity, then recent events, then `mine_id` — so the
queue never reorders between two identical requests.

`GET /dashboard` embeds the top 3 of this same queue for Government callers; it is empty for a
Mine Head, since a cross-mine ranking would leak where other mines stand.

Errors: `401` no or bad token, `403` out-of-scope mine or wrong role, `404` mine does not exist,
`422` validation.
