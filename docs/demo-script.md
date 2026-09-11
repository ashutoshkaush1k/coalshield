# Demo script (PRD Section 6)

Target: the multi-mine comparison story, since PRD Section 8 names it the primary judge-facing
scenario.

---

## READ THIS FIRST: re-seed before every run

**Scores fall and recover.** A sensor breach counts against its mine only inside a rolling window
(`BREACH_WINDOW_HOURS`, 36 s by default), so the simulator pulls scores down and they climb back
on their own as breaches age out. A PPE violation still counts until a clean re-inspection
resolves it (see `docs/architecture.md`).

The consequence you must plan around:

> **PPE violations from a CV demo or a rehearsal persist. A second run starts from a board that
> already carries them and will not match this script.** Simulator breaches clear themselves
> within about half a minute; violations do not.

### The rule

```bash
python scripts/seed_db.py --reset
```

Run it **immediately before every demo, every rehearsal, and every practice run.** It takes about
a second. Treat it as part of powering on the laptop.

### The safety net

`run_simulator.py` pre-flights the database and prints a loud banner if scores have drifted from
the clean baseline:

```
==============================================================================
  WARNING: SCORES DO NOT MATCH THE CLEAN BASELINE
==============================================================================
  MINE                   SCORE       PPE    BREACHES
  MP-SGR-02           80 -> 75        +1           -  LOW -> MEDIUM
  ...
  FIX BEFORE DEMOING:  python scripts/seed_db.py --reset
==============================================================================
```

Three modes:

| Command | Behaviour | Use it for |
|---|---|---|
| `run_simulator.py --check-only` | Reports and exits. Exit 0 clean, 1 drifted. | Pre-demo check, CI |
| `run_simulator.py --require-clean` | Refuses to start if drifted. | Rehearsal runs |
| `run_simulator.py` | Warns loudly, then continues. | **The live demo** |

The default warns rather than blocks on purpose: step 3 below runs the CV demo *before* the
simulator, so by step 4 the database has legitimately drifted. A blocking check would break the
very demo it is meant to protect. Use `--require-clean` when you want the hard gate.

---

## Before the room

- [ ] **`python scripts/seed_db.py --reset`** (required after pulling the directives change -
      SQLite cannot add the new alert columns to an existing database)
- [ ] Have a photograph ready to attach as resolution proof, and know its path — the board must read 100 / 80 / 70 / 60 / 45
- [ ] `python scripts/run_simulator.py --check-only` — must print `Pre-flight : OK`
- [ ] **Double-click `run_all.bat`** - it pre-flights the setup, opens `SIH-Backend` and
      `SIH-Frontend` windows, waits for both ports, and opens the dashboard. It warns loudly if
      a previous stack is still holding a port, or if the database is missing.
- [ ] `http://localhost:8000/docs` reachable and `http://localhost:5173` shows the login page
- [ ] `backend/ml/weights/ppe.pt` present (`demo_vision.py --dry-run` shows `backend : yolo`)
- [ ] Sample images in `backend/data/samples/images/`
- [ ] **Two browser tabs** pre-opened and signed in — Government in one, Mine Head
      (Singrauli) in the other. Sessions are per-tab, so both stay signed in at once.
      Arrange them side by side if the projector allows: the live hand-off in step 3 is
      far stronger when both boards are visible at the same time.
- [ ] A sample image ready to pick in the file dialog — know the path before you are on stage
- [ ] A terminal ready with the re-seed command already typed, not yet run

Clean baseline: **74 mines across 10 states**, national average **83.2**.

The Overview board defaults to the five highest-risk mines nationally, not all 74. The five
original named mines are still in the dataset under their real states. With the rolling breach
window their opening scores come from open PPE violations alone (the seeded readings are days
old), retuned to keep the same bands:

| Mine | District, State | Score | Risk |
|---|---|---|---|
| JH-DHN-01 Jharia | Dhanbad, Jharkhand | 100 | LOW (green) - no PPE violations; moves only with its sensors |
| MP-SGR-02 Singrauli | Singrauli, Madhya Pradesh | 80 | LOW (green) - one detection tips it to Medium |
| CG-KRB-03 Korba | Korba, Chhattisgarh | 70 | MEDIUM (yellow) |
| WB-RNG-04 Raniganj | Raniganj, West Bengal | 60 | MEDIUM (yellow) |
| OD-TLC-05 Talcher | Angul, Odisha | 45 | HIGH (red) |

If the national average does not read 83.2 before the simulator starts, **re-seed before
continuing.**

---

## Run of show

1. **Government login** (`gov@dgms.gov.in`) → national overview at `/gov`. The headline
   numbers cover all 74 monitored mines; the core sample board shows the **five highest-risk
   mines nationally**, each labelled with its district so it is obvious where the worst risk
   sits. The page refreshes itself, so nothing needs reloading on stage.

   **Then use the Region dropdown** on the Core Sample Board. Pick Jharkhand: the board fills
   with all 13 of that state's mines, and every number above it rescopes - the average drops
   from 83.2 national to 78.1 for Jharkhand. The selection follows you to the Priority Queue,
   Sensors and Trends tabs, so an official working one region never has to reselect it.
2. **Inspection priority** → `/gov/inspections`. All five ranked most urgent first, each with
   plain-language reasoning and a trend arrow. Talcher is #1.
3. **CV demo — in the product, not a terminal.** Switch to the **Mine Head tab** (Singrauli,
   `head.mp-sgr-02@coalmine.in`) and use the **PPE detection** panel: choose
   `backend/data/samples/images/metro_shaft_workers.jpg` and press *Run PPE detection*.

   Real YOLO inference on a real photograph. The panel shows the annotated frame with the
   violation boxed in red, and the score move **80 → 75, LOW → MEDIUM**.

   Now switch back to the **Government tab without reloading it**: Singrauli has already turned
   yellow and the fleet counts have moved. That hand-off is the strongest moment in the demo —
   an operator uploads footage, and the authority's board changes on its own within ~5 seconds.

   (`python scripts/demo_vision.py` still works and is the fallback if the browser misbehaves.)
4. **IoT simulator** → `python scripts/run_simulator.py --loop`
   (keep the default 6 s ticks - the breach window is tuned for them.) Live sensor telemetry.
   Breaches raise alerts and pull scores down tick by tick, and each one stops counting 36 s
   later, so mines dip and climb back on their own. Watch **Jharia** on a Mine Head tab: it has no
   PPE violations, so every move is its air - down on a breach, back up as the breach ages out,
   with nobody touching anything. The terminal marks those ticks `(older breaches aged out)`.
   Press Ctrl+C and the whole board is back at baseline within about half a minute. Expect the
   pre-flight banner here — step 3 already moved Singrauli, which is exactly the drift it reports.
5. **Drill down** → click any tile. Score with the formula shown, active alerts, three sensor
   charts with dashed limit lines and red breach markers, PPE violation log, and audit trail.
6. **Back to inspection priority** → the moved mine has climbed the ranking. Closes the loop from
   detection to authority action.
7. **Sensors tab (Government)** → the risk view. Which mines are breaching *right now*,
   sortable by severity and filterable by sensor, with breach counts split by gas / dust /
   temperature so "this mine's problem is mostly gas" is readable at a glance. Distinct from the
   Overview board, which shows accumulated score rather than current condition.

8. **Raise a directive** → open any mine's drill-down and use **Raise alert**. This is a
   Government instruction to that operator, not an automated finding, and it appears on their
   dashboard tagged *From DGMS*.

9. **Log out, Mine Head login** → own mine only. No grid, no comparison, no Priority Queue tab.
   Their **Sensors** tab is the same data framed as performance: current reading per sensor with
   a plain-language status.

10. **Close the loop** → the directive is waiting on their Overview. Resolve it with a written
    corrective action and a photograph. Switch back to the Government tab without reloading: the
    resolution and its proof are there, with a **Reopen** control if the evidence is not good
    enough. Reopening keeps the earlier attempt, so both submissions stay comparable.
11. **If a judge asks about access control**, do not go looking for it in the UI - there is
    deliberately nothing to click. A Mine Head simply never sees another mine, which is the
    point. Prove it one of two ways:

    ```bash
    backend\.venv\Scripts\python.exe -m pytest backend/tests/test_access_control.py -v
    ```

    That sweeps every mine-scoped route and asserts **403**, not a filtered empty list - an
    empty list would read as a mine with no findings, which is the most dangerous possible
    wrong answer here.

    Or call the API directly with a Mine Head token and show the refusal:

    ```bash
    curl -i -H "Authorization: Bearer <mine head token>" http://localhost:8000/api/v1/mines/1
    ```

**After the run — and before the next one — re-seed.**

---

## If a judge asks "how does a mine get its score back?"

Two ways, on purpose:

> "Environmental breaches are conditions, so they age out: a breach counts only inside a rolling
> window, and once the air has been clean for the whole window the penalty is gone - you just
> watched Jharia do that. PPE violations are findings about how people were working, so they
> stay until a clean re-inspection resolves them. Nothing is ever deleted; every breach and
> violation is still in the log and the audit trail. The demo window is 36 seconds so you can see
> it happen; a real deployment would set it in hours."

---

## Open questions to settle before the demo (PRD 8.1)

- Recorded video over live feed — recorded is the safer bet on venue hardware; decide and lock it.
- Final `weight_ppe` / `weight_env` values. Note that retuning changes the baseline table above,
  so regenerate the seed and update this document together.
- Number of mines on the grid — five reads well; three bands are visible at once.
