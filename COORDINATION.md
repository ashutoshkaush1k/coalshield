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

\- \[x] Live feed confirmed on existing + new endpoint — status: DONE. Real 3-process run (uvicorn +
  scripts/run_simulator.py + HTTP client, sharing only the SQLite file), 2 ticks, 7/7 checks:
  fleet GET /sensors (Gov) shows the tick's values with a fresh recorded_at; Mine Head
  GET /sensors/1 and /sensors/1/trend show the new rows; Gov /dashboard total_breaches 322->359
  and Mine Head /dashboard 4->5, both exactly what the CSV predicts; Mine Head still 403 on
  /sensors. No cache anywhere - each poll reads the DB. Regression tests: backend/tests/test_live_feed.py.
  HEADS-UP for Agent 2: existing endpoints send recorded_at as naive UTC ("2026-09-11T14:58:35.708274",
  no Z). `new Date()` reads that as LOCAL time, so charts in IST are 5h30m behind today. I'm fixing
  it server-side for the sensor endpoints in the task-3 push (they'll end in "Z"). Your
  fmtTime/new Date() code will then be correct with no change. Don't add a +5:30 workaround.

\- \[x] Sensor anomaly model trained — status: DONE. IsolationForest (200 trees, contamination 0.05,
  random_state 26024) trained on the 888 seeded ticks by scripts/train_sensor_model.py, saved to
  backend/ml/weights/sensor_anomaly.joblib (gitignored like ppe.pt; if it's missing the API fits the
  identical model in memory from the seed CSV, so you get the same scores without running anything).
  Threshold = 0.603. All contract fields now populated: /live readings anomaly_score + is_anomaly,
  envelope anomaly_threshold; /sensors per-mine anomaly_score + is_anomaly and top-level
  anomalous_mines (2 of 74 at the seed baseline); /sensors/{id} and /trend rows anomaly_score
  (all three sensor rows of a tick share one score). Strictly additive: breached flags, alerts,
  compliance scores and the fleet sort order are untouched (tests prove it). Typical values:
  ordinary tick ~0.42, every sensor just under its limit ~0.54, 3-sensor breach ~0.72.
  Honest limit: on this synthetic data every flagged tick is also a threshold breach. Read it as
  "how extreme is this tick", not "found something the thresholds missed". Plotting the score as a
  line against anomaly_threshold still gives a graded early signal before limits are hit.
  ACTION for Agent 2: `pip install -r backend/requirements.txt` (adds scikit-learn). Without it
  the anomaly fields are null and everything else works. Tests: backend/tests/test_sensor_anomaly.py
  (17). Full suite 251 passed.

\- \[ ] Raise Alert backend confirmed — status:

\- \[x] Chart-ready incremental data shape — status: DONE, implemented exactly as the contract above.
  GET /api/v1/sensors/{mine_id}/live and GET /api/v1/sensors/live are live. Verified on the real
  seeded DB + simulator process: mine 1 opening window = 14 complete ticks, oldest->newest; idle
  poll with cursor = `readings: []` (~215 bytes); after one simulator tick the mine poll returns
  exactly 1 new tick and the fleet poll returns 74 rows (one per mine, same timestamp); re-polling
  with the new cursor returns nothing (no duplicates); a stale cursor returns reset=true.
  anomaly_score / is_anomaly / anomaly_threshold are null until the model lands (next item).
  UTC fix shipped: recorded_at on /sensors, /sensors/{id} and /sensors/{id}/trend now ends in "Z"
  (e.g. "2026-09-11T15:04:34.475012Z"), so mixing /trend history with /live appends lines up.
  Tests: backend/tests/test_live_feed.py (21). Full suite 234 passed.



\## Agent 2 log

\- \[ ] Mine Head sensor view (performance) — status:

\- \[ ] Government sensor view (risk) — status:

\- \[ ] Live polling confirmed, no manual refresh — status:

\- \[ ] Flag for Inspection button confirmed — status:

\- \[ ] Real-time animated charts — status:



\## Blockers / needs from the other agent

(post here, tag who it's for)

\- \[Agent 1 -> Agent 2] RESOLVED: the /live endpoints are implemented (see Agent 1 log). Pull, then
  restart your backend (uvicorn --reload picks up the changes on its own).

