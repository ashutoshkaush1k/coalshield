# Architecture

## Layering

```
   React pages ──► src/api/*  ──HTTP──►  api/v1/endpoints/*
                                              │
                                         api/deps.py        (identity + mine scope)
                                              │
                                         services/*         (all business logic)
                                              │
                                         models/*  ──►  SQLite
```

Rules that keep this honest:

1. **Endpoints hold no business logic.** They validate input, call one service, and shape the response.
2. **Services never import FastAPI.** That makes scoring, thresholds, and access rules unit-testable
   without spinning up the app.
3. **Every read of mine-owned data goes through the scope guard.** No endpoint queries a mine table
   directly with a client-supplied `mine_id`.

## Data flow (PRD Section 6)

1. `POST /vision/analyze` receives an image or video →
   `services/vision/detector` runs the pretrained YOLO model →
   `ppe_rules` maps classes to violation types → `Violation` rows written,
   annotated frame saved to `storage/annotated/`.
2. `services/iot/simulator` replays `data/seed/sensor_readings.csv` on a timer →
   `thresholds` classifies each reading → breaches become `SensorReading` rows with `breached=True`.
3. Both paths call `services/alerts/engine`, which creates an `Alert` and calls
   `services/audit/recorder` so nothing enters the system unlogged.
4. `services/compliance/scoring` recomputes the mine's score and writes a `ComplianceScore`
   row, preserving the trend line.
5. Dashboards read the current score, risk band, alerts, and trends — Government across all
   mines, Mine Head for one.

## Why the score is recomputed, not incremented

Storing a running score would make it impossible to explain a number to a judge or to retune
`weight_ppe` / `weight_env` mid-demo. Recomputing from the violation and breach counts means a
weight change takes effect on the next tick and the arithmetic stays inspectable.

## Scores recover: violations through a clean re-inspection, breaches by ageing out

`compute_compliance_score` counts **open** violations and **open, in-window** breaches. A record is
never deleted; a resolved violation sets `resolved` plus `resolved_at`, and an aged-out breach simply
falls outside the window, so both stop counting against the score while staying visible in the logs,
the charts and the audit trail.

This partially lifts the limitation previously recorded here, that a score could only ever fall
and a mine that fixed a problem carried it forever.

### What can resolve a violation

Exactly one thing today: a **clean vision re-run** on that mine.
`services/compliance/resolution.py` accepts a detection run as evidence of compliance when it
produced zero violations **and** the model actually saw a workforce - at least one `person` or one
worn PPE item. Every open violation for the mine is then marked resolved, each gets a
`corrective_actions` row naming the evidence frame, and an `VIOLATIONS_RESOLVED` audit entry is
written. The score is recomputed in the same request.

The workforce check is not incidental. Zero violations on its own is not evidence of anything - a
photograph of an empty corridor also contains zero violations. Without that guard, any image at all
would clear a mine's history.

### How environmental penalties recover: the rolling breach window

**Breaches are not resolved, they age out.** `_breach_counts` counts only breaches recorded inside
the window (`BREACH_WINDOW_HOURS`), measured against the wall clock the readings are stamped with.
This is the "sustained clean window" this section used to say was missing: one clean reading clears
nothing, because the penalty only falls as each breach in the window expires. A mine whose sensors
have run clean for the whole window has no environmental penalty left, and its score is back where
its open PPE violations put it - with no resolve action and nothing deleted.

**Violations are deliberately not windowed.** A PPE violation is a finding about how people were
working, not a passing condition, so it still counts until a clean re-inspection resolves it.

**Every simulator tick re-scores every mine**, breaching or not, and writes a history point on any
movement, so recoveries reach the trend line and band changes are audited in both directions. The
dashboards recompute on every poll, so they show a recovery even between ticks and after the feed
stops. `--loop` no longer grinds mines to zero: the environmental penalty is capped at one window's
worth of breaches.

**The default is 0.0033 h (12 s), tuned for the demo.** The simulator stamps readings with real time
and replays one 6-hour seed slot per tick, so there is no "simulated hour" to measure in: two
replayed hours would be a fraction of a tick. 12 s is six ticks at the default 2-second interval -
half a replay pass, which maximises the visible rise-and-fall while looping (a window of a whole
pass holds an almost constant count). Keep it at about six ticks if `--interval` changes:
6s -> 0.01, 2s -> 0.0033, 1s -> 0.0017. Production would use hours; `BREACH_WINDOW_HOURS=0`
restores all-time counting.

**The seed was retuned in the same change**, as this note always said it would need to be. Every
seeded reading is days old, so under any short window the opening board is set by violations alone,
which left no red on it (avg 91.8, 0 High / 6 Medium / 68 Low). `scripts/generate_sensor_data.py`
now re-expresses the historical breach penalty of every mine below the Low band as extra open
violations, putting each back within 2 points of its old score: 6 High / 21 Medium / 47 Low as
before, avg 83.2. The five named mines are set by hand; Jharia keeps zero violations as the
sensor-only mine whose score moves purely with its air.

**The replayed feed is thinner than the record.** A mine's seeded breach count describes its
three-day record and sets that baseline; the telemetry the simulator replays is thinned to roughly a
quarter of it, capped at three (`live_breach_count`). A site that breached a dozen times over three
days is not breaching every few seconds, and a feed where nearly every tick is red both reads as a
broken sensor and buries the recovery - a mine never gets a clean stretch long enough for its
breaches to age out. Thinned, a mine dips once or twice per 12-tick pass and sits at baseline in
between.

`SensorReading.resolved` stays, so an explicit inspector sign-off can be added later without the two
penalties drifting apart.

### The simplification worth knowing about

**A mine head can raise their own score by uploading a compliant photograph.** The evidence guard
stops an empty frame from working, but it does not verify that the image is recent, is of that
mine, or is of the same area where the violation was found. Someone could photograph a compliant
crew and clear a genuine finding.

That is acceptable for Round 3 - it makes the recovery path demonstrable, and the full history
stays in the audit trail for an authority to review - but it is a real gap, not an oversight.
Closing it properly means tying evidence to the violation it claims to answer: capture location and
timestamp metadata, require the resolving frame to post-date the finding, and put resolution behind
an inspector role rather than the mine operator's own upload.

## Real-time

`realtime/ws.py` pushes new alerts and sensor ticks to open dashboards. `hooks/usePolling.js`
exists as the fallback — if the websocket is flaky at the venue, dashboards still refresh on an
interval, so the demo degrades rather than breaks.
