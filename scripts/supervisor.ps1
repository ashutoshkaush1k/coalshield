<#
.SYNOPSIS
  Keeps the stack up (Phase 8). Started by run_all.bat in the window SIH-Supervisor.

.DESCRIPTION
  Every 30 seconds it checks each service's health address. A service that fails two checks in a
  row (a minute; one slow answer during heavy work is not a failure) is restarted, and the
  restart is written to api\runtime\logs\supervisor.log - when it went down, why, and whether it
  came back. A service that will not come back is retried at most every 5 minutes, so a broken
  install does not loop.

    API          http://127.0.0.1:8080/v1/health   restarted with scripts\api_server.bat restart
                                                    (Apache), else a SIH-API window with api\serve.bat
    ai-service   http://127.0.0.1:8001/health      window SIH-AI (ai-service\run_ai_service.bat);
                                                    while it is down the API's detection uses the PHP
                                                    fallback and the dashboards' footer says so
    frontend     http://127.0.0.1:5173/            window SIH-Frontend (npm run dev)
    field server http://127.0.0.1:5180/field       window SIH-Field (only when frontend\dist is built,
                                                    run_field.bat)

  PostgreSQL is not restarted here: scripts\db.bat owns it, and a database that stops needs a look.
  run_all.bat --stop closes this window first, so nothing is restarted while stopping.

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts\supervisor.ps1
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts\supervisor.ps1 -Once    # one round, then exit
#>
[CmdletBinding()]
param(
    [int]$IntervalSeconds = 30,
    [switch]$Once
)

$ErrorActionPreference = 'Continue'
$Root = Split-Path -Parent $PSScriptRoot
$LogDir = Join-Path $Root 'api\runtime\logs'
$Log = Join-Path $LogDir 'supervisor.log'
New-Item -ItemType Directory -Force $LogDir | Out-Null
try { $Host.UI.RawUI.WindowTitle = 'SIH-Supervisor' } catch {}

function Write-Log([string]$Message) {
    $line = '{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Add-Content -Path $Log -Value $line -Encoding utf8
    Write-Host $line
}

function Test-Up([string]$Url) {
    # Vite and Node may listen on IPv6 only ([::1]) when started as "localhost": either address counts.
    $first = Test-One $Url
    if ($first.Up -or $Url -notlike '*127.0.0.1*') { return $first }
    $second = Test-One ($Url -replace '127\.0\.0\.1', '[::1]')
    if ($second.Up) { return $second }
    return $first
}

function Test-One([string]$Url) {
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 5
        return @{ Up = ($r.StatusCode -ge 200 -and $r.StatusCode -lt 400); Why = "HTTP $($r.StatusCode)" }
    } catch {
        $why = $_.Exception.Message
        if ($_.Exception.Response) { $why = "HTTP $([int]$_.Exception.Response.StatusCode)" }
        return @{ Up = $false; Why = $why }
    }
}

function Stop-Port([int]$Port) {
    Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique |
        ForEach-Object { if ($_ -gt 4) { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue } }
}

function Stop-Window([string]$Title) {
    & taskkill.exe /fi "WINDOWTITLE eq $Title*" /t /f 2>$null | Out-Null
}

function Start-Window([string]$Title, [string]$Dir, [string]$Command) {
    Start-Process -FilePath 'cmd.exe' -ArgumentList '/k', "title $Title && $Command" -WorkingDirectory $Dir -WindowStyle Minimized
}

$services = @(
    @{ Name = 'API'; Url = 'http://127.0.0.1:8080/v1/health'; Enabled = $true; Restart = {
        & (Join-Path $Root 'scripts\api_server.bat') restart | Out-Null
        if (-not (Test-Up 'http://127.0.0.1:8080/v1/health').Up) {
            Stop-Window 'SIH-API'; Stop-Port 8080
            Start-Window 'SIH-API' (Join-Path $Root 'api') 'serve.bat'
        }
    } },
    @{ Name = 'ai-service'; Url = 'http://127.0.0.1:8001/health'
        Enabled = (Test-Path (Join-Path $Root 'ai-service\.venv\Scripts\python.exe')); Restart = {
        Stop-Window 'SIH-AI'; Stop-Port 8001
        Start-Window 'SIH-AI' $Root 'ai-service\run_ai_service.bat'
    } },
    @{ Name = 'frontend'; Url = 'http://127.0.0.1:5173/'; Enabled = (Test-Path (Join-Path $Root 'frontend\node_modules')); Restart = {
        Stop-Window 'SIH-Frontend'; Stop-Port 5173
        Start-Window 'SIH-Frontend' (Join-Path $Root 'frontend') 'npm run dev'
    } },
    @{ Name = 'field server'; Url = 'http://127.0.0.1:5180/field'; Enabled = (Test-Path (Join-Path $Root 'frontend\dist\index.html')); Restart = {
        Stop-Window 'SIH-Field'; Stop-Port 5180; Stop-Port 5443; Stop-Port 5080
        Start-Window 'SIH-Field' $Root 'node scripts\field_server.mjs'
    } }
)
foreach ($s in $services) { $s.Failures = 0; $s.Restarts = 0; $s.NextTry = [datetime]::MinValue; $s.DownSince = $null }

Write-Log ("supervisor started: every {0} s; watching {1}" -f $IntervalSeconds, (($services | Where-Object { $_.Enabled } | ForEach-Object { $_.Name }) -join ', '))
while ($true) {
    # Every round: keep the stack out of Windows power throttling, including anything just
    # restarted and PostgreSQL's per-connection processes (scripts\no_throttle.ps1 says why).
    & (Join-Path $Root 'scripts\no_throttle.ps1') -Quiet
    foreach ($s in ($services | Where-Object { $_.Enabled })) {
        $check = Test-Up $s.Url
        if ($check.Up) {
            if ($s.DownSince) {
                Write-Log ("{0} is back up (down {1:n0} s)" -f $s.Name, ((Get-Date) - $s.DownSince).TotalSeconds)
                $s.DownSince = $null
            }
            $s.Failures = 0; $s.Restarts = 0
            continue
        }
        $s.Failures++
        if (-not $s.DownSince) { $s.DownSince = Get-Date }
        if ($s.Failures -lt 2 -or (Get-Date) -lt $s.NextTry) { continue }
        $s.Restarts++
        Write-Log ("{0} is down ({1}); restart {2}" -f $s.Name, $check.Why, $s.Restarts)
        & $s.Restart
        # Give it up to 60 s to answer again before the next round judges it.
        $back = $false
        for ($i = 0; $i -lt 12 -and -not $back; $i++) { Start-Sleep -Seconds 5; $back = (Test-Up $s.Url).Up }
        if ($back) {
            Write-Log ("{0} restarted and answering" -f $s.Name)
            $s.Failures = 0; $s.DownSince = $null; $s.Restarts = 0
        } else {
            $wait = 30
            if ($s.Restarts -ge 3) { $wait = 300 }
            $s.NextTry = (Get-Date).AddSeconds($wait)
            Write-Log ("{0} did not come back after restart {1}; next try in {2} s. See its window (SIH-*)." -f $s.Name, $s.Restarts, $wait)
        }
    }
    if ($Once) { break }
    Start-Sleep -Seconds $IntervalSeconds
}
