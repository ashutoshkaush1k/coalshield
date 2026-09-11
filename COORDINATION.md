\# Live Coordination — CoalShield Sprint



\## Ground rules

\- Agent 1 owns: backend/ (endpoints, models, sensor pipeline, ML training script)

\- Agent 2 owns: frontend/ (all pages, components, charts)

\- Check this log before editing anything outside your ownership.

\- Commit and push after every working checkpoint, not at the end.

\- Update your section below every time you finish a step or hit a blocker.



\## Agreed data contract (fill in once decided, both agents must follow exactly)

Sensor reading shape: see "Live sensor feed v1" below. Decided by Agent 1, 2026-09-11.
The SHAPE IS FINAL - build against it now. Backend implementation lands in the next pushes
(status in the Agent 1 log). Field names below are exact.

```
LIVE SENSOR FEED v1 - incremental, append-only
==============================================
One "reading" = one tick = gas + dust + temperature for ONE mine at ONE moment.
Timestamps are UTC. `timestamp` = ISO-8601 ending in "Z". `timestamp_ms` = epoch millis
(use timestamp_ms for chart x-axes - no parsing, no timezone surprises).

--- Per-mine feed ---------------------------------------------------------------
GET /api/v1/sensors/{mine_id}/live?after=<cursor>&limit=<N>
  Access : Mine Head = own mine only (other mine -> 403). Government = any mine.
  after  : optional int. OMIT on first load. Afterwards pass back `cursor` from the
           previous response.
  limit  : optional int, default 40, max 500. Max ticks returned (the newest N).

200 ->
{
  "mine_id": 1,
  "cursor": 2670,              // opaque int. Send back as ?after= on the next poll
  "reset": false,              // false => APPEND readings. true => REPLACE your buffer
                               //   (database was reseeded; old points are gone)
  "thresholds": {"gas": 50.0, "dust": 10.0, "temperature": 45.0},
  "units":      {"gas": "ppm", "dust": "mg/m3", "temperature": "C"},
  "anomaly_threshold": 0.55,   // example value - READ IT from the response, don't hardcode
  "readings": [                // oldest -> newest. [] when nothing new since `after`
    {
      "timestamp": "2026-09-11T10:15:02.123456Z",
      "timestamp_ms": 1789121702123,
      "gas": 32.1,             // number, or null if that sensor didn't report this tick
      "dust": 7.4,
      "temperature": 38.2,
      "breached": ["gas"],     // sensor types over threshold this tick (existing rule)
      "anomaly_score": 0.41,   // 0..1, higher = more unusual. null if model unavailable
                               //   or the tick is missing a sensor
      "is_anomaly": false      // anomaly_score >= anomaly_threshold. null when score is null
    }
  ]
}

--- Fleet feed (Government risk view) -------------------------------------------
GET /api/v1/sensors/live?after=<cursor>&limit=<N>&state=<State>
  Access : Government only. Mine Head -> 403.
  limit  : ticks PER MINE, default 10, max 100.
  state  : optional, same values as the existing state filter.

200 -> same envelope as above WITHOUT "mine_id"; every reading ALSO carries
       "mine_id", "code", "name". Readings ordered by (timestamp_ms, mine_id) ascending.

--- Polling protocol (both feeds) ------------------------------------------------
1. First call: no `after`. Draw `readings` as the initial window. Store `cursor`.
2. Every poll: ?after=<cursor>. reset=false -> append (often []). reset=true -> replace.
   Store the new `cursor` either way.
3. Trim the buffer client-side to your window. The server never re-sends old ticks.
Simulator ticks every 6s by default (every 1-2s in rehearsal), so the existing
usePolling cadence is fine.

--- Additive fields on EXISTING endpoints (nothing removed or renamed) ---------------
GET /api/v1/sensors                  top-level "anomalous_mines": int
                                     per mine  "anomaly_score": float|null,
                                               "is_anomaly": bool|null   (latest tick)
GET /api/v1/sensors/{mine_id}        every reading gains "anomaly_score": float|null
GET /api/v1/sensors/{mine_id}/trend  every point gains   "anomaly_score": float|null
```

Role framing: Mine Head "performance" = /sensors/{own_id}/live (+ existing /trend).
Government "risk" = /sensors (table) + /sensors/live (fleet chart) + /sensors/{any_id}/live
for a single-mine drill-down.



\## Agent 1 log

\- \[x] Fleet sensors endpoint — status: DONE (already existed, verified). GET /api/v1/sensors,
  Government-only via RequireGovernment: Gov 200, Mine Head 403, no token 401. Mine Head keeps
  GET /sensors/{own_id} (200) and gets 403 for any other mine. Full backend suite: 213 passed.
  Existing response shape unchanged: {mine_count, breaching_mines, mines:[{mine_id, code, name,
  location, worst_severity, breaching_now, total_open_breaches, sensors:[{sensor_type, unit,
  threshold, value, recorded_at, breached, margin, severity, status_label, open_breaches}]}]}.
  Already consumed by frontend/src/api/sensors.js getFleetSensors().

\- \[ ] Live feed confirmed on existing + new endpoint — status:

\- \[ ] Sensor anomaly model trained — status:

\- \[ ] Raise Alert backend confirmed — status:

\- \[ ] Chart-ready incremental data shape — status:



\## Agent 2 log

\- \[ ] Mine Head sensor view (performance) — status:

\- \[ ] Government sensor view (risk) — status:

\- \[ ] Live polling confirmed, no manual refresh — status:

\- \[ ] Flag for Inspection button confirmed — status:

\- \[ ] Real-time animated charts — status:



\## Blockers / needs from the other agent

(post here, tag who it's for)

\- \[Agent 1 -> Agent 2] FYI, not a blocker: the data contract above is final, so you're unblocked.
  Until the /live endpoints are pushed they will 404/422 - mock the documented shape until the
  Agent 1 log shows "Chart-ready incremental data shape: DONE".

