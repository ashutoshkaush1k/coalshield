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
api\yii.bat jobs/all
```

Run it **immediately before every demo, every rehearsal, and every practice run.** It reloads the
demo preset in one transaction (about 25 seconds) and starts a fresh audit chain. `jobs/all` (Phase
7, about 5 seconds) then does what the clock would: reminders, escalations, the risk index history,
the predictions and the detectors' findings. The scores are the same before and after it.

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

- [ ] **`run_all.bat`** - starts PostgreSQL if needed, the API (Apache on 8080), the ai-service and the frontend,
      waits for the ports and opens the dashboard. It stops with a clear message if the database
      will not start. (First run on a machine: it migrates and seeds by itself.)
- [ ] **`api\yii.bat seed demo`** - the board must read **100 / 80 / 70 / 60 / 45** for the five
      demo mines and **83.2** national average.
- [ ] `python scripts\run_simulator.py --check-only` - must print `Pre-flight : OK`.
- [ ] **Languages.** Mine heads are seeded with their state's language and open in it after login
      (Bhubaneswari: Odia; Moonidih, Gevra, Block-B: Hindi). That is the feature. To walk a step
      in English, choose English on the Profile page (sidebar, one click, saved to the account), and
      switch back for the language step. Government and corporate open in English.
- [ ] `http://127.0.0.1:8080/v1/health` answers `{"status":"ok",...}`; `http://localhost:5173`
      shows the login page.
- [ ] PPE vision runs the **real model**: `http://127.0.0.1:8001/health` must say
      `"backend":"yolo"` (`run_all.bat` warns if `ai-service/ml/weights/ppe.pt` is missing; rebuild
      with `ai-service\.venv\Scripts\python.exe scripts\build_ppe_model.py`, about 2 h on CPU -
      docs/AI_EVALUATION.md). Use **only the held-out test images** in
      `ai-service\samples\heldout\` - images from the test split the model never saw in training
      or model selection, and say so when you show them. `heldout_06_violations.jpg` is the scripted
      one; `heldout_01_clean.jpg` the clean re-inspection. The folder's README gives the model's
      agreement with the ground truth over the whole test split (73 % of images with people) - the
      demo images are selected from the agreeing ones, and that is also what you say.
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
   detection**; choose `ai-service\samples\heldout\heldout_06_violations.jpg` - **a held-out
   test image: the model never saw it in training**. The real YOLO model finds three workers
   without hard hats; the panel shows the annotated frame with the violations boxed in red and the
   score move - **80 → 65, Low → Medium**.

   Switch back to the **Government tab without reloading**: Jayant has turned yellow and the
   fleet counts have moved - a tab refetches the moment it becomes visible (otherwise the
   dashboards poll every 10 s, one request per screen). Uploading `heldout_01_clean.jpg` afterwards (also
   held out: two workers with hard hats, vests and boots) is a clean re-inspection: it resolves
   every open PPE finding from vision at that mine (older seeded ones too), so the score climbs
   back - to **100** on the demo seed.

   If a judge asks about out-of-distribution photos: the model misses people on some of the
   repository's own sample photos (docs/AI_EVALUATION.md, "Limits") - that is why the demo uses
   held-out test images, and why a clean frame counts only when it shows a person or worn PPE.
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
   **incidents** (the dangerous occurrence of 18 Sept, reported after 3 h - within the 12 h RPT-05 allows -
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
11. **Production and the Call for Detailed Report** (Production tab). Government sees **numbers
    only** per mine - the day and the month to date, target, actual, achievement - with an anomaly
    flag: **Gevra (CG-KRB-03) is flagged for 4 September**, output twice its 30-day average and
    79 % over target the day before an inspection (scenario S2; Chasnalla's genuine increase with a
    revised target, N1, is not flagged). **View detail** answers *Detailed report required (403
    DETAIL_REQUEST_REQUIRED)* - the regulator cannot browse a mine's records at will.
    **Call for detailed report** on Gevra: the range around the flagged day and the reason are
    prefilled; set a deadline and send. Sign in as the Gevra mine head
    (`head.cg-krb-03@coalmine.in`): the call is in the inbox; the charts show target vs actual with
    the spike marked, the cumulative month and the shift split. Enter a shift, **Submit** (locked),
    then **Correct with reason** - the edit log shows old → new, the reason, who and when. Answer the
    call with a note and a file. Back as Government: the row says *Submitted*, **View detail** now
    opens the entries, charts and the mine's response; **Accept and close**. A call nobody answers
    turns *Overdue* at its deadline and *Escalated* 72 h later, each with a
    `DETAIL_REQUEST_OVERDUE` alert - Nandira (OD-ANG-57)'s seeded call falls due during the day.
12. **Grievances.** Sign out; on the login page press **Raise a grievance** (no account). Choose
    Gevra, *Contract worker*, Hindi, *Safety* → *PPE*, write the complaint in Hindi, submit: a
    ticket `GRV-2026-000xxx`, an 8-character **tracking code** (shown this once - say so) and the
    response due time (48 h for safety). **Track this grievance** shows the status and the
    timeline only - no text, no names. Change one letter of the code and track again: *No
    grievance matches* - the same answer as a ticket that does not exist, so a ticket number alone
    tells nobody anything. (A seeded grievance's code is in `data/out/demo/grievance.csv`,
    column `tracking_code` - demo data only.)
    - Sign in as the Gevra mine head (`head.cg-krb-03@coalmine.in`), Grievances tab:
      - the new grievance is at the top, its text shown as written and labelled *Hindi*;
      - **Submitted by** says *Identity not shown to this role*;
      - **Start investigation**, then write the resolution and **Resolve**; tracking now shows
        the outcome;
      - the safety grievance also opened an observation for the inspection flow.
    - As Government, Grievances tab:
      - totals, average time to resolution, SLA breaches;
      - the **SLA-breach cluster at Kulda (OD-SUN-07, scenario S6)**: grievances raised
        1-9 September, all past their response time;
      - Jhanjra's burst of six grievances, all handled in time (N2), is *not* flagged;
      - the escalated queue.
    - Filter **Sensitive**: Gevra has two harassment grievances, routed to DGMS, identity visible
      to the regulator. Back as the Gevra mine head they are nowhere - not in the list, not in the
      alerts, not in the audit trail - and by id the API answers 404.
13. **Statutory obligations** (Obligations tab). As the Gevra mine head: statutory compliance
    (a separate measure - the compliance score does not move), then *Due soon*, *Overdue*, *Open*,
    *Submitted* and *Recently accepted*. Every row shows its act and section; open one and
    **Show the text** gives the verbatim quote with the source file and page. Upload a PDF with a
    note → *Submitted*. As Government, Obligations tab: compliance per company, the most overdue
    items, then choose *Chhattisgarh* and open the new evidence under *Evidence awaiting review*:
    **Reject** without a reason is refused; write one and reject. Back as the mine head the reason
    is on the task; upload again, and Government **Accepts**. Overdue items carry an
    `OBLIGATION_OVERDUE` alert (level 1) and escalate to level 2 after 168 h; to show it live,
    `api\yii.bat obligation/check --at=<a time after a due date>` does now what the clock will
    do then (reseed afterwards).
14. **The map, offline** (Map tab). Pull the network cable or switch Wi-Fi off first: the state and
    district outlines are local data and the mines are at their real coordinates, coloured and
    labelled by risk band (hover: score, district, location quality - dashed for approximate).
    Click a mine to open it. The street map (OpenStreetMap) is off by default; switching it on
    offline says the tiles are unavailable and the outlines stay. Global Energy Monitor (CC BY
    4.0) and DataMeet are credited on the map. As corporate SECL: 17 mines; as a mine head: one.
15. **Languages.** Sign out; on the login page choose **ଓଡ଼ିଆ** in the switcher at the top - the page
    switches at once (the choice stays in this browser). Sign in as the Bhubaneswari mine head: the
    account's saved language wins. Open **Profile and language** in the sidebar and pick **हिन्दी**:
    the whole interface, charts and map included, switches without a reload, and the choice is saved
    to the account. Show the obligation register in Hindi: titles and labels are translated, the
    legal citation and quote stay as written; a grievance keeps the language it was written in.
    Numbers keep Indian grouping (17,04,240) in every language. Say plainly that the five
    translations are drafts marked for native-speaker review.
16. **Corporate view** (quick-fill "Corporate - SECL") → the same screens, scoped to SECL's 17
    mines. Opening a mine of another company answers **404** - exactly like a mine that does not
    exist, so nothing leaks.
17. **If a judge asks about access control**, do not look for it in the UI - there is nothing to
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

18. **Automation and risk (Phase 7).** `run_all.bat` has already run the scheduled jobs once. Open
    **Priority Queue**: the mines are ordered by the **Governance Risk Index**, with the compliance
    score still beside it - say the score itself has not changed. Scroll to **Patterns found**: seven
    automated checks, each finding with its numbers ("12 roof and strata violations of 33 in 45
    days, about 2.5 expected"). Open **Bhubaneswari**: the index sits beside the score; the panel
    below shows how it is made up (count × points, capped), the **predicted risk** with what raises
    it, and the statement that the model is **trained on US regulator data and transferred** -
    read it out; it is a prompt to look, not a finding. If asked how good the checks are:
    `docs/AI_EVALUATION.md` (every planted scenario found, every decoy ignored, the false positives
    explained; the model beats last year's accident rate on later US years, AUC 0.82 against 0.77).
    To show the fallback, close the `SIH-AI` window and run `api\yii.bat jobs/anomaly`: the same
    findings, labelled "built-in check".

19. **Field app, offline to dashboard (2 minutes, Phase 7B).** Before the room: `run_field.bat`
    running; the phone set up once (`docs/FIELD_APP_SETUP.md`: CA installed, or USB forwarding),
    the app installed from `/field`, **signed in once as `inspector.07@dgms.example`**, Location and
    Camera allowed. On the laptop, the government dashboard open on **Talabira II & III** (the mine of
    inspector 07's scheduled inspection) next to the phone. Say the open-violations count out loud.

    | Time | Phone | Say |
    |---|---|---|
    | 0:00 | Turn on **aeroplane mode**. Open the app from its icon: it opens, marked **Offline**. | "No signal, as underground. The app, the checklist and this inspector's assignments are on the phone." |
    | 0:15 | Tap **Talabira II & III** (scheduled). Open **Roof and strata**, tap **Roof and sides...** | "Every item is tied to a category and, where one applies, the obligation it checks - SAF-08 here, with its regulation." |
    | 0:30 | **High**, take a photo, see **Located (within ... m)**, keep **Record as a violation**, tick **Ask the mine for a corrective action**, type one line, **Save finding**. | "Photo compressed on the phone; GPS with its accuracy; the phone's own time. We are hundreds of km from Talabira, so it warns this will be **flagged** - saved, not refused: the geo-check." |
    | 0:55 | Second finding: **PPE > Every worker has helmet...**, **Medium**, photo, **Save**. Back: two findings **waiting**. | "Two findings queued. Nothing has left the phone." |
    | 1:10 | Turn aeroplane mode **off**. Tap **Sync now**. "Sent: 5 new". | "Visit, two findings, two photos. Each carries an id made on the phone." |
    | 1:25 | Point at the laptop: within 10 s the open-violations count rises by 2 with no reload. Open **Violations**, then the new one: **Field capture**, phone time and receipt time, GPS and distance, the photo. | "Through the same rules as any violation: scope, alert, audit chain. The mine head sees it too." |
    | 1:45 | Tap **Sync now** again (or: "if the answer had been lost and the phone sent it again..."). | "Every item is idempotent: a resent queue is answered 'already on the server' and nothing doubles." |

    If asked: a capture more than 5 km from the mine's recorded point (as in this room), or from a
    phone with a wrong clock, is **flagged, not refused**; an expired login keeps the queue and asks for the password
    before syncing; nothing queued is ever deleted until it has synced. The captures change the live
    score of Talabira - re-seed afterwards. Fallback if the phone misbehaves: the same sequence runs
    in a desktop browser at `http://localhost:5180/field` with DevTools set to a phone and **Offline**
    (the screenshots in `docs/screenshots/phase7b/` show every step).

**After the run - and before the next one - re-seed.** (`run_all.bat` runs the jobs again on the next start.)

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
