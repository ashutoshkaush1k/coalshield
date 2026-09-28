# Native Windows setup (API track)

How the development machine was set up for `api/` (Yii2 + PostgreSQL 16 + PostGIS), and how to
repeat it. Everything here runs **without administrator rights**: no installer, no Windows service,
no UAC prompt. Docker is the portable alternative (see the end), but it is not installed on the
reference machine, so the Docker path is **not verified yet**.

Reference machine, 2026-09-27: Windows 11, XAMPP PHP 8.2.12 (`C:\xampp\php`), no Docker.

| Component | Version | Where |
|---|---|---|
| PostgreSQL | 16.15 (EDB binaries zip, build 16.15-4) | `D:\tools\pgsql` |
| PostGIS | 3.6.2 (OSGeo bundle for PG16) | copied into `D:\tools\pgsql` |
| Database cluster | UTF8, `--no-locale`, scram-sha-256, port 5432, localhost only | `D:\tools\pgdata16` |
| Server log | | `D:\tools\pglog\postgres.log` |
| Composer | 2.10.3 (`composer.phar`) | `D:\tools\composer` |
| Generated passwords | superuser and app role, one per file | `D:\tools\secrets\` (outside the repo) |

The paths are only defaults; `scripts\db.bat` reads `PG_HOME`, `PGDATA` and `PG_LOG` if you use
others.

## 1. PostgreSQL 16 (portable binaries)

1. Download `postgresql-16.15-4-windows-x64-binaries.zip` from EDB's binaries page
   (<https://www.enterprisedb.com/download-postgresql-binaries>; direct link
   `https://get.enterprisedb.com/postgresql/postgresql-16.15-4-windows-x64-binaries.zip`).
2. Extract it so that `D:\tools\pgsql\bin\postgres.exe` exists (the zip has a top-level `pgsql\`
   folder; extract into `D:\tools`).
3. Put a random superuser password in a file, then create the cluster (PowerShell):

   ```powershell
   New-Item -ItemType Directory -Force D:\tools\secrets, D:\tools\pglog | Out-Null
   -join ((48..57 + 65..90 + 97..122) | Get-Random -Count 24 | ForEach-Object {[char]$_}) | Set-Content -NoNewline D:\tools\secrets\pg_superuser.txt
   D:\tools\pgsql\bin\initdb.exe -D D:\tools\pgdata16 -U postgres -E UTF8 --no-locale -A scram-sha-256 --pwfile=D:\tools\secrets\pg_superuser.txt
   Add-Content D:\tools\pgdata16\postgresql.conf "`nlisten_addresses = 'localhost'`nport = 5432"
   ```

4. Start it: `scripts\db.bat start` (stop: `scripts\db.bat stop`, check: `scripts\db.bat status`).

PostgreSQL runs as a **normal user process**, not a service: start it again after every reboot.
To avoid that you can register a service with `pg_ctl register` from an elevated prompt; that step
needs an administrator and is optional.

## 2. PostGIS 3.6.2

1. Download `postgis-bundle-pg16-3.6.2x64.zip` and its `.md5` from
   <https://download.osgeo.org/postgis/windows/pg16/> and check the checksum:

   ```powershell
   (Get-FileHash postgis-bundle-pg16-3.6.2x64.zip -Algorithm MD5).Hash   # 6730107131C29B7FF9A36702F736007B
   ```

2. Extract it and copy its `bin`, `lib`, `share` (and `gdal-data`, `utils` if present) folders into
   `D:\tools\pgsql`, **without overwriting files that already exist**:

   ```powershell
   robocopy <bundle>\bin D:\tools\pgsql\bin /E /XC /XN /XO
   robocopy <bundle>\lib D:\tools\pgsql\lib /E /XC /XN /XO
   robocopy <bundle>\share D:\tools\pgsql\share /E /XC /XN /XO
   ```

   **Pitfall seen on the reference machine:** a plain copy replaced PostgreSQL's own
   `libcrypto-3-x64.dll`, `libssl-3-x64.dll`, `libcurl.dll`, `libiconv-2.dll`, `liblz4.dll`,
   `libzstd.dll` and `zlib1.dll` with the bundle's builds. `CREATE EXTENSION pgcrypto` then failed
   with *"The specified procedure could not be found"*. Fix: stop the server and extract those seven
   files again from the PostgreSQL zip.

## 3. Database, role and extensions

As the superuser (password in `D:\tools\secrets\pg_superuser.txt`); put a second random password in
`D:\tools\secrets\pg_app.txt` for the application role:

```powershell
$env:PGPASSWORD = Get-Content D:\tools\secrets\pg_superuser.txt
$app = Get-Content D:\tools\secrets\pg_app.txt
D:\tools\pgsql\bin\psql.exe -h 127.0.0.1 -U postgres -c "CREATE ROLE coalshield LOGIN PASSWORD '$app'"
foreach ($db in 'coalshield', 'coalshield_test') {
  D:\tools\pgsql\bin\psql.exe -h 127.0.0.1 -U postgres -c "CREATE DATABASE $db OWNER coalshield ENCODING 'UTF8' TEMPLATE template0"
  D:\tools\pgsql\bin\psql.exe -h 127.0.0.1 -U postgres -d $db -c "CREATE EXTENSION postgis; CREATE EXTENSION pgcrypto; CREATE EXTENSION pg_trgm;"
}
Remove-Item Env:PGPASSWORD
```

PostGIS is not a "trusted" extension, so the superuser creates it once per database. The first
migration runs `CREATE EXTENSION IF NOT EXISTS` for all three (a no-op here), and rolling it back
leaves the extensions in place.

## 4. PHP (XAMPP) extensions

`C:\xampp\php\php.ini` was backed up to `C:\xampp\php\php.ini.bak-2026-09-27`, then these lines were
uncommented:

```ini
extension=intl
extension=pdo_pgsql
extension=pgsql
extension=sodium
```

Check with `C:\xampp\php\php.exe -m` (the list must include `pdo_pgsql`, `pgsql`, `intl`,
`sodium`). To undo, copy the backup back over `php.ini`. Apache is not used by the API.

## 5. Composer

Installed from the official installer after checking its SHA-384 against
<https://composer.github.io/installer.sig>:

```powershell
New-Item -ItemType Directory -Force D:\tools\composer | Out-Null
C:\xampp\php\php.exe -r "copy('https://getcomposer.org/installer', 'D:/tools/composer/composer-setup.php');"
# compare (Get-FileHash D:\tools\composer\composer-setup.php -Algorithm SHA384).Hash with installer.sig, then:
C:\xampp\php\php.exe D:\tools\composer\composer-setup.php --install-dir=D:\tools\composer
Remove-Item D:\tools\composer\composer-setup.php
Set-Content D:\tools\composer\composer.bat @('@echo off', '"C:\xampp\php\php.exe" "%~dp0composer.phar" %*')
```

Use `D:\tools\composer\composer.bat` (or add `D:\tools\composer` to your user `PATH`).

## 6. The API

```bat
cd api
copy .env.example .env
rem edit .env: DB_PASSWORD = contents of D:\tools\secrets\pg_app.txt,
rem            JWT_SECRET  = output of: C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"
D:\tools\composer\composer.bat install
yii.bat migrate --interactive=0
yii.bat rbac/init
yii.bat seed demo
yii.bat audit/verify
run_tests.bat
..\scripts\api_server.bat start
```

- `yii.bat` / `yii_test.bat` run the console against the development / test database.
- `yii seed <preset>` loads `data/out/<preset>/*.csv`. Generate a preset first with
  `data\run_data.bat small|demo|full` (see `data/HANDOFF.md`). `run_tests.bat` seeds `demo` into
  the test database (about 25 s, so the demo-score test checks the real numbers) and generates it
  if it is missing.
- `scripts\api_server.bat start` serves the API through XAMPP's Apache on
  <http://127.0.0.1:8080> (check `/v1/health`); see "6a" below. `serve.bat` (PHP's built-in
  server) is the fallback - it handles one request at a time, so it is slow with a dashboard open.
- Real environment variables win over `.env` (phpdotenv immutable mode); Docker uses this to point
  `DB_HOST` at the `db` container.

### 6a. The API under Apache (mod_php, OPcache)

`scripts\api_server.bat start | stop | restart | status | config` (a wrapper around
`scripts\api_server.ps1`). No administrator rights and no change to XAMPP's own files:

- `config` writes `api\runtime\apache\httpd.conf` - one virtual host for `api\web` on
  127.0.0.1:8080 (every path that is not a file goes to `index.php`, like `serve.bat`), only the
  modules the API needs, its own pid and `error.log` - and `api\runtime\apache\php.ini`, which is
  XAMPP's `php.ini` plus OPcache and a larger realpath cache. `start` rewrites both first, so
  moving the repository needs no manual step. XAMPP's `httpd.conf` and `php.ini` are untouched;
  the XAMPP control panel's Apache (port 80) is unaffected.
- Apache runs as a normal user process with a hidden console, like PostgreSQL; `stop` ends it.
- Under Apache the API keeps its PostgreSQL connections open between requests
  (`api/config/db.php`, persistent PDO; 24 threads, so at most 24 connections).
- Schema and RBAC caching use `api\runtime\cache\`; `yii migrate`, `yii seed` and `yii rbac/init`
  flush it, and `yii cache/flush-all` does it by hand.

`run_all.bat` starts it and falls back to a `SIH-API` window with `serve.bat` if Apache does not
come up; `run_all.bat --stop` stops it. Numbers: `docs/PERFORMANCE.md`.

## 7. Everyday start and stop: run_all.bat

```bat
run_all.bat
run_all.bat --sim
run_all.bat --stop
```

`run_all.bat` (add `--sim` for the live sensor replay):

1. Checks PHP, `api\vendor`, `api\.env` and `frontend\node_modules`.
2. **Starts PostgreSQL** through `scripts\db.bat start` if it is not already accepting
   connections, then waits (up to 30 s, polling `pg_isready`). If it does not come up, it stops
   with the last lines of `D:\tools\pglog\postgres.log` and the usual causes, and starts nothing
   else.
3. First run only: migrations, RBAC and `yii seed demo`, and an API key for the simulator
   (`scripts\.simulator.key`). Later runs only apply pending migrations.
4. Runs every scheduled job once, `yii jobs/all` (about 2 s; see 7a). They are idempotent, so
   a restart never raises an alert twice.
5. Starts the API under Apache on 8080 (`scripts\api_server.bat`; a `SIH-API` window with
   `serve.bat` if Apache fails), opens `SIH-AI` (8001, when `backend\.venv` exists; warns if the
   PPE weights are missing), `SIH-Frontend` (5173) and, with `--sim`, `SIH-Simulator`; waits for
   the ports and opens the dashboard.

`run_all.bat --stop` closes the `SIH-*` windows, stops Apache and stops PostgreSQL.

**Stopping never corrupts the database.** `db.bat start` launches PostgreSQL in its own hidden
console, so closing any `SIH-*` window (or the window that ran `run_all.bat`) does not touch it.
It stops only through `run_all.bat --stop` or `scripts\db.bat stop`, a `fast` shutdown: open
connections are closed and a checkpoint is written, and the next start logs `database system was
shut down` (clean) rather than a recovery. Even a power cut is survivable: PostgreSQL replays its
write-ahead log on the next start. The one thing to avoid is deleting `postmaster.pid` while a
`postgres.exe` is still running.

`scripts\db.bat status | start | stop | wait | psql` also work on their own.

## 7a. Scheduled jobs (Phase 7)

The work that happens by the clock - reminders, deadlines, escalations, the daily scores, the
anomaly detectors - is a set of console commands, `api\yii.bat jobs/<name>`:

| Job | What it does | Registered schedule |
|---|---|---|
| `reminders` | expiring contractor documents, obligations due soon, yesterday's production not submitted (`PRODUCTION_ENTRY_PENDING`) | daily 07:00 |
| `sla` | grievance response times and detailed-report deadlines | every 15 min |
| `escalate-alerts` | an open, unacknowledged alert goes to level 1 after 24 h and level 2 after 72 h (`rules.yaml` product.alert_escalation) | every 15 min |
| `score` | the day's compliance score and Governance Risk Index per mine (history), and the predicted risk | daily 06:30 |
| `anomaly` | the seven detectors (ai-service, PHP fallback): new findings raise `ANOMALY_DETECTED`, findings no longer made are cleared | hourly |
| `contractor` | contractor alerts | hourly |
| `obligation` | the obligation register: new tasks, overdue, escalation | hourly |
| `production` | locks past periods, detailed-report deadlines | hourly |
| `grievance` | grievance deadlines | hourly |

`yii jobs/all` runs them all in that order; `yii jobs/status` shows each one's last run.

- **Idempotent.** Run twice, the second run finds nothing to do (`JobsTest` checks it).
- **Logged.** Every run is a row in `job_run` (status, summary, duration, error) and a line in
  `api\runtime\logs\jobs.log`.
- **Never twice at once.** Each job holds a PostgreSQL advisory lock; a copy started while another
  runs records `skipped` and exits.
- **A system action.** Status history and the audit trail show no user for what a job did.

**Registering them in Windows Task Scheduler** (current user, no administrator rights):

```powershell
powershell -ExecutionPolicy Bypass -File scripts\register_tasks.ps1 -DryRun      # show what it would register
powershell -ExecutionPolicy Bypass -File scripts\register_tasks.ps1              # register (or update)
Get-ScheduledTask -TaskName 'SmartMineGovernance - jobs-*'                       # check
powershell -ExecutionPolicy Bypass -File scripts\register_tasks.ps1 -Unregister  # remove them all
```

The tasks are named `SmartMineGovernance - jobs-<name>` and run as you, only while you are logged
on (logon type Interactive - no stored password). They need PostgreSQL running, so on a laptop
start it with `run_all.bat` first; a job that cannot reach the database is logged as `failed` and
the next run tries again. The PHP path defaults to `C:\xampp\php\php.exe` (set `$env:PHP` before
registering to change it).

On a server the same commands run from cron or a service account instead, e.g.
`*/15 * * * * cd /srv/api && php yii jobs/sla`.

## 7b. The field app for phones (Phase 7B)

`run_field.bat` builds the app for phones (`npm run build:field`: the API through the same origin) and
serves it with `scripts\field_server.mjs`: HTTPS on the LAN (port 5443) with a local certificate made
by `node scripts\make_cert.mjs` (no admin rights; `certs\` is git-ignored), and plain HTTP on
`localhost:5180` for this PC and for phones over USB port forwarding. Phone setup, click by click:
`docs/FIELD_APP_SETUP.md`. No scheduled task is needed: the phones sync when their users tap **Sync**.

## 8. Where the secrets live, and how to regenerate them

Nothing secret is in the repository. The files, all outside git:

| File | Holds | Used by |
|---|---|---|
| `D:\tools\secrets\pg_superuser.txt` | password of the PostgreSQL superuser `postgres` | setup only (roles, databases, PostGIS) |
| `D:\tools\secrets\pg_app.txt` | password of the application role `coalshield` | copied into `api\.env` |
| `api\.env` (git-ignored) | `DB_PASSWORD` (= `pg_app.txt`), `JWT_SECRET` (signs tokens and file links) | the API and its console |
| `scripts\.simulator.key` (git-ignored) | the simulator's API key; the database stores only its SHA-256 | `scripts\run_simulator.py` |
| `frontend\.env` (git-ignored) | no secret, only `VITE_API_URL` | Vite |

Regenerate them (PowerShell, from the repository root; `api\.env` is edited by hand):

```powershell
# 1. New application-role password: write it, apply it, then copy it into api\.env as DB_PASSWORD
-join ((48..57 + 65..90 + 97..122) | Get-Random -Count 24 | ForEach-Object {[char]$_}) | Set-Content -NoNewline D:\tools\secrets\pg_app.txt
$env:PGPASSWORD = Get-Content D:\tools\secrets\pg_superuser.txt
D:\tools\pgsql\bin\psql.exe -h 127.0.0.1 -U postgres -c "ALTER ROLE coalshield PASSWORD '$(Get-Content D:\tools\secrets\pg_app.txt)'"
Remove-Item Env:PGPASSWORD

# 2. New JWT secret: paste the output into api\.env as JWT_SECRET
#    (signs everyone out and invalidates signed file links)
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# 3. New simulator key (the old one stops working at once)
cd api; .\yii.bat api-key/issue simulator --out=..\scripts\.simulator.key; cd ..
```

The superuser password changes the same way (`ALTER ROLE postgres PASSWORD '...'`, connected with
the old one).

**Check that no secret file is tracked** (worth running before any commit you are unsure about):

```bat
git ls-files | findstr /r /i "\.env$ \.key$ secret pg_app pg_superuser"
```

```bat
git check-ignore -v api/.env frontend/.env backend/.env scripts/.simulator.key
```

The first must print nothing; the second must list all four files with the `.gitignore` rule
that ignores them. Result on 2026-09-27: no secret file tracked, none anywhere in the git history
(`git log --all --diff-filter=A --name-only`), and all four ignored (`api/.gitignore` for
`api/.env`, the root `.gitignore` for the others).

## 9. Falling back to the FastAPI prototype

The old stack is still in `backend/` (kept until Phase 8 confirms parity), but `run_all.bat` no
longer starts it and the frontend now speaks the new contract. To run the prototype as it was,
use a separate worktree at the last commit before the switch (Phase 1, `b026164`):

```bat
git worktree add ..\SIH_prototype b026164
cd ..\SIH_prototype
powershell -ExecutionPolicy Bypass -File scripts\setup.ps1
run_all.bat
```

That tree's `run_all.bat` starts FastAPI on 8000 and its own frontend on 5173; stop the new stack
first (`run_all.bat --stop`) or the ports collide. Remove it afterwards with
`git worktree remove ..\SIH_prototype`. The prototype's own tests still run in this tree:
`backend\.venv\Scripts\python.exe -m pytest backend/tests`.

## 10. Terminal pitfalls

- Running `pg_ctl start` from a tool that captures output (some IDE terminals, CI runners) can
  hang: the server inherits the output pipe. `scripts\db.bat start` avoids this by starting the
  server in a hidden console of its own (PowerShell `Start-Process -WindowStyle Hidden`).
- Git Bash strips backslashes in unquoted heredocs; write Windows paths in `.bat` files with an
  editor or PowerShell.

## 11. PPE detection weights

The ai-service uses `backend\ml\weights\ppe.pt`, a YOLO11n model fine-tuned on the S13 PPE dataset.
The weights are not in git (Ultralytics AGPL-3.0 and size); rebuild them once per machine:

```bat
data\run_data.bat download
backend\.venv\Scripts\python.exe scripts\build_ppe_model.py
```

The first line fetches S13 if `data\raw\ppe\dataset` is missing. The second trains on the CPU
(about 20 minutes), evaluates on the held-out test split, installs `ppe.pt` and rewrites the
results in `docs/AI_EVALUATION.md`. Without the weights `run_all.bat` prints a warning box and the
ai-service answers with its test fixture. `set PPE_DETECTOR=fixture` before starting it forces
the fixture even when the weights exist.

## Docker alternative (not verified)

`docker-compose.yml` at the repository root starts `postgis/postgis:16-3.4` (with the test
database and extensions from `docker/postgres/init/`) and the API on port 8080:

```bash
docker compose up -d db api
docker compose exec api php yii seed demo
```

Docker Desktop is not installed on the reference machine, so this path has not been run yet
(tracked in `PROGRESS.md`).
