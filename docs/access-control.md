# Access control (PRD 4.2)

The PRD is explicit: enforcement is server-side, not UI-hidden. A Mine Head must not be able to
reach another mine's data by calling the API directly.

## Model

- Every mine has a unique `mine_id`.
- A Mine Head user row carries exactly one `mine_id`.
- A Government user row has `mine_id = NULL`, meaning no restriction.
- The JWT carries `role` and `mine_id`, so scope is derived from the token, never from a query
  parameter the client controls.

## Enforcement

One choke point: `app/api/deps.py`.

- `get_current_user()` — decodes the token, loads the user.
- `require_role(Role.GOVERNMENT)` — for Government-only endpoints
  (cross-mine comparison, inspection prioritisation, full audit trail).
- `resolve_mine_scope(requested_mine_id)` — returns the mine ids the caller may read.
  For a Government user, all of them. For a Mine Head, only their own — and a request for any
  other mine raises **403 before the query runs**.

`services/access/scope.py` holds the same rule as a pure function so it can be tested without HTTP.

## The 403 vs 404 choice

A Mine Head requesting another mine gets **403**, not a silently filtered empty result. An empty
list would look like a mine with no violations, which is exactly the wrong signal in a compliance
system.

## Test that proves it

`tests/test_access_control.py` logs in as a Mine Head and calls every mine-scoped endpoint with a
foreign `mine_id`. Each must return 403. This is the test to run in front of judges if asked
whether the restriction is real.
