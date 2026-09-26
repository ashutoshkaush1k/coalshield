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
serve.bat
```

- `yii.bat` / `yii_test.bat` run the console against the development / test database.
- `yii seed <preset>` loads `data/out/<preset>/*.csv`. Generate a preset first with
  `data\run_data.bat small|demo|full` (see `data/HANDOFF.md`); `run_tests.bat` generates `small`
  itself if it is missing.
- `serve.bat` starts PHP's built-in server on <http://127.0.0.1:8080> (check `/v1/health`).
- Real environment variables win over `.env` (phpdotenv immutable mode); Docker uses this to point
  `DB_HOST` at the `db` container.

## 7. Terminal pitfalls

- Running `pg_ctl start` from a tool that captures output (some IDE terminals, CI runners) can hang:
  the server inherits the output pipe. `scripts\db.bat start` redirects the server's output to the
  log file with `-l`; if a wrapper still hangs, start it detached:
  `Start-Process D:\tools\pgsql\bin\pg_ctl.exe -ArgumentList '-D','D:\tools\pgdata16','-l','D:\tools\pglog\postgres.log','start' -WindowStyle Hidden`.
- Git Bash strips backslashes in unquoted heredocs; write Windows paths in `.bat` files with an
  editor or PowerShell.

## Docker alternative (not verified)

`docker-compose.yml` at the repository root starts `postgis/postgis:16-3.4` (with the test
database and extensions from `docker/postgres/init/`) and the API on port 8080:

```bash
docker compose up -d db api
docker compose exec api php yii seed demo
```

Docker Desktop is not installed on the reference machine, so this path has not been run yet
(tracked in `PROGRESS.md`).
