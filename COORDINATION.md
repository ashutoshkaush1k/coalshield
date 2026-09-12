\# Live Coordination — CoalShield Sprint



\## Ground rules

\- Agent 1 owns: backend/ (endpoints, models, sensor pipeline, ML training script)

\- Agent 2 owns: frontend/ (all pages, components, charts)

\- Check this log before editing anything outside your ownership.

\- Commit and push after every working checkpoint, not at the end.

\- Update your section below every time you finish a step or hit a blocker.



\## Agreed data contract (fill in once decided, both agents must follow exactly)

A. EXISTING ENDPOINTS - what the frontend is built on. CONFIRMED by Agent 1 (see notes below).

Sensor reading shape (Agent 2 read this off the live backend schemas -- Agent 1 please
confirm or correct; frontend is built against exactly this):

GET /sensors/{mine_id}/trend?points=N  -> SensorTrendOut
  { mine_id, series: [ {
      sensor_type: "gas"|"dust"|"temperature",
      unit, threshold, breach_count,
      points: [ { id, mine_id, sensor_type, value, unit, breached, recorded_at } ]  // oldest -> newest
  } ] }

GET /sensors  (Government only, 403 for Mine Head)  -> FleetSensorOut
  { mine_count, breaching_mines, mines: [ {
      mine_id, code, name, location, worst_severity, breaching_now, total_open_breaches,
      sensors: [ { sensor_type, unit, threshold, value, recorded_at, breached,
                   margin, severity, status_label, open_breaches } ]
  } ] }

Two things the charts depend on, please keep them stable:
  1. points[].id is stable and monotonic per reading. The trend charts now APPEND by id
     instead of replacing the dataset each poll, so a changing/absent id would break the
     smooth scroll and make the chart flash again.
  2. points[] stays sorted oldest -> newest, and recorded_at is ISO-8601.
  severity is one of HIGH|MEDIUM|LOW|OK; status_label is one of
  "Breached" | "Approaching limit" | "Within safe range" (the Mine Head view mirrors these
  words exactly, so changing the strings changes the operator-facing copy).

Agent 1 confirmation (2026-09-11) - everything above holds, with these notes:
  - id = sensor_readings primary key. Assigned once at insert, never rewritten, strictly
    increasing. Only `scripts/seed_db.py --reset` restarts it (see Blockers: reload the tab).
  - Order oldest -> newest and the severity / status_label strings are unchanged. Backend
    won't change those strings without posting here first.
  - ONE FORMAT CHANGE: recorded_at is still ISO-8601 but now ends in "Z" (explicit UTC), e.g.
    "2026-09-11T15:04:34.475012Z". It was naive UTC before, which `new Date()` read as local
    time, so every label in IST was 5h30m behind. useLiveSeries' `new Date(p.recorded_at)` is
    now correct as written. Labels move to true local time, and sequence-based x is unaffected.
  - EXTENDED 2026-09-11 (evening): the same "Z" rule now covers EVERY event timestamp, not just
    sensors - alerts `created_at`, directive `resolutions[].created_at` / `resolved_at`, audit
    `created_at`, violations `detected_at` / `resolved_at` (incl. the ones inside
    /vision/analyze), and compliance history `computed_at`. Field names and shapes unchanged.
  - /sensors/breaches `start_ms` windows are now UTC-aligned for real (they were shifted by the
    server's offset). Same field, same 6h size, same IST label set (05h/11h/17h/23h) - readings
    just land in the window they actually happened in.
  - ADDITIVE fields (nothing removed or renamed), from the sensor anomaly model:
      trend points[] and GET /sensors/{id} rows : "anomaly_score": float|null
      GET /sensors                               : top-level "anomalous_mines": int,
                                                   per mine "anomaly_score", "is_anomaly"
  - 2026-09-12, rolling breach window: `compliance.breach_count` (every endpoint that returns a
    score) and dashboard `stats.total_breaches` now count only breaches INSIDE the scoring window,
    not all-time. New additive fields: `compliance.breach_window_hours` and
    `stats.breach_window_hours` (float hours; null = all-time). Sensor response shapes unchanged.

B. LIVE SENSOR FEED v1 - OPTIONAL, additive (Agent 1). Built before Agent 1 could see section A
   (Agent 1's pushes were blocked). The frontend as built (trend + dedupe by id) is correct and
   needs NO change. /live is there if you want smaller polls (idle poll ~215 bytes vs a full
   40-point window) or one row per tick with the anomaly score for a chart.

```
One "reading" = one tick = gas + dust + temperature for ONE mine at ONE moment.
Timestamps are UTC. `timestamp` = ISO-8601 ending in "Z". `timestamp_ms` = epoch millis.

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
  "anomaly_threshold": 0.603,  // READ IT from the response, don't hardcode
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
```

Role framing: Mine Head "performance" = /sensors/{own_id}/trend (or /live). Government "risk" =
/sensors (table) + optionally /sensors/live (fleet chart) and /sensors/{any_id}/live (drill-down).



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

\- \[x] Sensor anomaly model trained — status: DONE. IsolationForest (200 trees, contamination 0.05,
  random_state 26024) trained on the 888 seeded ticks by scripts/train_sensor_model.py, saved to
  backend/ml/weights/sensor_anomaly.joblib (gitignored like ppe.pt; if it's missing the API fits the
  identical model in memory from the seed CSV, so you get the same scores without running anything).
  Threshold = 0.603. Fields populated: /live readings anomaly_score + is_anomaly, envelope
  anomaly_threshold; /sensors per-mine anomaly_score + is_anomaly and top-level anomalous_mines
  (2 of 74 at the seed baseline); /sensors/{id} and /trend rows anomaly_score (all three sensor
  rows of a tick share one score). Strictly additive: breached flags, alerts, compliance scores and
  the fleet sort order are untouched (tests prove it). Typical values: ordinary tick ~0.42, every
  sensor just under its limit ~0.54, 3-sensor breach ~0.72.
  Honest limit: on this synthetic data every flagged tick is also a threshold breach. Read it as
  "how extreme is this tick", not "found something the thresholds missed". Plotting the score as a
  line against anomaly_threshold still gives a graded signal before limits are hit.
  ACTION for Agent 2: `pip install -r backend/requirements.txt` (adds scikit-learn). Without it
  the anomaly fields are null and everything else works. Tests: backend/tests/test_sensor_anomaly.py
  (17). Full suite 251 passed.

\- \[x] Raise Alert backend confirmed — status: DONE, no code change needed. 33/33 directive tests
  pass, plus an end-to-end run on the live server (16/16): POST /api/v1/alerts/directives with ONLY
  {"mine_id": 1} -> 201, alert_type DIRECTIVE, status OPEN, source GOVERNMENT, raised_by the gov
  user; message composed server-side at click time ("Flagged by DGMS Compliance Authority — score 79,
  Medium Risk, 7 breaches open", score matched /mines/1 exactly); severity follows the risk band;
  grid open_alerts 7 -> 8. Mine Head sees it first in GET /alerts; another Mine Head can't see it
  (and gets 403 asking for mine 1); Mine Head raising -> 403; unknown mine -> 404. Resolve with
  proof -> RESOLVED with author, open_alerts back to 7; Government reopen -> OPEN; audit log has
  DIRECTIVE_RAISED / RESOLVED / REOPENED. Matches what Agent 2 saw from the UI side.

\- \[x] Chart-ready incremental data shape — status: DONE. Two routes to append-only charts, both
  supported: (1) Agent 2's existing approach, /trend + dedupe by points[].id, confirmed in contract
  section A (id is the DB primary key, stable and monotonic); (2) the optional /live feeds in
  section B. /live verified on the real seeded DB + simulator process: mine 1 opening window = 14
  complete ticks, oldest->newest; idle poll with cursor = `readings: []` (~215 bytes); after one
  simulator tick the mine poll returns exactly 1 new tick and the fleet poll returns 74 rows (one
  per mine, same timestamp); re-polling with the new cursor returns nothing (no duplicates); a
  stale cursor returns reset=true. UTC fix shipped on every sensor endpoint (see section A).
  Tests: backend/tests/test_live_feed.py (21).

\- \[x] Full-stack walkthrough + 3 fixes (2026-09-11 evening) — status: DONE. Ran backend +
  frontend + simulator together on Naman's laptop and walked all 14 demo steps in the browser
  (Gov overview / drill-down / priority queue / sensors live / trends; Mine Head scoping, 403s,
  live-appending charts, PPE upload, one-click flag, resolve with text+photo, Gov reopen). Every
  step passed; your append-by-id charts held the same DOM nodes across ticks, idle polls redrew
  nothing. It surfaced three backend bugs, now fixed:
    1. Trends windows shifted 5h30m on an IST server: breach_buckets called .timestamp() on the
       naive datetimes SQLite returns, which Python reads as LOCAL time. Evening readings landed in
       the "11h" bar instead of "17h". Fixed in services/iot/fleet_status.py.
    2. Alert / directive / audit / violation times displayed 5h30m early (naive, no Z - same cause
       the sensor fix addressed). Fixed in the alert, audit, violation and compliance schemas.
    3. `scripts/run_simulator.py --loop` stopped after one pass (12 ticks) unless --ticks was also
       given, so a "looping" feed went dead mid-demo. Now runs until Ctrl+C, as documented.
  Tests: backend/tests/test_utc_timestamps.py (6, each fails on the old code) + 4 CLI tests in
  test_iot_simulator.py. Full suite 261 passed. Verified live: directive raised 17:22Z now shows
  "Sep 11 10:52 PM" (was 05:22 PM); Trends narrative now "worst window Sep 11 17h" (was 11h).

\- \[x] Rolling breach window, scores now recover (2026-09-12) — status: DONE. The environmental
  penalty counts only breaches from the last BREACH_WINDOW_HOURS (default 0.01h = 36s = six 6s
  simulator ticks = half a replay pass). PPE violations are unchanged: they still need a clean
  re-inspection. Every simulator tick re-scores every mine and records recoveries on the trend
  line; band changes are audited in both directions. `--loop` no longer grinds mines to zero.
  Seed retuned, as docs/architecture.md always said a window would need: under a short window
  every seeded breach has aged out, which left 0 High mines (avg 91.8). Violations were topped up
  for mines below the Low band -> 6 High / 21 Medium / 47 Low as before, avg 83.2 (was 78.7).
  Named mines now 100 / 80 / 70 / 60 / 45; Jharia has 0 PPE violations (sensor-only). Only
  violations.json changed, purely appended; mines/users/readings are byte-identical.
  E2E on the real stack (backend + frontend + `run_simulator.py --loop`, ~4 min, 37 ticks): Jharia's
  Mine Head dashboard went 100 -> 97 -> 94 -> 91 and back up repeatedly, no reload, no action;
  after Ctrl+C it was at 100 within 38s and the whole fleet back at baseline (avg 83.2, 6 High).
  Tests: backend/tests/test_breach_window.py (18) + 1 drift test. Full suite 280 passed.



\## Agent 2 log

STATUS 2026-09-11, end of Agent 2's sprint: ALL ITEMS DONE, nothing blocked on Agent 1.
Everything below was verified against a running stack, not read off the source. Pulled your
backend work (aca39b1), installed scikit-learn, retrained the model locally (threshold 0.603,
same as yours), restarted both servers clean, and re-checked the whole contract live — no
drift, details in the verification section further down. Latest frontend commit: 888fab5.

\- \[x] Mine Head sensor view (performance) — status: DONE, verified against the running
  stack. /minehead Sensors tab: per-sensor current reading, safe limit, breaches in window,
  and the plain-language status (Within safe range / Approaching limit / Breached) mirroring
  SensorStanding.status_label word for word. Reads GET /sensors/{mine_id}/trend.

\- \[x] Government sensor view (risk) — status: DONE, verified against the running stack.
  /gov Sensors tab: cross-mine table off GET /sensors, sortable by breach severity / breach
  history / mine name, filterable to breaching-now or to one breaching sensor, with the
  "N of M mines breaching right now" read-out. Confirmed live: 74 rows, filter to breaching
  gave exactly the 20 the summary claimed, sort by name reordered correctly.

\- \[x] Live polling confirmed, no manual refresh — status: DONE, both views, measured not
  assumed. Ran the simulator against the live stack and watched the DOM with no reload and no
  click: Government went 20 -> 18 mines breaching and the table re-sorted to a different mine
  at the top; Mine Head appended the new readings to all three charts. Both use the existing
  usePolling hook (5s + refetch on tab focus), no new update mechanism.

\- \[x] Flag for Inspection button confirmed — status: DONE, exercised against the live
  backend. Government -> mine drill-down -> "Flag for inspection". One click, no form: only
  the mine id is sent and the backend composes the message from the mine's current state.
  Four independent confirmation signals, all observed: the button locks to "Flagged v" and
  disables; a "Directive raised" toast quotes the composed message; the alert count moved
  62 -> 63; and "0 open directives" became "1 open directive" with a new From DGMS / Open
  row at the top of the list. The alert list is re-fetched, so that last one proves the
  record actually persisted rather than the button just toggling.

\- \[x] Real-time animated charts — status: DONE, verified against the running stack.
  Charts now APPEND rather than being replaced wholesale each poll. New hook
  frontend/src/hooks/useLiveSeries.js accumulates readings keyed by points[].id and returns
  the SAME array reference when a poll brings nothing new, so React skips the chart
  re-render entirely. The x axis is numeric rather than categorical, which is what gives
  the window a continuous domain to slide along: useSlidingDomain eases it toward the
  newest reading, so the viewport scrolls under a path that is never itself re-animated.
  The Line keeps isAnimationActive={false} deliberately — Recharts' line animation is an
  ENTRY transition and re-runs from scratch on every data change, which is precisely the
  flashing being removed. Y axis is pinned to a rounded ceiling so a steady sensor stops
  looking volatile.

  Points are positioned by ARRIVAL SEQUENCE, not by timestamp. I tried wall-clock first and
  it read terribly: the seeded history is hours apart while the simulator ticks every few
  seconds, so every live reading collapsed into one pixel at the right edge. Sequence
  spaces readings evenly as the old axis did; the ticks still carry real clock times, and
  they gain seconds only when the visible window genuinely holds two readings in the same
  minute. This also fixes a latent bug in the old category axis, which keyed points by
  formatted time — two readings in the same minute shared a category and the later one
  silently replaced the earlier. At demo tick rate that was happening constantly.

  Measured, not assumed: three simulator ticks appended exactly three points with the
  path's DOM node preserved (no re-mount), and four consecutive polls carrying no new
  readings left the path byte-identical.

  Depends on points[].id being stable — Agent 1 has since CONFIRMED and guaranteed this
  (primary key, never rewritten). Nothing outstanding on this item.

\- \[x] Chart survives a database reseed — status: DONE (888fab5), from Agent 1's catch.
  useLiveSeries dedupes by reading id, so after `seed_db.py --reset` restarts ids at 1 the
  ledger recognised every fresh reading as one it had already filed and the chart sat frozen.
  It now self-heals: a batch whose highest id is BELOW one already filed can only mean the
  table was rebuilt, so the ledger is cleared before merging. Verified with the tab open and
  never reloaded — 24 points of simulator data (Sep 11 10:09 PM) became 12 points of fresh
  seed data (Sep 09 01:52 PM) on the next poll, same chart DOM node, and appending resumed
  normally afterwards (12 -> 14 over two ticks). See the Blockers section for one correction
  to your reseed note that is worth reading before demo day.



\## Agent 2 verification of Agent 1's backend (2026-09-11, post-pull aca39b1)

Pulled, installed, restarted both servers, re-checked the contract against the RUNNING API.
Contract sections A and B are ACCURATE as written — no drift found. Evidence:

  A. /sensors/{id}/trend : point keys = id, mine_id, sensor_type, value, unit, breached,
     recorded_at, anomaly_score. ids monotonic oldest->newest, recorded_at ascending, all
     ending "Z". A/sensors (gov) : anomalous_mines present (7 of 74 here), per-mine
     anomaly_score + is_anomaly present, sensor keys unchanged. status_label observed in the
     wild = exactly {Within safe range, Approaching limit, Breached}; severity = {OK, LOW,
     MEDIUM, HIGH}. Mine Head on /sensors -> 403.
  B. /sensors/{id}/live : envelope = cursor, reset, thresholds, units, anomaly_threshold,
     mine_id; anomaly_threshold 0.603, matching the model I trained locally (same seed, so it
     reproduces exactly). Idle re-poll with the cursor returned readings: [] as documented.
     /sensors/live (gov) rows carry mine_id/code/name and are ordered by (timestamp_ms,
     mine_id); Mine Head -> 403.

The UTC fix is confirmed correct end to end, not just in the payload: API said
16:32:23Z, this browser is UTC+5:30, and the UI rendered "Sep 11 10:02 PM". Before the fix
that same reading would have displayed as 4:32 PM. Thanks — that was a real bug and it was
silently wrong in my charts.

The additive anomaly fields did not disturb the frontend: it ignores unknown keys, and
append-by-id still works against the new payload (12 -> 15 points over three simulator ticks
with the chart's DOM node preserved, i.e. no re-mount). No console errors in a clean tab.
Both sensor views verified against the restarted stack.

Not adopting /live for now: the frontend already appends correctly off /trend, and swapping
the transport mid-sprint would risk a working demo path for a payload-size win that does not
show on stage. Noted as the better option if we need the fleet chart or the anomaly line.

Environment note for whoever sets up next: on Python 3.14 the full requirements.txt installs
cleanly (scikit-learn 1.9.1, and torch/opencv now have 3.14 wheels) but pulls ~220 MB, so it
takes several minutes with no output. Nothing is wrong; let it run.

FIXED — @Agent 1's reseed trap. useLiveSeries now self-heals: ids only ever increase against
a live database, so a batch whose highest id is BELOW one already filed means the table was
rebuilt, and the ledger is cleared before the merge. The x sequence deliberately keeps
counting, so the window carries on instead of lurching back across thousands of positions.
Verified end to end with the tab open and never reloaded: 24 points of simulator data
(Sep 11 10:09 PM) became 12 points of fresh seed data (Sep 09 01:52 PM) on the next poll,
same chart DOM node throughout, and appending resumed normally (12 -> 14 after two ticks).
Counterfactual confirmed: the post-reseed ids were 157-168, every one already in a ledger
that held ids up to 5328, so without the fix all twelve would have been skipped as duplicates.

@Agent 1 — ONE CORRECTION to your note, worth knowing before demo day. With the API running,
`seed_db.py --reset` does not merely freeze an open tab: it LOGS IT OUT. drop_all() removes
the users table, a poll lands in that window, get_current_user returns 401 "Account no longer
exists", and client.js clears the token and redirects to /login. I reproduced it. So the
advice is really "sign in again", not "reload the tab", and the frozen-chart case only
arises when the session survives the reseed — a backgrounded tab whose polling is throttled
past the drop window, or a stack restart (network errors don't clear the token, only a 401
does). That is the path I used to verify the fix. Not proposing a change: nobody reseeds
mid-demo on purpose, and being returned to the login screen is at least honest about what
happened. Flagging it so it isn't a surprise on the day.



\## Blockers / needs from the other agent

=== @Agent 1 / @Naman — AGENT 2 IS DONE. NOTHING IS BLOCKED ON YOU. (2026-09-11) ===

CONFIRMED (78179ec): your timezone fix across alerts, directives, audit and violations renders
correctly, and you were right that no frontend change was needed — fmtDateTime/fmtTime already
go through `new Date()`, which reads the "Z" properly. Checked on the running stack after
pulling and restarting the API:
  - alert created_at 2026-09-11T16:41:07Z renders "Sep 11 10:11 PM" — exact match against
    locale formatting, i.e. the +5:30 shift is applied once and only once.
  - directive raised through the UI and timed: API said 17:45:24Z, the row read "Sep 11
    11:15 PM", and the record was 12 SECONDS old. Before the fix that would have displayed
    5h30m in the past. Flag for Inspection still works end to end post-pull.
  - audit rows render consistently with the alerts they describe; violations detected_at is
    Z-suffixed.
  - Trends renders on both roles. Your fleet_status.py key_of() fix is visible in the data:
    every 6-hour bucket now starts exactly on a UTC edge (verified start_ms %% bucket == 0 for
    all 21 buckets), which it would not have been when a naive timestamp was read as local.

Two cosmetic things, neither blocking and neither caused by your change:
  - Bucket labels print getHours(), so a bucket on the 00:00 UTC edge reads "05h" here rather
    than 05:30. Truncation, not a wrong bucket. Only visible in a half-hour-offset zone.
  - frontend/src/components/charts/ComplianceTrendChart.jsx is a 32-byte stub containing only
    a comment and is imported nowhere. Mine to delete; flagging so it isn't mistaken for a
    missing feature.

All five of my sprint items plus the reseed fix you caught are finished, pushed, and verified
against a live stack. Frontend is at 888fab5 on live-sprint.

What I needed from you is closed: the data contract is confirmed accurate (I re-checked both
sections against the running API after pulling aca39b1 — no drift), and points[].id is
guaranteed stable, which is what the charts append by.

Three things from me that are worth your time, in order:
  1. The UTC fix was a genuine bug on my side and it was silently wrong — my charts had been
     labelling every reading 5h30m early in IST. Verified corrected end to end: API 16:32:23Z
     renders as "Sep 11 10:02 PM" here. Thank you for catching it.
  2. Your reseed note needs one correction before demo day — see the FIXED entry below. Short
     version: with the API running, `seed_db.py --reset` doesn't just freeze an open tab, it
     LOGS IT OUT (401 "Account no longer exists"), so the advice is "sign in again", not
     "reload the tab". I did not change that behaviour.
  3. I am NOT adopting the /live feeds for now, and that is not a criticism of them — the
     frontend already appends correctly off /trend, and swapping transport this late risks a
     working demo path for a payload win that does not show on stage. They are the right call
     if we add the fleet chart or plot the anomaly line against anomaly_threshold.

No asks outstanding. If you change any sensor response shape from here, post it above first —
the charts are built against the contract exactly as written.

RESOLVED — the push problem below is fixed, no action needed. `pancholiyug21-cmyk` now has
write access and all five commits are on origin/live-sprint (through 90b21cb). Leaving the
note in place only so the history makes sense if you read this file top to bottom.
  (was: push rejected 403, this laptop's GitHub account was not a collaborator on the repo.)

@Agent 1 — nothing blocking me on your side. Two asks, neither urgent:
  1. Confirm the data contract above. The frontend is built against it exactly as written.
  2. Keep `points[].id` stable and monotonic. The trend charts now append by it; if it ever
     changes per response every poll will look like brand new data and the charts will go
     back to redrawing on every tick.

FYI, not a blocker: running `scripts/run_simulator.py --loop` grinds every mine's score to 0,
as its own docstring warns. I did that while testing, so my local DB is re-seeded; if your
scores look flattened, re-run `scripts/seed_db.py`.

\- \[Agent 1 -> Agent 2] Answers to both asks: (1) CONFIRMED, see the Agent 1 notes under contract
  section A. (2) GUARANTEED: points[].id is the primary key, stable and monotonic.
  One demo-day trap that follows from (2): `seed_db.py --reset` restarts ids, so a dashboard tab
  left open across a reseed already holds "id:2665..." from the earlier simulator run, and your
  ledger will treat the fresh post-reseed readings as duplicates until ids pass the old maximum
  (the charts look frozen). Reload the tab after any reseed. If you want it automatic, /live
  returns reset=true in exactly that case.
  Also: after you pull, `pip install -r backend/requirements.txt` for scikit-learn (optional,
  anomaly fields are null without it) and expect the sensor time labels to move by +5:30. That's
  the UTC fix making them correct, not a regression.

\- \[Agent 1 -> humans] Agent 1's laptop had no GitHub credentials for this remote, so Agent 1's
  commits sat local until someone signed in on Naman's laptop. If Agent 2 is reading this, that
  is resolved.

\- \[Agent 1 -> Agent 2] FYI, NO ACTION NEEDED (2026-09-11 evening). The timestamp fix now covers
  alerts, directives (and their resolutions), audit trail and violations, in addition to sensors -
  see the EXTENDED note under contract section A. After you pull and restart the backend, expect
  alert-feed, directive-drawer, audit-trail and PPE-violation times to move +5:30 to the correct
  local time; your fmtDateTime/new Date() calls are right as written, so please don't add an
  offset anywhere. The Trends chart keeps its labels but the evening spike moves from the
  "Sep 11 11h" bar to "17h", where it belongs. Also `run_simulator.py --loop` now genuinely loops
  until Ctrl+C (it silently stopped after 12 ticks before) - every pass lowers scores, so reseed
  before the demo. And thanks for the reseed correction: noted that it logs the tab out (401),
  so the advice is "sign in again".

\- \[Agent 1 -> Agent 2] FYI, NO FRONTEND CHANGE REQUIRED (2026-09-12). Compliance scores now go UP
  as well as down: sensor breaches age out of a rolling window (36s by default), so the numbers you
  already poll climb back on their own - nothing to wire up. Two things you will notice:
  (1) the "Breaches" stat on the score cards, the formula line and the Overview tally now mean
  "breaches inside the window", so they sit at 0 on a fresh seed and rise and fall with the
  simulator. If you want copy for that, `compliance.breach_window_hours` /
  `stats.breach_window_hours` (new, additive) give the window in hours (0.01 = 36s).
  (2) The clean baseline changed: national avg 83.2 (was 78.7), named mines 100/80/70/60/45.
  The Sensors-tab history (open_breaches, Trends) is unchanged - still every breach on record.
  After pulling: `python scripts/seed_db.py --reset` and restart the backend (sign in again).

\- \[Agent 1 -> Agent 2] Calmer live feed (2026-09-12), no frontend change, no API shape change.
  The replayed telemetry is now thinned to about a quarter of each mine's seeded record (capped at
  3), so a mine breaches once or twice per 12-tick pass instead of constantly. The opening board is
  IDENTICAL (avg 83.2, 6 High / 21 Medium / 47 Low, named mines 100/80/70/60/45) - only the live
  behaviour changes: the board mostly sits at baseline and dips when a site actually has a problem,
  then climbs back. Defaults now pair up: simulator `--interval 2` with BREACH_WINDOW_HOURS=0.0033
  (12s = six ticks), and `run_all.bat --sim` runs `--interval 2 --loop`. Re-seed after pulling
  (`python scripts/seed_db.py --reset`) - sensor_readings.csv changed.

\- \[Agent 1 -> Agent 2] Housekeeping (2026-09-12), no action needed, both at the owner's request.
  (1) The local editor/tooling config folder at the repo root is no longer tracked - it is in
  .gitignore now. Your local copy is untouched and keeps working; nothing in backend/ or frontend/
  reads it, so pulling changes nothing for you.
  (2) I edited ONE COMMENT LINE each in frontend/src/styles/index.css and theme.css: they pointed
  at that folder's path, and now just say the styles follow the shared frontend design rules.
  Comment text only - no selectors, tokens, imports or logic touched. Flagging it because
  frontend/ is yours.
