<#
.SYNOPSIS
  Puts the demo database back to "yii seed demo + yii jobs/all" in seconds (Phase 8).

.DESCRIPTION
  The first run (or when the seed has changed) builds the snapshot: migrations, RBAC, `yii seed demo`,
  `yii jobs/all`, a check of the demo baseline, then pg_dump to api\runtime\snapshots\demo.dump.
  Every run restores that snapshot with pg_restore and checks the result: the five demo mines'
  scores, the fleet's average and band split, and the audit chain (`yii demo/check`).

  "The seed has changed" means a different fingerprint of what the seed is made of: the generated
  data (data\out\demo\_manifest.json), the migrations, the seeder, the RBAC config and the rules
  (data\schema\*.yaml). -Rebuild forces a new snapshot (e.g. after changing a job's logic).

  The restore runs as the application role (no superuser): it replaces the application's tables,
  sequences and data and leaves the PostGIS extension alone. Other sessions of the application role
  (the API's requests, a job) are ended first, so a restore is never blocked by a lock; the
  dashboards reconnect on their next poll.

.EXAMPLE
  scripts\demo_reset.bat              restore (building the snapshot when needed)
  scripts\demo_reset.bat -Rebuild     rebuild the snapshot from the seed, then restore
  scripts\demo_reset.bat -Status      show the snapshot and whether it is current
#>
[CmdletBinding()]
param([switch]$Rebuild, [switch]$Status, [string]$Preset = 'demo')

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Api = Join-Path $Root 'api'
$Php = if ($env:PHP) { $env:PHP } else { 'C:\xampp\php\php.exe' }
$PgBin = Join-Path $(if ($env:PG_HOME) { $env:PG_HOME } else { 'D:\tools\pgsql' }) 'bin'
$SnapDir = Join-Path $Api 'runtime\snapshots'
$Dump = Join-Path $SnapDir "$Preset.dump"
$FpFile = Join-Path $SnapDir "$Preset.fingerprint"
New-Item -ItemType Directory -Force $SnapDir | Out-Null

# The database settings from api\.env (the password goes to PGPASSWORD, never to the screen).
$envFile = @{}
foreach ($line in Get-Content (Join-Path $Api '.env')) {
    if ($line -match '^\s*([A-Z_]+)\s*=\s*"?(.*?)"?\s*$') { $envFile[$Matches[1]] = $Matches[2] }
}
$DbHost = if ($envFile.DB_HOST) { $envFile.DB_HOST } else { '127.0.0.1' }
$DbPort = if ($envFile.DB_PORT) { $envFile.DB_PORT } else { '5432' }
$DbName = if ($envFile.DB_NAME) { $envFile.DB_NAME } else { 'coalshield' }
$DbUser = if ($envFile.DB_USER) { $envFile.DB_USER } else { 'coalshield' }
$env:PGPASSWORD = $envFile.DB_PASSWORD
$conn = @('-h', $DbHost, '-p', $DbPort, '-U', $DbUser)

function Step([string]$Text) { Write-Host ("  " + $Text) }
function Yii([string[]]$YiiArgs) {
    & $Php (Join-Path $Api 'yii') @YiiArgs
    if ($LASTEXITCODE -ne 0) { throw "yii $($YiiArgs -join ' ') failed" }
}

function Get-Fingerprint {
    $files = @((Join-Path $Root "data\out\$Preset\_manifest.json"), (Join-Path $Api 'commands\SeedController.php'), (Join-Path $Api 'config\rbac.php'))
    $files += Get-ChildItem (Join-Path $Api 'migrations') -Filter '*.php' | Sort-Object Name | ForEach-Object FullName
    $files += Get-ChildItem (Join-Path $Root 'data\schema') -Filter '*.yaml' | Sort-Object Name | ForEach-Object FullName
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $all = New-Object System.IO.MemoryStream
    foreach ($f in $files) {
        if (-not (Test-Path $f)) { throw "missing $f - generate the data first: data\run_data.bat generate $Preset" }
        $name = [System.Text.Encoding]::UTF8.GetBytes((Split-Path $f -Leaf) + "`n")
        $all.Write($name, 0, $name.Length)
        $bytes = [System.IO.File]::ReadAllBytes($f)
        $all.Write($bytes, 0, $bytes.Length)
    }
    return (($sha.ComputeHash($all.ToArray()) | ForEach-Object { $_.ToString('x2') }) -join '')
}

$fingerprint = Get-Fingerprint
$current = (Test-Path $Dump) -and (Test-Path $FpFile) -and ((Get-Content $FpFile -Raw).Trim() -eq $fingerprint)

if ($Status) {
    if (Test-Path $Dump) {
        $item = Get-Item $Dump
        Write-Host ("snapshot: {0} ({1:n1} MB, {2})" -f $Dump, ($item.Length / 1MB), $item.LastWriteTime)
    } else { Write-Host 'snapshot: none yet' }
    Write-Host $(if ($current) { 'current: yes - the seed has not changed' } else { 'current: no - the next reset rebuilds it' })
    exit 0
}

& (Join-Path $Root 'scripts\db.bat') start | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL is not running (scripts\db.bat start failed)' }
$watch = [System.Diagnostics.Stopwatch]::StartNew()

if ($Rebuild -or -not $current) {
    Write-Host 'Building the demo snapshot (the seed changed, or none yet): about a minute...'
    Step 'migrations, RBAC, seed'
    Yii @('migrate', '--interactive=0') | Out-Null
    Yii @('rbac/init') | Out-Null
    Yii @('seed', $Preset) | Out-Null
    Step 'every scheduled job once (yii jobs/all)'
    & $Php (Join-Path $Api 'yii') jobs/all | Out-Null   # a failed job is reported by demo/check below, not fatal here
    Step 'checking the baseline before saving it'
    Yii @('demo/check') | Out-Null
    Step 'pg_dump'
    $tmp = "$Dump.tmp"
    & (Join-Path $PgBin 'pg_dump.exe') @conn -d $DbName -Fc -Z 1 --no-owner --no-privileges -f $tmp
    if ($LASTEXITCODE -ne 0) { throw 'pg_dump failed' }
    Move-Item -Force $tmp $Dump
    Set-Content -Path $FpFile -Value $fingerprint -Encoding ascii
    Write-Host ("  snapshot saved: {0:n1} MB in {1:n0} s" -f ((Get-Item $Dump).Length / 1MB), $watch.Elapsed.TotalSeconds)
    $watch.Restart()
}

Write-Host 'Restoring the demo snapshot...'
$ErrorActionPreference = 'Continue'   # native tools write progress to stderr; their exit codes are checked
# The restore list without the extensions (PostGIS belongs to the superuser and stays as it is).
$list = Join-Path $SnapDir "$Preset.list"
& (Join-Path $PgBin 'pg_restore.exe') -l $Dump | Where-Object { $_ -notmatch ' EXTENSION ' -and $_ -notmatch 'spatial_ref_sys' } |
    Set-Content -Path $list -Encoding ascii
# End the application role's other sessions, so no lock holds the restore up, then drop the
# application's own tables, sequences and functions (never the extensions' objects).
$drop = @'
SET client_min_messages = warning;
SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity
 WHERE datname = current_database() AND usename = current_user AND pid <> pg_backend_pid();
DO $$
DECLARE r record;
BEGIN
  FOR r IN SELECT c.oid::regclass AS name FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
              AND pg_get_userbyid(c.relowner) = current_user LOOP
    EXECUTE 'DROP TABLE IF EXISTS ' || r.name || ' CASCADE';
  END LOOP;
  FOR r IN SELECT c.oid::regclass AS name FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'S' AND pg_get_userbyid(c.relowner) = current_user LOOP
    EXECUTE 'DROP SEQUENCE IF EXISTS ' || r.name || ' CASCADE';
  END LOOP;
  FOR r IN SELECT p.oid::regprocedure AS name FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND pg_get_userbyid(p.proowner) = current_user LOOP
    EXECUTE 'DROP FUNCTION IF EXISTS ' || r.name || ' CASCADE';
  END LOOP;
END $$;
'@
$drop | & (Join-Path $PgBin 'psql.exe') @conn -d $DbName -q -v ON_ERROR_STOP=1 -f - | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'could not clear the application tables before the restore' }
$out = & (Join-Path $PgBin 'pg_restore.exe') @conn -d $DbName --no-owner --no-privileges -j 4 -L $list $Dump 2>&1
$restoreCode = $LASTEXITCODE
Remove-Item $list -ErrorAction SilentlyContinue
if ($restoreCode -ne 0) {
    $out | ForEach-Object { Write-Host "  pg_restore: $_" }
    throw 'pg_restore failed - the database may be partly restored; run scripts\demo_reset.bat -Rebuild'
}
# pg_restore leaves the planner without statistics: gather them, or the first dashboard requests crawl.
"SET client_min_messages = error; ANALYZE;" | & (Join-Path $PgBin 'psql.exe') @conn -d $DbName -q -f - | Out-Null
& $Php (Join-Path $Api 'yii') cache/flush-all --interactive=0 | Out-Null   # schema cache, the footer's status
Write-Host ("  restored in {0:n1} s" -f $watch.Elapsed.TotalSeconds)

Write-Host 'Checking: demo scores and the audit chain'
& $Php (Join-Path $Api 'yii') demo/check
$ok = $LASTEXITCODE -eq 0
Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
if (-not $ok) {
    Write-Host 'The restored board is not the demo baseline. Rebuild it: scripts\demo_reset.bat -Rebuild' -ForegroundColor Red
    exit 1
}
Write-Host 'Demo board reset.' -ForegroundColor Green
