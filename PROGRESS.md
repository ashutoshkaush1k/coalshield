# Progress (governance backend, branch `feat/governance-backend`)

Brief: `CLAUDE_CODE_TASK.md`. Plan and decisions: `PLAN.md`. Data: `data/HANDOFF.md`.

## Phase 1: Foundation (done, 2026-09-27)

### Done

- **Toolchain on Windows, no admin rights needed:** PostgreSQL 16.15 and PostGIS 3.6.2 as portable
  binaries, Composer 2.10.3, and XAMPP `php.ini` with pdo_pgsql, pgsql, intl and sodium (backup
  taken). Recorded in `docs/SETUP_WINDOWS.md`. Start and stop the database with `scripts\db.bat`.
- **`api/` scaffold:** Yii2 2.0.55 as a pure JSON API with module `v1`.
  - `ApiController`: CORS for the Vite origin, JWT bearer auth, verb filter.
  - `ApiErrorHandler`: `{"error": {"code", "params"?, "fields"?}}` on every error, with a debug block only when `YII_DEBUG` is on.
  - `ListingQuery`: `page`/`per_page`/`limit`, `filter[]` and `sort` with whitelists; `X-Total-Count`.
  - Secrets live only in `api/.env` (`.env.example` committed). The log never dumps `$_SERVER`, and 4xx responses are not logged.
- **Migrations**, all reversible, tested down and up:
  - extensions;
  - `subsidiary`, `area`, `mine` (PostGIS Point/Polygon with SRID 4326, GIST and trigram indexes);
  - `user` (lower-case roles; a CHECK keeps each role's scope columns consistent);
  - `audit_log` (hash chain in SQL, append-only trigger);
  - `file`;
  - RBAC (`yii\rbac` migrations) and queue (`yii2-queue` db migrations);
  - every FK indexed.
- **Components:**
  - `ScopedActiveQuery` / `ScopedActiveRecord::findScoped()`: the only place mine scoping happens. Out of scope gives 404.
  - `AuditBehavior` + `AuditChain`: insert, update and delete are logged with changed values only, and `password_hash` is redacted. `yii audit/verify` recomputes the chain.
  - `StatusTransition` + `HasStatusTransitions`: 422 `INVALID_TRANSITION` with `{from, to}`.
  - `FileStorage`: outside `web/`, sha256, size limit, MIME checked with finfo.
- **RBAC:** `DbManager` roles `government`, `corporate`, `mine_head` and `inspector` (defined, reads like government), with per-action permissions in `config/rbac.php`. `yii rbac/init` is idempotent and assignments are synced from `user.role`.
- **Endpoints:** `POST /v1/auth/login`, `GET /v1/users/me` (+ `/v1/auth/me` alias), `PATCH /v1/users/me` (language only), `GET /v1/mines`, `GET /v1/mines/{id}`, `GET /v1/health`.
- **`yii seed [preset]`:** loads `data/out/<preset>/*.csv` in HANDOFF order with PostgreSQL COPY.
  - Runs in one transaction.
  - Fails on any column mismatch.
  - Refuses a preset whose `_validation.json` failed.
  - Checks row counts against `_manifest.json`.
  - Hashes the demo password.
  - Resets sequences and assigns roles.
  - Writes one `seed` audit entry as the chain's genesis.
  - Tables from later phases are skipped with a note. No data is generated in PHP.
- **Tests (Codeception, 57 tests, 216 assertions), all passing:**
  - login;
  - `/users/me`;
  - scoping per role, including that out-of-scope is identical to missing;
  - listing conventions;
  - audit chain insert/update/delete, redaction, the append-only trigger, and tamper detection (edited row; edited row with recomputed hash);
  - StatusTransition;
  - FileStorage;
  - seeder (counts, hashing, SRID, roles, sequences, column mismatch rolls back, refuses an invalid preset).
- **Frontend:**
  - i18next + react-i18next with `src/i18n/` (`en.json`), `t()` / `useT()` / `errorMessage()`.
  - `roles.js` is lower case and normalises the old upper-case roles.
  - Footer "Demo data: synthetic, calibrated to public statistics — see DATASETS.md" on every page and the login page.
  - A "demo value" tag on every compliance score (board, national average, priority queue, mine detail, mine head overview).
- **Docker fallback:** `docker-compose.yml`, `api/Dockerfile` and `docker/postgres/init/`, written but not run (see Known issues).

### Pending (next phases)

- Phase 2:
  - port mines/sensors/violations/corrective actions/inspections/alerts/audit/compliance;
  - the incident module (48-hour reporting check with its obligation code);
  - the demo-score test (100/80/70/60/45, 6/21/47, average 83.2) after `yii seed demo`;
  - switch the frontend to `VITE_API_URL`;
  - update the demo script, login quick-fill labels, tests and docs to the new mine names and to 404 for out-of-scope records (`docs/API_CHANGES.md` G4, G7).
- Phases 3–8 as in `PLAN.md`. The old `backend/` stays until Phase 8 confirms parity.

### Known issues

- **Docker path not verified:** Docker Desktop is not installed on the development machine.
- **PostgreSQL is not a service:** it runs as a user process, so start it after each reboot with `scripts\db.bat start`.
- **Mine names:** the login quick-fill labels still use the old mine names ("Talcher", "Jharia"), because the frontend still talks to the old backend, whose seed uses them. They change with the Phase 2 switch.
- **`inspector` reads like government:** it has no screen of its own yet; the route guard shows "no screen for this role" instead of redirecting in a loop.

### How to verify

```bat
scripts\db.bat start
cd api
yii.bat migrate --interactive=0
yii.bat rbac/init
yii.bat seed demo
yii.bat audit/verify
run_tests.bat
serve.bat
```

Then, in another terminal: `curl http://127.0.0.1:8080/v1/health`. To log in, send
`POST /v1/auth/login` with `{"email":"corporate.secl@coalmine.in","password":"demo123"}`. Call
`GET /v1/mines` with the returned token: `X-Total-Count` is SECL's mine count, and any other
company's mine id gives 404. The frontend (`run_all.bat`, still on the old backend) shows the footer
and the demo-value tags.

## TODO-VERIFY register

No new regulatory facts were introduced in Phase 1. The items left open by the data track are listed in
`data/HANDOFF.md` ("Open TODO-VERIFY items"):

- EPF Act status;
- the RPT-08 production return;
- the OpenAQ licence;
- glossary and grievance translations;
- the CO sensor limit.

`PLAN.md` §8 lists the items for later phases (grievance SLAs, document due day, and others). Each
will be carried here when its phase starts.
