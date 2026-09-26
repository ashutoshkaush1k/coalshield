# Data track → backend and frontend handoff

Notes from the dataset track (`data/`) for the other tracks. The data track never edits
`backend/`, `api/`, `ai-service/` or `frontend/`; changes there are proposals for the owning track.

---

## Proposal: switch the backend seed to the real mine roster (pending team approval)

**Status: proposed on 2026-09-26, not approved.** Nothing in `backend/` has changed.

The prototype's 74 mines (`backend/data/seed/mines.json`, copied to
`data/reference/mines_base.csv`) are fictional: template names such as "Dhanbad Washery Block", and
operators placed in states where they do not operate (46 of 74, per the Global Coal Mine Tracker).
`data/reference/mines_real.csv` is a drop-in roster of 74 **real operating coal mines** from the
Global Energy Monitor (GEM) Global Coal Mine Tracker (August 2026, CC BY 4.0), all with exact
GEM coordinates.

**What the backend track would do in Phase 1** (after approval):

1. Load mines from `data/reference/mines_real.csv` instead of `backend/data/seed/mines.json`.
   Columns map one-to-one: `id`, `code`, `name`, `state`, `district`, `region`; the operator comes from
   `company_id` via `data/reference/companies.csv`; `location` can be built as "district, state".
   New fields the backend may use: `lat`, `lon`, `type`, `capacity_mtpa`, `area_id`, `gem_id`.
2. Use `data/reference/mine_code_mapping.csv` (`old_code → new_code`) to move anything keyed by the
   old code: users, violations and sensor readings in the seed.
3. Logins: each mine head's login follows the existing pattern `head.<code in lower case>@coalmine.in`
   (column `head_email`). **The five demo mines keep their codes and logins** (JH-DHN-01,
   MP-SGR-02, CG-KRB-03, WB-RNG-04, OD-TLC-05). The other 69 get new logins in the same format, and 10
   codes in total are unchanged. Give the new logins the same demo password the seed uses today.
   The data track does not copy passwords.
4. Scores: each real mine takes the demo score, band and open-violation count (`seed_violations`)
   of the seed slot it replaces. So `100 − 5 × seed_violations` reproduces `demo_score`, and the
   board keeps its shape: average 83.2, **6 High / 21 Medium / 47 Low**, with the five named mines at
   100 / 80 / 70 / 60 / 45.

**What stays the same**

- The demo script's five slots: same codes, logins, scores and bands. Only their names and places
  change: Jharia → Moonidih (BCCL, Dhanbad); Singrauli → Jayant (NCL); Korba → Gevra (SECL);
  Raniganj → Sonepur Bazari (ECL, Paschim Bardhaman); Talcher → Bhubaneswari (MCL, Angul). The
  Mine / District, State table under "Before the room" in `docs/demo-script.md` would need those
  names and places updated.
- `data/reference/mines.csv`, the fictional seed with district-level locations, is kept unchanged
  as the fallback if the team prefers not to switch.

**Must-haves if adopted**

- **Scores on real mines are demo values, not assessments.** A real mine shown as "HIGH risk" could
  be read as a statement about that mine. Every score, band and violation count in the seed is
  synthetic (`score_note` column). The UI should say so wherever a score is shown next to a real
  mine name, e.g. "Demo score - not a real safety assessment".
- **Attribution.** GEM's licence (CC BY 4.0) requires crediting "Global Energy Monitor, Global Coal
  Mine Tracker, August 2026" wherever the mine names, locations or capacities are shown (an About
  page or map footer is enough).

**Open points for the team**

- Approve or reject the switch.
- If approved: update `docs/demo-script.md` and the frontend's map for real coordinates (all 74 are
  exact GEM points, so a map no longer needs to cluster mines at district centres).
