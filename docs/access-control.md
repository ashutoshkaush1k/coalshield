# Access control (PRD 4.2, brief rules 2 and 3)

Enforcement is server-side, not UI-hidden. No account can reach another mine's data by calling
the API directly. The frontend hides actions an account may not take only as a courtesy (it reads
the account's `permissions` from `GET /v1/users/me`).

## Model

| Role | Sees | Scope column |
|---|---|---|
| `government` | every mine | none |
| `inspector` | every mine (reads like government; carries out inspections) | none |
| `corporate` | the mines of its company (`subsidiary`) | `user.subsidiary_id` |
| `mine_head` | its own mine | `user.mine_id` |

A database CHECK keeps each role's scope columns consistent (`m260927_000005_create_user`). Scope is
read from the user row on every request, never from the token or a client-supplied parameter, so
re-mapping an account takes effect immediately.

## Two layers

1. **Scoping - what rows exist for you.** One place: `api/components/ScopedActiveQuery.php`.
   Every mine-owned model extends `ScopedActiveRecord` and declares its mine column
   (`scopePath()`: `mine_id`, `id` for the mine itself, or `relation.column`). Controllers read
   through `Model::findScoped($id)` or `Model::find()->forCurrentUser()`; a `?mine_id=` filter
   is itself scope-checked (`ApiController::mineParam()`).
2. **Permissions - what you may do.** RBAC with `yii\rbac\DbManager`, one permission per action
   (`api/config/rbac.php`, installed by `yii rbac/init`): e.g. `directive.create` (government),
   `correctiveAction.resolve` (mine head), `inspection.viewQueue` (multi-mine roles). A missing
   permission is **403 `FORBIDDEN`**.

## The 404 rule (owner decision, 2026-09-27)

A record outside your scope answers **404 `NOT_FOUND`** - byte for byte the same response as a
record that does not exist - so the API never confirms that another mine's record exists. The
prototype answered 403 there; that leaked existence.

It is never a filtered empty list either: an empty violation list would read as a mine with no
findings, the most dangerous wrong answer in a compliance system. So: out of scope → 404; not
permitted → 403; never silently empty.

## Tests that prove it

- `api/tests/api/ScopingCest.php` - government, inspector, corporate and mine head against
  `GET /v1/mines` and `/v1/mines/{id}`, including that an out-of-scope id and a missing id get
  identical responses.
- `api/tests/unit/ScopedActiveQueryTest.php` - the scope rule itself, including misconfigured
  accounts (a corporate user without a company sees nothing).
- Every Phase 2 resource repeats the check: alerts, sensors, violations, corrective actions,
  incidents, the audit trail and PPE vision (`api/tests/api/*Cest.php`).

```bat
cd api
run_tests.bat api ScopingCest
```
