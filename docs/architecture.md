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

## Scores can now recover, but only through a clean re-inspection

`compute_compliance_score` counts **open** violations and **open** breaches. A record is never
deleted; resolving one sets `resolved` plus `resolved_at` so it stops counting against the score
while staying visible in the violation log and the audit trail.

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

### What this deliberately does not do

**Breaches are structurally resolvable but nothing resolves them.** `SensorReading` carries the
same two fields and `_breach_counts` already filters on them, so the two penalties cannot drift
apart. No code sets the flag, so environmental penalties behave exactly as before. That is on
purpose: sensor readings arrive continuously and mostly clean, so "one clean reading clears the
history" would erase the environmental penalty almost immediately and make the IoT half of the
demo meaningless. Clearing a breach history needs a deliberate signal - an inspector sign-off, or a
sustained clean window - and neither exists yet.

**There is no rolling time window.** A violation from last month still counts until something
resolves it. A window (`score_mine(since=...)`) remains the more complete answer and is still
unimplemented, for the reason recorded before: the seeded scores are tuned against all-time
counting, so a window changes every one of them and needs the seed generator retuned in the same
change.

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
