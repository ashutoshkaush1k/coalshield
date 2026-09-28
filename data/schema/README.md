# Output schemas (stage D4)

One YAML file per table that `data/generators/` writes to `data/out/<preset>/<table>.csv`. The
generators write exactly these columns, in this order. Stage D5 (`validate.py`) checks every CSV
against its schema: columns, types, nullability, enum values and foreign keys.

Where the columns come from, in order of precedence (dataset brief D4, owner decision 2026-09-26):

1. `CLAUDE_CODE_TASK.md`, including its "Legal update (verified in D1)" section.
2. `PLAN.md` (branch `feat/governance-backend`), where the brief is silent.
3. The FastAPI prototype's models (`app/models/`, removed in Phase 8, in git history) (existing columns the brief keeps).
4. The dataset brief itself (for `env_reading`, which no backend document defines).

Each file lists its sources under `defined_by`. Every conflict between these documents, and the
choice made, is recorded in `data/HANDOFF.md` ("Schema conflicts and choices").

## Column types

| type | CSV form |
|---|---|
| `integer` | digits, no thousands separator |
| `number` | decimal with `.`; precision given per column where it matters |
| `string` / `text` | UTF-8; `text` may be long |
| `boolean` | `true` / `false` |
| `date` | `YYYY-MM-DD` |
| `month` | `YYYY-MM` |
| `datetime` | ISO-8601 UTC with `Z`, e.g. `2026-09-25T06:00:00Z` (brief rule 8) |
| `json` | a JSON object on one line |
| `wkt_point` | `POINT(lon lat)`, SRID 4326 |
| `enum` | one of `values`; always lower case (brief: `snake_case`, lower-case statuses and roles) |

An empty cell means NULL and is allowed only where `nullable: true`.

## Rule files (not tables)

- `violation_categories.yaml` - the 11 violation categories (brief's 10 + `machinery`, decided 2026-09-26).
- `rules.yaml` - every legal period, deadline or threshold a generator uses, each tied to a
  verified row of `data/reference/obligations.csv`. Generators refuse to run if a cited
  obligation is missing or not verified.
