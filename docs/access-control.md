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

## Three layers

1. **Scoping - what rows exist for you.** One place: `api/components/ScopedActiveQuery.php`.
   Every mine-owned model extends `ScopedActiveRecord` and declares its mine column
   (`scopePath()`: `mine_id`, `id` for the mine itself, `relation.column` - e.g. a contract
   worker through `contract.mine_id` - or `via:table.key` for a record with no mine of its own:
   a contractor is in scope when one of its contracts is, `via:contract.contractor_id`). Controllers read
   through `Model::findScoped($id)` or `Model::find()->forCurrentUser()`; a `?mine_id=` filter
   is itself scope-checked (`ApiController::mineParam()`).
2. **Permissions - what you may do.** RBAC with `yii\rbac\DbManager`, one permission per action
   (`api/config/rbac.php`, installed by `yii rbac/init`): e.g. `directive.create` (government),
   `correctiveAction.resolve` (mine head), `inspection.viewQueue` (multi-mine roles). A missing
   permission is **403 `FORBIDDEN`**.

3. **Data-dependent rules - what the data allows you to see.** Declared once in
   `api/components/AccessRule.php` and applied by the controllers, never re-implemented. The
   first is the production detail rule (Phase 4): a multi-mine role sees a mine's production
   entries for a period only when a "Call for Detailed Report" covering that period has been
   answered (`submitted` or `closed`); otherwise **403 `DETAIL_REQUEST_REQUIRED`**. It applies
   after scoping, so a mine out of scope is still a plain 404. A mine head sees its own mine in
   full (`production.viewDetail`); the summary for multi-mine roles is numbers only.
   The second is grievance routing (Phase 5). A grievance about harassment, or against the mine
   head, does not exist for the mine head, even at its own mine. `ScopedActiveRecord::restrictFor`
   removes it from every scoped query, together with its alert. The audit trail and the open-alert
   count exclude it too. A complainant's name and contact are serialised only to government and
   inspector (and corporate, for grievances that are not sensitive); a mine head's payloads do not
   even carry the keys.

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
- Contractors (`ContractorCest.php`): a mine head sees only contractors with a contract at its
  mine (another mine's contractor or contract is 404), government and corporate read but cannot
  manage (403), and the per-mine summary is refused to a mine head (403).

- Production (`ProductionCest.php`): 403 `DETAIL_REQUEST_REQUIRED` before a request, still 403
  while it is unanswered and for any day outside the answered range, 200 after the answer for
  government and for corporate of the same company; another company's mine is 404 before the rule
  applies; the mine head cannot call for reports and government cannot enter production.
- Grievances (`GrievanceCest.php`): a sensitive grievance at the mine head's own mine is absent
  from its list, 404 by id and for a transition, and absent from its alerts (list and by id), the
  open-alert count and the audit trail, while government sees all of it. No grievance payload for
  a mine head contains a `name` or `contact` key, and none of the mine's complainant names or
  contacts appears in any response to it (lists, details, the screen view, the audit trail).
  Corporate sees identities only for grievances that are not sensitive.
- The view endpoints (`ViewCest.php`): each part of `/v1/views/*` equals its own endpoint for the
  same account, and another mine's view is 404.

```bat
cd api
run_tests.bat api ScopingCest
```
