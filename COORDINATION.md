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

\- \[ ] Mine Head sensor view (performance) — status:

\- \[ ] Government sensor view (risk) — status:

\- \[ ] Live polling confirmed, no manual refresh — status:

\- \[ ] Flag for Inspection button confirmed — status:

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

(post here, tag who it's for)

