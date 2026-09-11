\# Live Coordination — CoalShield Sprint



\## Ground rules

\- Agent 1 owns: backend/ (endpoints, models, sensor pipeline, ML training script)

\- Agent 2 owns: frontend/ (all pages, components, charts)

\- Check this log before editing anything outside your ownership.

\- Commit and push after every working checkpoint, not at the end.

\- Update your section below every time you finish a step or hit a blocker.



\## Agreed data contract (fill in once decided, both agents must follow exactly)

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



\## Agent 1 log

\- \[ ] Fleet sensors endpoint — status:

\- \[ ] Live feed confirmed on existing + new endpoint — status:

\- \[ ] Sensor anomaly model trained — status:

\- \[ ] Raise Alert backend confirmed — status:

\- \[ ] Chart-ready incremental data shape — status:



\## Agent 2 log

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

\- \[x] Real-time animated charts — status: DONE (code). Charts now APPEND instead of
  being replaced each poll. New hook frontend/src/hooks/useLiveSeries.js accumulates
  readings by points[].id and returns the SAME array reference when a poll brings nothing
  new, so React skips the chart re-render entirely. X axis is now a real numeric time axis
  whose domain eases to the newest reading (useSlidingDomain), so the window scrolls rather
  than the path being redrawn — Line stays isAnimationActive={false} on purpose, because
  Recharts' line animation is an ENTRY transition and re-runs from scratch on every data
  change, which is exactly the flashing we were removing. Y axis is pinned to a rounded
  ceiling so a steady sensor stops looking volatile. AGENT 1: this depends on points[].id
  being stable — see the contract section.



\## Blockers / needs from the other agent

@Agent 1 / @Naman — I CANNOT PUSH. `git push` to ashutoshkaush1k/coalshield is rejected:
this laptop is authenticated as GitHub user `pancholiyug21-cmyk`, which is not a collaborator
on that repo (403, and the token already carries full `repo` scope, so it is a repo-permission
problem and not a scope one). Four commits are sitting on `live-sprint` locally and none of
them have reached the remote, so you are not seeing any of this work yet. Whoever owns the
repo needs to add `pancholiyug21-cmyk` as a collaborator, or tell me a fork/remote to push to.
I am continuing to commit locally in the meantime.

@Agent 1 — nothing blocking me on your side. Two asks, neither urgent:
  1. Confirm the data contract above. The frontend is built against it exactly as written.
  2. Keep `points[].id` stable and monotonic. The trend charts now append by it; if it ever
     changes per response every poll will look like brand new data and the charts will go
     back to redrawing on every tick.

FYI, not a blocker: running `scripts/run_simulator.py --loop` grinds every mine's score to 0,
as its own docstring warns. I did that while testing, so my local DB is re-seeded; if your
scores look flattened, re-run `scripts/seed_db.py`.

