# Data cards

One card per dataset in `data/reference/` and `data/out/`. Each states whether the data is
**real** (taken from a published source), **calibrated-synthetic** (generated, but tuned to real
statistics) or **synthetic** (generated from documented assumptions), plus where it came from, how
it was made, and what it cannot be trusted for.

Cards are added stage by stage; stage D6 completes the set.

---

## Rule: mine locations (decided 2026-09-25, applies from stage D3)

The 74 mines come from the prototype's synthetic seed, so a mine name is not evidence that a real
mine exists. `reference/mines.csv` (stage D3) therefore places mines as follows:

1. **Matched with good confidence to a real mine** — by fuzzy name + state against the Global
   Coal Mine Tracker, or failing that Wikidata — gets that source's coordinates,
   `location_quality = exact_gem` or `wikidata`, and `is_demo_mine = false`.
2. **Anything else** gets `location_quality = district_centroid` and `is_demo_mine = true`.
3. **A fictitious mine is never given real-looking exact coordinates.** A district-centroid point
   is the centroid of the mine's district polygon, rounded to 2 decimal places (about 1 km), so it
   cannot be mistaken for a surveyed mine location.

"Good confidence" is a `match_confidence` threshold chosen and justified in stage D3. Every match
below it is reviewed and listed at the end of that stage, not silently accepted or dropped.

Not to be confused with `demo_named` in `mines_base.csv`, which marks the five mines the demo
script is built around. The two are independent: a named demo mine may still turn out to have
no real counterpart (`is_demo_mine = true`).

---

## `reference/mines_base.csv`

| | |
|---|---|
| **Kind** | Repo seed — the prototype's own **synthetic** demo data, copied as-is |
| **Source** | S01, the existing repo seed (`backend/data/seed/`), read-only |
| **Produced by** | `data/scripts/extract_mines_base.py` (stage D0; `run_data.bat setup`) |
| **Rows** | 74 — one per seeded mine |
| **Checksum** | `9ac697e189e00847d267be3401801b0cac48b6a7293ad2261bbd06c7cae3de51` (SHA-256) |

**Inputs** (SHA-256, commit `2509d16`):

| File | SHA-256 |
|---|---|
| `backend/data/seed/mines.json` | `951ce401c70a25fbcfe5c976514241997846f9f5cfeb2fb115738b9beb3d113e` |
| `backend/data/seed/users.json` | `3a74a2328805ae26667f6c24ebe4895da0c11136aa5d5a80a49ea8ff3e309723` |
| `backend/data/seed/violations.json` | `ce6f2c9775025c2446deead7bd6d9d6896230bae8bd44adc93335e279feaabe4` |
| `backend/data/seed/sensor_readings.csv` | `49f58cfe9e3ab5e3a5dd146e1614da53c561bb619b5b8eecab6e53da6b1df2c0` |

**Columns**

| Column | Meaning |
|---|---|
| `id`, `code`, `name`, `location`, `district`, `state`, `region`, `operator` | Every field of `mines.json`, unchanged. `operator` is the operating company as a name string. |
| `head_email` | The mine head's login from `users.json`. Passwords are deliberately not copied. |
| `demo_named` | `true` for the five mines the demo script is built around (JH-DHN-01, MP-SGR-02, CG-KRB-03, WB-RNG-04, OD-TLC-05). |
| `seed_violations` | Open PPE violations at seed time (the seed has no resolved flag, so all are open). |
| `seed_readings` | Seeded sensor readings for the mine. |
| `seed_breaches` | Seeded readings strictly above the backend's demo thresholds (gas 50 ppm, dust 10 mg/m³, temperature 45 °C). Historical; none count toward today's score. |
| `demo_score` | The score on the demo board today: `100 − 5 × seed_violations`. Breaches count only inside the backend's 12-second window and every seeded reading is days old, so none are in it. |
| `demo_risk_level` | LOW ≥ 80, MEDIUM ≥ 50, else HIGH — from the rounded score, as the backend does it. |

**How it was checked.** The extraction refuses to write unless its result matches the baseline
in `docs/demo-script.md`: 74 mines, average 83.2, 6 High / 21 Medium / 47 Low, and the named mines
at 100 / 80 / 70 / 60 / 45. It was also compared field by field against the backend's database
(`backend/smartmine.db`, opened read-only, pre-flight clean): all 74 mines match on every column,
the head login, the open-violation count, the score and the band. Two runs produce byte-identical
files.

**Limitations**

- **Not real-world data.** The mine names, violation counts and scores were created for the
  prototype demo with a fixed random seed. A name resembling a real mine does not make it one.
  Which of the 74 correspond to real mines is established in stage D3 by matching against the
  Global Coal Mine Tracker, not assumed here.
- **No coordinates, no areas.** The seed has neither; stage D3 adds both, each with a stated
  quality level.
- **`operator` is a company name, not a key.** Stage D3 turns it into `companies.csv` IDs. Three
  values are not Coal India subsidiaries: NLC India Ltd, Singareni Collieries Company Ltd, and
  "Coal India Ltd" itself (five mines).
- **The demo weights and thresholds are prototype settings, not legal limits.** They are recorded
  in `config.yaml` under `repo_demo_baseline` only to reproduce the board, and must not be used as
  regulatory values (brief rule 7).
- **A snapshot of commit `2509d16`.** If the backend seed or scoring changes, re-run
  `run_data.bat setup`; the extraction stops rather than writing a wrong file if the documented
  baseline no longer matches.
