<#
.SYNOPSIS
  Before a demo: is everything ready? Prints READY, or a numbered list of what to fix (Phase 8).

.DESCRIPTION
  Checks, in plain language: the database, the API, the ai-service and its model, the dashboard,
  the field app and its certificate for this PC's current address, the supervisor, fonts served
  from this machine (no internet needed), Windows sleep on mains power, free disk space, the demo
  scores and the audit chain. It changes nothing: every fix is a command you run yourself.

.EXAMPLE
  scripts\predemo_check.bat
#>
[CmdletBinding()]
param([int]$MinFreeGB = 2)

$ErrorActionPreference = 'Continue'
$Root = Split-Path -Parent $PSScriptRoot
$Php = if ($env:PHP) { $env:PHP } else { 'C:\xampp\php\php.exe' }
$PgBin = Join-Path $(if ($env:PG_HOME) { $env:PG_HOME } else { 'D:\tools\pgsql' }) 'bin'
$problems = New-Object System.Collections.Generic.List[string]
$notes = New-Object System.Collections.Generic.List[string]

function Ok([string]$What) { Write-Host ("  ok   " + $What) -ForegroundColor Green }
function Bad([string]$What, [string]$Fix) { Write-Host ("  !!   " + $What) -ForegroundColor Red; $problems.Add("$What`n       Fix: $Fix") }
function Note([string]$What) { Write-Host ("  i    " + $What) -ForegroundColor Yellow; $notes.Add($What) }

function Get-Json([string]$Url, [int]$Timeout = 5) {
    foreach ($u in @($Url, ($Url -replace '127\.0\.0\.1', '[::1]'))) {
        try { return Invoke-RestMethod -Uri $u -TimeoutSec $Timeout } catch {}
        if ($Url -notlike '*127.0.0.1*') { break }
    }
    return $null
}
function Test-Http([string]$Url) {
    foreach ($u in @($Url, ($Url -replace '127\.0\.0\.1', '[::1]'))) {
        try { $r = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 5; if ($r.StatusCode -lt 400) { return $true } } catch {}
        if ($Url -notlike '*127.0.0.1*') { break }
    }
    return $false
}

Write-Host ''
Write-Host 'Pre-demo check'
Write-Host '=============='

# 1. Database
& (Join-Path $PgBin 'pg_isready.exe') -h 127.0.0.1 -p 5432 -q
if ($LASTEXITCODE -eq 0) { Ok 'PostgreSQL is accepting connections' }
else { Bad 'PostgreSQL is not running.' 'run_all.bat (it starts the database first), or scripts\db.bat start' }

# 2. API
$health = Get-Json 'http://127.0.0.1:8080/v1/health'
if ($health -and $health.status -eq 'ok') { Ok 'API answers on port 8080 and reaches the database' }
elseif ($health) { Bad "The API answers but reports '$($health.database)' for the database." 'scripts\db.bat start, then check api\runtime\logs' }
else { Bad 'The API is not answering on port 8080.' 'run_all.bat; if Apache will not start, see the SIH-API window' }

$token = $null
if ($health) {
    try {
        $login = Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:8080/v1/auth/login' -ContentType 'application/json' -TimeoutSec 10 `
            -Body '{"email":"gov@dgms.gov.in","password":"demo123"}'   # the demo account (demo-only password)
        $token = $login.access_token
        Ok 'The demo account can sign in (gov@dgms.gov.in)'
    } catch { Bad 'The demo government account cannot sign in.' 'scripts\demo_reset.bat (restores the demo accounts)' }
}

# 3. ai-service and its models
$weights = Join-Path $Root 'ai-service\ml\weights\ppe.pt'
if (Test-Path $weights) { Ok 'PPE model weights are present (ai-service\ml\weights\ppe.pt)' }
else { Bad 'The PPE model weights are missing: photo analysis would find nothing.' 'ai-service\.venv\Scripts\python.exe scripts\build_ppe_model.py (about 25 min), docs\SETUP_WINDOWS.md 11' }
if (Test-Path (Join-Path $Root 'ai-service\risk\model.json')) { Ok 'Predictive model is present (ai-service\risk\model.json)' }
else { Bad 'The predictive model file is missing.' 'git checkout ai-service/risk/model.json, or retrain: ai-service\.venv\Scripts\python ai-service\risk\train.py' }
$ai = Get-Json 'http://127.0.0.1:8001/health'
if ($ai -and $ai.status -eq 'ok') {
    if ($ai.backend -eq 'yolo') { Ok 'ai-service answers on port 8001 with the real PPE model' }
    else { Bad "ai-service is running on the '$($ai.backend)' test detector, not the PPE model." 'check the weights above, then close the SIH-AI window (the supervisor restarts it)' }
} else { Bad 'The ai-service is not answering: detection runs on the PHP fallback and photo analysis is off.' 'run_all.bat, or open a window: ai-service\run_ai_service.bat' }
if ($token) {
    try {
        $status = Invoke-RestMethod -Uri 'http://127.0.0.1:8080/v1/system/status' -Headers @{ Authorization = "Bearer $token" } -TimeoutSec 10
        if ($status.detection_engine -eq 'ai-service') { Ok 'The API uses the ai-service for detection (no fallback warning on screen)' }
        else { Bad "The dashboards will show the 'built-in checks' warning ($($status.reason))." 'start the ai-service; with AI_ENGINE=php in api\.env remove that line' }
    } catch {}
}

# 4. Dashboard and field app
if (Test-Http 'http://127.0.0.1:5173/') { Ok 'Dashboard (frontend) answers on port 5173' }
else { Bad 'The dashboard is not answering on port 5173.' 'run_all.bat, or in frontend: npm run dev' }
if (Test-Path (Join-Path $Root 'frontend\dist\index.html')) {
    if (Test-Http 'http://127.0.0.1:5180/field') { Ok 'Field app server answers (http://localhost:5180/field)' }
    else { Bad 'The field app server is not running.' 'run_field.bat' }
} else { Note 'Field app not built - only needed for the phone demo (run_field.bat).' }

# 5. Certificate for the phones (HTTPS on the LAN)
$crtPath = Join-Path $Root 'certs\server.crt'
$lan = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object {
        $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.PrefixOrigin -ne 'WellKnown' } | ForEach-Object IPAddress)
if (Test-Path $crtPath) {
    $crt = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 $crtPath
    $san = ($crt.Extensions | Where-Object { $_.Oid.Value -eq '2.5.29.17' } | ForEach-Object { $_.Format($false) }) -join ', '
    $missing = @($lan | Where-Object { $san -notmatch [regex]::Escape($_) })
    if ($crt.NotAfter -lt (Get-Date)) { Bad "The HTTPS certificate expired on $($crt.NotAfter.ToString('d MMM yyyy'))." 'node scripts\make_cert.mjs, then restart run_field.bat' }
    elseif ($missing.Count) { Bad "The HTTPS certificate does not cover this PC's current address ($($missing -join ', ')): phones will see a warning." 'node scripts\make_cert.mjs, then restart run_field.bat (phones keep the same CA)' }
    else { Ok ("HTTPS certificate valid until {0} for this PC's address ({1})" -f $crt.NotAfter.ToString('d MMM yyyy'), ($lan -join ', ')) }
} else { Note 'No HTTPS certificate - phones can still use USB forwarding (docs\FIELD_APP_SETUP.md 4).' }

# 6. Supervisor
$sup = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '*supervisor.ps1*' }
if ($sup) { Ok 'Supervisor is running (restarts a stopped service within about a minute)' }
else { Bad 'The supervisor is not running: a crashed service would stay down.' 'run_all.bat starts it (window SIH-Supervisor)' }

# 7. Fonts served from this machine
$pattern = 'fonts\.googleapis|fonts\.gstatic|use\.typekit|cdn\.jsdelivr\.net/.*font|cdnjs.*font'
$remote = @(Get-ChildItem (Join-Path $Root 'frontend\src'), (Join-Path $Root 'frontend\index.html') -Recurse -File -Include *.css, *.js, *.jsx, *.html -ErrorAction SilentlyContinue |
        Select-String -Pattern $pattern -List | ForEach-Object Path)
$fontsources = @(Get-ChildItem (Join-Path $Root 'frontend\node_modules\@fontsource') -Directory -ErrorAction SilentlyContinue).Count
if ($remote.Count) { Bad "Fonts are loaded from the internet in: $($remote -join ', ')." 'use the @fontsource packages (bundled) instead' }
elseif ($fontsources -lt 5) { Bad 'The bundled font packages are missing (frontend\node_modules\@fontsource).' 'cd frontend, then npm install' }
else { Ok "Fonts are bundled locally ($fontsources @fontsource packages, no web font links)" }

# 8. Power: no sleep on mains during the demo
function Get-AcSeconds([string]$Setting) {
    $out = powercfg /query SCHEME_CURRENT SUB_SLEEP $Setting 2>$null
    $line = $out | Where-Object { $_ -match 'AC' -and $_ -match '0x[0-9a-fA-F]{8}' } | Select-Object -First 1
    if ($line -match '(0x[0-9a-fA-F]{8})') { return [Convert]::ToInt32($Matches[1], 16) }
    return $null
}
$standby = Get-AcSeconds 'STANDBYIDLE'
$hibernate = Get-AcSeconds 'HIBERNATEIDLE'
if ($standby -eq 0 -and ($hibernate -eq 0 -or $hibernate -eq $null)) { Ok 'Windows will not sleep on mains power' }
elseif ($standby -eq $null) { Note 'Could not read the sleep setting (powercfg).' }
else {
    $when = @()
    if ($standby) { $when += ('sleeps after {0} min' -f [math]::Round($standby / 60)) }
    if ($hibernate) { $when += ('hibernates after {0} min' -f [math]::Round($hibernate / 60)) }
    Bad ("On mains power Windows " + ($when -join ' and ') + ': the laptop could sleep mid-demo.') 'powercfg /change standby-timeout-ac 0   and   powercfg /change hibernate-timeout-ac 0   (Settings > System > Power also works)'
}
$battery = Get-CimInstance Win32_Battery -ErrorAction SilentlyContinue | Select-Object -First 1
if ($battery -and $battery.BatteryStatus -ne 2) { Bad ("The laptop is on battery ({0}% left)." -f $battery.EstimatedChargeRemaining) 'plug in the charger' }
elseif ($battery) { Ok 'On mains power' }

# 9. Disk space
foreach ($drive in @((Split-Path -Qualifier $Root), 'C:') | Select-Object -Unique) {
    $d = Get-PSDrive ($drive.TrimEnd(':')) -ErrorAction SilentlyContinue
    if (-not $d) { continue }
    $free = [math]::Round($d.Free / 1GB, 1)
    if ($free -lt $MinFreeGB) { Bad "Only $free GB free on $drive." "free at least $MinFreeGB GB (logs, uploads and the database grow during a demo)" }
    else { Ok "$free GB free on $drive" }
}

# 10. Demo scores and the audit chain
if ($health) {
    $raw = & $Php (Join-Path $Root 'api\yii') demo/check --json 2>$null
    try { $demo = ($raw | Out-String) | ConvertFrom-Json } catch { $demo = $null }
    if ($demo -and $demo.ok) { Ok 'Demo scores are the baseline (100 / 80 / 70 / 60 / 45, fleet 83.2) and the audit chain is intact' }
    elseif ($demo) {
        $diff = @($demo.mines.PSObject.Properties | Where-Object { -not $_.Value.ok } | ForEach-Object { '{0} is {1}' -f $_.Name, $_.Value.score })
        $diff += @($demo.fleet.PSObject.Properties | Where-Object { -not $_.Value.ok } | ForEach-Object { '{0} is {1}' -f $_.Name, $_.Value.value })
        if (-not $demo.audit.ok) { $diff += 'the audit chain is broken' }
        Bad ('The demo board is not at its starting point: ' + ($diff -join '; ') + '.') 'scripts\demo_reset.bat (about 10 s)'
    } else { Bad 'Could not read the demo scores.' 'check the API and the database above' }
}

Write-Host ''
if ($problems.Count -eq 0) {
    Write-Host 'READY' -ForegroundColor Green
    foreach ($n in $notes) { Write-Host "  (note: $n)" }
    exit 0
}
Write-Host ("NOT READY - {0} thing(s) to fix:" -f $problems.Count) -ForegroundColor Red
$i = 0
foreach ($p in $problems) { $i++; Write-Host ("  {0}. {1}" -f $i, $p) }
foreach ($n in $notes) { Write-Host "  (note: $n)" }
exit 1
