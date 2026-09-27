# Performance

Target (owner, before Phase 4): every dashboard request **under 150 ms** on the development
machine with the demo seed, **no slowdown with two dashboards open side by side**, and at most
**two requests per screen per polling cycle**, polling every 10 s instead of 5.

Result: every screen answers in **18-45 ms** (median; p95 at most 52 ms), the same with two
dashboards polling side by side, in **one request per screen and cycle** - down from
**370-850 ms** per cycle (1.2-1.4 s side by side) before.

## How it was measured

`node scripts/perf_check.mjs [legacy|aggregated] [cycles]` replays one polling cycle of each screen
(the requests that screen makes on every refresh, fired together as the browser does), first with
one client, then with a government overview and a mine-head dashboard polling side by side. It
prints the median and p95 per request and per cycle, after two warm-up cycles. `legacy` replays the
requests the screens made before this work, `aggregated` the ones they make now.

- Machine: the development laptop (Intel Core, Windows 11, no GPU), PostgreSQL 16 on the same
  machine, freshly seeded `demo` preset (74 mines, 1.7 M sensor readings), no other load (the PPE
  training run was not running during either measurement), no browser tab polling the API.
- Accounts: `gov@dgms.gov.in` and the mine head of Bhubaneswari (mine 5, the demo's high-risk mine).
- 2026-09-27: "before" at the start of the work, "after" once it was done.

## Before and after

All times in milliseconds, median / p95.

| Screen (requests per cycle) | Before: `php -S`, 5 s polling | After: Apache, one request, 10 s polling |
|---|---|---|
| Government overview (2 → 1) | 374 / 493 | **42 / 46** |
| Government mine detail (7 → 1) | 843 / 876 | **32 / 39** |
| Mine head dashboard (7 → 1) | 853 / 895 | **33 / 37** |
| Government production (new, 1) | - | **45 / 47** |
| Mine head production (new, 1) | - | **18 / 20** |
| **Side by side:** government overview | 1,233 / 1,376 | **42 / 52** |
| **Side by side:** mine head dashboard | 1,235 / 1,350 | **33 / 34** |

Requests per minute, per open screen: government overview 14 → **6**, a mine screen 84 → **6**.

Before, the mine screens' seven requests queued behind each other in PHP's single-threaded
built-in server (150, 296, 403, ... 842 ms: each waited for all the ones before it), and a second
open dashboard doubled every wait.

## What changed, and what each part bought

1. **Apache instead of `php -S`** (`scripts/api_server.bat`: XAMPP's Apache with mod_php on port
   8080, a generated virtual host, XAMPP's own configuration untouched). Requests run in parallel,
   so two dashboards no longer queue behind each other. `api/serve.bat` remains the fallback.
2. **OPcache** (in the generated `php.ini`; `serve.bat` enables it too).
3. **Persistent PostgreSQL connections under Apache** (`api/config/db.php`). On Windows,
   PostgreSQL starts a process per connection: opening one cost 40-47 ms - about a third of a
   dashboard request - and the first queries on a new connection pay extra catalog loading
   (planning a query over the 25 monthly `sensor_reading` partitions took about 90 ms cold,
   1-2 ms warm). `/v1/dashboard` went from 147 ms to 41 ms with this alone.
4. **Schema and RBAC caching** (file cache; flushed by `yii migrate`, `yii seed`, `yii rbac/init`).
5. **Queries:**
   - `GET /v1/contractors/summary` scored every contractor mine by mine, 74 times (270 ms of PHP).
     It now does one pass (`ContractorService::evaluatePerMine`, with a unit test that it equals
     the per-mine result): 45 ms.
   - The sensor trend ran one query per sensor type. It now runs one LATERAL query on the same
     index, so it is planned once. The result is verified identical.
   - Alert lists loaded each alert's history separately (30 queries). They now load in one query
     (`StatusHistory::preload`).
6. **One request per screen** (`GET /v1/views/overview`, `/v1/views/mine/{id}`,
   `/v1/views/production`, `/v1/views/production-overview`). Each assembles the screen from the
   same actions that serve the individual endpoints, with their own permission and scope checks, so
   every part has exactly its endpoint's shape (`api/tests/api/ViewCest.php` asserts equality). The
   frontend polls these every 10 s. A tab also refetches the moment it becomes visible, so a
   hand-over between tabs is still immediate.

The same endpoint set on Apache before aggregation, for comparison (after steps 1-5):

| Screen | Old requests on Apache | One view request on Apache |
|---|---|---|
| Government overview | 2 requests, cycle 27 / 27 | 42 / 46 |
| Mine screen | 7 requests in parallel, cycle 15 / 17 | 33 / 37 |

On Apache the old seven requests run in parallel and finish in about the time of one, so the
aggregated endpoint is not faster there. What it buys is **load**: one PHP request and one
authentication per cycle instead of seven, and 6 requests a minute per screen instead of 84. It
also makes the fallback server usable:

| `php -S` fallback, after steps 2, 4 and 5 | Old requests | One view request |
|---|---|---|
| Government overview | 150 / 158 | 110 / 114 |
| Mine screen | 375 / 405 | 112 / 116 |
| Side by side, government / mine head | 515 / 616, 516 / 643 | 221 / 232, 223 / 231 |

## After Phase 5 (grievances)

Same method, 2026-09-27: government grievances **44 / 52**, mine head grievances **14 / 15**
(one request each). The other screens measured 22-56 ms median in the same run (p95 at most 86 ms
for the government overview). They are a few milliseconds slower than above, partly because a
mine head's alert and audit queries now also exclude sensitive grievances. Side by side:
52 / 59 and 42 / 50.

## After Phase 5B (obligation register and map)

Same method, 2026-09-28, freshly seeded demo, Apache, median / p95 in ms over 12 cycles:

| New screen (one request per cycle) | median / p95 |
|---|---|
| Government obligations (`/views/obligations`) | **87 / 90** |
| Corporate SECL obligations | **82 / 85** |
| Mine head obligations | **73 / 76** |
| Government map (`/views/map`, with open-alert counts) | **41 / 46** |
| Mine head map | **34 / 37** |
| Map outlines, first load (`/geo/states` 21 / 26 + `/geo/districts` 58 / 62, limited to scope; then revalidated by ETag) | 58 / 63 |

**This run was on battery power** (discharging, 27 % down to 5 %), and everything was slower than
in the Phase 5 run, not only the new screens. Government overview 151 / 157 (was 42-56 / 86),
government production 157 / 158, mine screen 117-124 / 133-150, mine head grievances 41 / 43
(was 14 / 15).
To tell a code regression from the machine, the Phase 5 commit and the current code were served
side by side with `php -S` against the same database, in the same power state: the government
overview took 333 and 332 ms, `/dashboard` 236 and 234 ms, the grievance view 227 and 227 ms.
The Phase 5B changes do not slow the existing screens; the machine was about three times slower
(the same `php -S` overview measured 110 ms in the table above). The new screens stay under 150 ms
even so. Rerun `node scripts/perf_check.mjs` on mains power for numbers comparable with the
earlier sections.

What keeps the register fast: the obligation check runs once per request with targeted queries
(late tasks, due-soon groups, stale reminders), and new periods' tasks are generated once a day.
The roll-up is a single grouped query per (mine, domain), summed in PHP and cached until any task
or submission changes (a version counter) or the hour turns. A mine head's register is two
queries. The first government request after a change or a new hour recomputes the roll-up:
124-135 ms (measured three times, on battery), then 86-95 ms. After a full cache flush
(`yii cache/flush-all`, which also drops the schema cache) the first request took 547 ms, once.

## In the browser

The Phase 2, 3 and 4 browser checks (and later the Phase 5 and 5B ones) were rerun with a government overview and a mine-head
dashboard open and polling in two more tabs for the whole run (`node scripts/browser_check.mjs
<phase> --side-tabs`): all steps passed, and the side tabs made their polls without a failure
(for example 17 and 14 API requests during the 90 s Phase 2 run).

## Caveats

- The first request on each Apache thread opens its connection and loads the catalog: about
  120 ms, once per thread after a restart. The next requests take 40-50 ms.
- The API keeps up to 24 PostgreSQL connections open (one per Apache thread; `max_connections`
  is 100).
- Measured on one development machine; not a load test.
