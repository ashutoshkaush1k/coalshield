# Demo script (PRD Section 6)

Target: the multi-mine comparison story, since PRD Section 8 names it the primary judge-facing
scenario. Stack: Yii2 API (port 8080) + PostgreSQL, ai-service (8001) for PPE vision, React (5173).
Every score on screen is a **demo value** computed from synthetic data (`data/DATASETS.md`) - the
UI says so next to each one. Mine names and locations are real (Global Energy Monitor, Global
Coal Mine Tracker, August 2026, CC BY 4.0); the numbers attached to them are not.

---

## READ THIS FIRST: re-seed before every run

**Scores fall and recover.** A sensor breach counts against its mine only inside a rolling window
(`BREACH_WINDOW_HOURS`, 12 s by default - six ticks at the simulator's 2 s interval), so the
simulator pulls scores down and they climb back on their own as breaches age out. A violation
counts until it is resolved: by a corrective action closed with proof, or - for PPE findings - by
a clean re-inspection frame.

> **Violations, directives and resolutions from a rehearsal persist. A second run starts from a
> board that already carries them and will not match this script.** Breaches clear themselves
> within about 12 seconds; the rest does not.

### The rule

```bat
api\yii.bat seed demo
```

Run it **immediately before every demo, every rehearsal, and every practice run.** It reloads the
demo preset in one transaction (about 25 seconds) and starts a fresh audit chain.

### The safety net

`run_simulator.py` pre-flights the API (`GET /v1/sensor-readings/baseline`) and prints a loud
banner if scores have drifted from the seeded baseline:

```
==============================================================================
  WARNING: SCORES DO NOT MATCH THE CLEAN BASELINE
==============================================================================
  MINE                   SCORE  VIOLATIONS  BREACHES
  MP-SGR-02           80 -> 70          +2         -  low -> medium
  ...
  FIX BEFORE DEMOING:  api\yii.bat seed demo
==============================================================================
```

| Command | Behaviour | Use it for |
|---|---|---|
| `python scripts\run_simulator.py --check-only` | Reports and exits. Exit 0 clean, 1 drifted. | Pre-demo check |
| `python scripts\run_simulator.py --require-clean` | Refuses to start if drifted. | Rehearsal runs |
| `python scripts\run_simulator.py --loop` | Warns loudly, then continues. | **The live demo** |

The default warns rather than blocks on purpose: step 3 below runs the PPE demo *before* the
simulator, so by step 4 the scores have legitimately drifted.

---

## Before the room

- [ ] **`run_all.bat`** - starts PostgreSQL if needed, the API, the ai-service and the frontend,
      waits for the ports and opens the dashboard. It stops with a clear message if the database
      will not start. (First run on a machine: it migrates and seeds by itself.)
- [ ] **`api\yii.bat seed demo`** - the board must read **100 / 80 / 70 / 60 / 45** for the five
      demo mines and **83.2** national average.
- [ ] `python scripts\run_simulator.py --check-only` - must print `Pre-flight : OK`.
- [ ] `http://127.0.0.1:8080/v1/health` answers `{"status":"ok",...}`; `http://localhost:5173`
      shows the login page.
- [ ] PPE vision: `backend/ml/weights/ppe.pt` is the model fine-tuned on S13 (docs/AI_EVALUATION.md;
      rebuild with `backend\.venv\Scripts\python.exe scripts\build_ppe_model.py`, about 20 min).
      It is strong on construction-style photos (test mAP50 0.85) but misses people and hard hats
      on some of the repository's sample photos, so **for the scripted numbers start the ai-service
      with `set PPE_DETECTOR=fixture`**: `ppe_sample.jpg` then gives two violations and `with_ppe.jpg`
      a clean frame. Show the real model separately, as what it is.
- [ ] **Two browser tabs**, signed in - Government in one, Mine Head (Jayant,
      `head.mp-sgr-02@coalmine.in`) in the other. Sessions are per tab, so both stay signed in.
- [ ] A photograph ready to attach as resolution proof (JPG, PNG or WEBP).
- [ ] A terminal with `api\yii.bat seed demo` typed, not yet run.

Clean baseline: **74 mines across 10 states**, national average **83.2**, **6 high / 21 medium /
47 low**. The Overview board shows the five highest-risk mines nationally, not all 74. The five
demo mines keep their codes; their names now come from the real roster
(`data/reference/mines_real.csv`, old codes in `mine_code_mapping.csv`):

| Mine | Company | District, State | Score | Risk |
|---|---|---|---|---|
| JH-DHN-01 Moonidih | BCCL | Dhanbad, Jharkhand | 100 | Low - no open violations |
| MP-SGR-02 Jayant | NCL | Singrauli, Madhya Pradesh | 80 | Low - one PPE detection tips it to Medium |
| CG-KRB-03 Gevra | SECL | Korba, Chhattisgarh | 70 | Medium |
| WB-RNG-04 Sonepur Bazari | ECL | Paschim Bardhaman, West Bengal | 60 | Medium |
| OD-TLC-05 Bhubaneswari | MCL | Angul, Odisha | 45 | High |

If the national average does not read 83.2 before the simulator starts, **re-seed before
continuing.**

---

## Run of show

1. **Government login** (`gov@dgms.gov.in`, quick-fill "Government (DGMS)") → national overview.
   The headline numbers cover all 74 mines; the core sample board shows the five highest-risk
   mines nationally, each with its district. The page refreshes itself.

   **Then use the State dropdown.** Pick Odisha: the board fills with all 19 Odisha mines and
   every number rescopes - the average moves from 83.2 national to 86.8 for Odisha. The
   selection follows you to the Priority Queue, Sensors and Trends tabs.
2. **Priority Queue tab** → every mine ranked most urgent first, each with its reasoning and a
   trend. The ranking is urgency = (100 - score) + rising-event pressure.
3. **PPE vision - in the product.** Switch to the **Mine Head tab** (Jayant) and press **Run PPE
   detection**; choose the sample image (see *Before the room*). The panel shows the annotated
   frame with violations boxed in red and the score move - **80 → 70, Low → Medium** with the
   fixture's two violations (80 → 75 with real YOLO on the metro-shaft photo).

   Switch back to the **Government tab without reloading**: Jayant has turned yellow and the
   fleet counts have moved within about 5 seconds. Uploading `with_ppe.jpg` afterwards is a clean
   re-inspection: it resolves every open PPE finding from vision at that mine (older seeded ones
   too), so the score climbs back - possibly above 80.
4. **Live sensors** → `run_all.bat --sim` starts the simulator, or in a terminal:
   `python scripts\run_simulator.py --interval 2 --loop`. It replays the last 14 days of the demo
   data through `POST /v1/sensor-readings/ingest`, one data-hour per 2 s tick, judged against the
   **legal limits** in `data/schema/rules.yaml` (methane 1.25 % / 0.75 % return air - SAF-11;
   wet-bulb 33.5 °C - HLT-05; dust 2 mg/m³ as an 8-hour average - HLT-04; no verified CO limit).
   Breaches are rare in calibrated data, so pick **Odisha** in the State filter: **Nandira
   (OD-ANG-57)** breaches most often - down 3 on a breach, back 12 s later with nobody touching
   anything. The terminal prints every move. Ctrl+C, and the board is back at baseline within
   12 seconds. Expect the pre-flight banner here - step 3 already moved Jayant.
   (Dust rarely breaches in the replay: its limit is an 8-hour average, and the replay compresses
   hours into seconds.)
5. **Drill down** → click Bhubaneswari. Score with the formula, alerts (each a translated
   `{code, params}`), sensor trends with the legal limit lines, and one row of records:
   **violations** (all 11 categories), **corrective actions** (overdue ones flagged),
   **incidents** (the dangerous occurrence of 18 Sept, reported after 3 h - within 48 h, RPT-05 -
   linked to the strata violation before it) and the **audit trail**.
6. **Raise a directive** → **Flag for inspection** on the drill-down. It records the score at the
   moment of the click and appears on the mine's dashboard tagged *From DGMS*.
7. **Sensors tab (Government)** → which mines are breaching right now, per sensor type, with the
   legal limit and "No verified limit" where none exists.
8. **Sign out, Mine Head login** (quick-fill "Mine Head - Bhubaneswari") → own mine only. No
   board, no comparison, no Priority Queue tab.
9. **Close the loop** → resolve the directive with a written action and a photograph. Then open
   **Violations**, pick an open one, **Record corrective action**, and close it from **Corrective
   actions** with proof: the violation is resolved and the score rises **45 → 50, High → Medium**.
   Back on the Government tab: the resolution and its proof are there, with **Reopen** if the
   evidence is not good enough (the earlier attempt stays in the history).
10. **Contractors** (Contractors tab). Sign in as the mine head of **Block-B (MP-SIN-42,
    `head.mp-sin-42@coalmine.in`)**: **Prakash Infra Projects** is at the top, flagged - wage
    registers and EPF challans missing month after month and 3 violations per active worker at
    this mine (1.03 across all its contracts, the highest in the fleet). Open it: the Documents tab
    lists every missing month with an Upload button; uploading one lifts the score at once. The
    Alerts tab carries its `CONTRACTOR_DOC_MISSING` alerts. Licence, training and medical rules cite
    the OSH Code, 2020 and the OSH (Central) Rules, 2026 (LAB-02, SAF-04, HLT-01) - never the
    repealed Contract Labour Act. On the Government overview, the **Contractor compliance** card
    lists it first among flagged contractors; the Contractors tab gives the read-only per-mine
    summary.
11. **Corporate view** (quick-fill "Corporate - SECL") → the same screens, scoped to SECL's 17
    mines. Opening a mine of another company answers **404** - exactly like a mine that does not
    exist, so nothing leaks.
12. **If a judge asks about access control**, do not look for it in the UI - there is nothing to
    click. Prove it from the tests or the API:

    ```bat
    cd api && run_tests.bat api ScopingCest
    ```

    ```bat
    curl -i -H "Authorization: Bearer <mine head token>" http://127.0.0.1:8080/v1/mines/1
    ```

    The second answers `404 {"error":{"code":"NOT_FOUND"}}` for any mine outside the account's
    scope. An empty list would read as a mine with no findings - the most dangerous wrong answer
    in a compliance system - so out-of-scope is always a 404, never a filtered empty result.

**After the run - and before the next one - re-seed.**

---

## If a judge asks "how does a mine get its score back?"

> "Sensor breaches are conditions, so they age out: a breach counts only inside a rolling window,
> and once the air has been clean for the whole window the penalty is gone - you just watched
> Nandira do that. Violations are findings, so they stay until someone closes them with evidence:
> a corrective action with proof, or a clean PPE re-inspection. Nothing is deleted; every breach,
> violation and resolution stays in the log, and the audit trail is hash-chained
> (`api\yii.bat audit/verify`). The demo window is 12 seconds so you can see it happen; a real
> deployment would set it in hours."

---

## Open questions to settle before the demo (PRD 8.1)

- Recorded video over live feed - recorded is the safer bet on venue hardware; decide and lock it.
- Final `WEIGHT_PPE` / `WEIGHT_ENV` values (api/.env). Retuning changes the baseline table above;
  the demo-score test (`api/tests/api/DemoScoreCest.php`) will say so.
