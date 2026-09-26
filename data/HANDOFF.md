# Data track handoff

**Ownership (2026-09-26).** One owner: Yug Pancholi, the only person on this project. All
implementation is done by Claude Code, which owns `backend/`, `api/`, `ai-service/`, `frontend/`
and `data/`. Earlier "pending team approval" or "owned by another track" notes are resolved. This
file records what the data track hands to the backend and frontend work, the decisions taken, and
what is still open.

---

## Decision: the real mine roster is adopted (2026-09-26)

`data/reference/mines_real.csv` is **the project's mine list**: 74 real operating coal mines from
the Global Energy Monitor (GEM) Global Coal Mine Tracker (August 2026, CC BY 4.0), all with exact
GEM coordinates. `data/reference/mines.csv` (the prototype's fictional seed, with district-level
locations) stays as the fallback, selected with `mine_roster: seed` in `data/config.yaml`.

**What the backend does in Phase 1**

1. Load mines from `data/reference/mines_real.csv` instead of `backend/data/seed/mines.json`.
   `id`, `code`, `name`, `state`, `district` and `region` map one-to-one. The operator comes from
   `company_id` via `data/reference/companies.csv`, and `location` is "district, state". Also
   available: `lat`, `lon`, `type`, `capacity_mtpa`, `area_id`, `gem_id`.
2. Use `data/reference/mine_code_mapping.csv` (`old_code → new_code`) for anything keyed by the old
   code (users, violations, sensor readings).
3. **Logins** follow `head.<code in lower case>@coalmine.in` (`head_email`).
   - The five demo mines keep their codes and logins (JH-DHN-01, MP-SGR-02, CG-KRB-03, WB-RNG-04,
     OD-TLC-05); 10 codes in total are unchanged.
   - New logins get the same demo password the seed uses today. The data track does not copy
     passwords.
4. **Scores:** each real mine takes the demo score, band and open-violation count of the seed slot
   it replaces. `100 − 5 × seed_violations` reproduces `demo_score`: average 83.2, **6 High /
   21 Medium / 47 Low**, with the named mines at 100 / 80 / 70 / 60 / 45.
5. **Demo script:** update the Mine / District, State table under "Before the room" in
   `docs/demo-script.md`:
   - Jharia → Moonidih (BCCL, Dhanbad);
   - Singrauli → Jayant (NCL);
   - Korba → Gevra (SECL);
   - Raniganj → Sonepur Bazari (ECL, Paschim Bardhaman);
   - Talcher → Bhubaneswari (MCL, Angul).

**Must-haves**

- **Scores on real mines are demo values, not assessments.** Every score, band and violation count
  is synthetic (`score_note`). Wherever a score appears next to a real mine name, the UI must say
  so, e.g. "Demo score - not a real safety assessment".
- **Attribution.** Credit "Global Energy Monitor, Global Coal Mine Tracker, August 2026" wherever
  mine names, locations or capacities are shown (an About page or map footer is enough).
- The map can use real points; all 74 mines have exact GEM coordinates.

---

## Decisions on the D3 review (2026-09-26)

All accepted by the owner:

- **Five dropped GEM candidates.** Kakri, Barsingsar, Kondapuram, Vakilpalli and Neyveli I/I A/II
  (the Neyveli mines have no seed state prefix).
- **Name-based area rule.** A mine whose name contains its company's area name gets that area.
- **2011 district basis for 12 mines** whose districts were split after 2011.
- **`reference/env_daily.csv` stays out of git** until the OpenAQ station licence is known.
- **An 11th violation category, `machinery`.** The final list of 11 is in
  `data/schema/violation_categories.yaml`, and the backend uses the same list.

---

## Open TODO-VERIFY items (left open on purpose)

| Item | Where | What would close it |
|---|---|---|
| EPF Act 1952 status | `reference/legal_instruments.csv` | Serial (vi) of S.O. 2060(E) of 03.05.2023 (manual step 6) |
| Monthly coal production return (RPT-08) | `reference/obligations.csv` | A downloaded source stating the reporting duty |
| OpenAQ station licence | `reference/env_stations.csv` | Licence for the CPCB stations; then `env_daily.csv` can be committed |
| Glossary translations | `reference/glossary.csv` | Native-speaker review of hi, bn, or, te, mr (`reviewed = false`) |
