<#
.SYNOPSIS
  Registers the Phase 7 scheduled jobs (yii jobs/*) in Windows Task Scheduler for the current user.

.DESCRIPTION
  No administrator rights and no password: each task runs as you, only while you are logged on
  (the "Interactive" logon type), which is what a demo or a single-officer laptop needs. A server
  deployment would run the same commands from a service account or cron (docs/SETUP_WINDOWS.md).

  Tasks (all named "SmartMineGovernance - jobs-<name>"):
    every 15 minutes   sla, escalate-alerts
    every hour         anomaly, obligation, contractor, grievance, production
    every day 06:30    score        (the day's scores, Governance Risk Index, predictions)
    every day 07:00    reminders    (expiries, obligations due soon, yesterday's production)

  Every job is idempotent and skips itself if the previous run is still going, so an overlapping
  trigger or a manual run does no harm. Output goes to api\runtime\logs\jobs.log and the job_run
  table (api\yii.bat jobs/status).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\register_tasks.ps1            # register (or update)
  powershell -ExecutionPolicy Bypass -File scripts\register_tasks.ps1 -DryRun    # show what it would do
  powershell -ExecutionPolicy Bypass -File scripts\register_tasks.ps1 -Unregister
#>
[CmdletBinding()]
param(
    [switch]$Unregister,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Api = Join-Path $Root 'api'
$Php = if ($env:PHP) { $env:PHP } else { 'C:\xampp\php\php.exe' }
$Prefix = 'SmartMineGovernance - jobs-'

$Jobs = @(
    @{ Name = 'sla';             Every = 15;  Daily = $null },
    @{ Name = 'escalate-alerts'; Every = 15;  Daily = $null },
    @{ Name = 'anomaly';         Every = 60;  Daily = $null },
    @{ Name = 'obligation';      Every = 60;  Daily = $null },
    @{ Name = 'contractor';      Every = 60;  Daily = $null },
    @{ Name = 'grievance';       Every = 60;  Daily = $null },
    @{ Name = 'production';      Every = 60;  Daily = $null },
    @{ Name = 'score';           Every = $null; Daily = '06:30' },
    @{ Name = 'reminders';       Every = $null; Daily = '07:00' }
)

if ($Unregister) {
    $existing = Get-ScheduledTask -ErrorAction SilentlyContinue | Where-Object { $_.TaskName -like "$Prefix*" }
    foreach ($task in $existing) {
        if ($DryRun) { Write-Host "would remove  $($task.TaskName)"; continue }
        Unregister-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath -Confirm:$false
        Write-Host "removed  $($task.TaskName)"
    }
    if (-not $existing) { Write-Host 'No SmartMineGovernance tasks registered.' }
    exit 0
}

if (-not (Test-Path $Php)) { throw "PHP not found at $Php - set `$env:PHP to php.exe" }
if (-not (Test-Path (Join-Path $Api 'yii'))) { throw "api\yii not found under $Root" }

$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

foreach ($job in $Jobs) {
    $name = "$Prefix$($job.Name)"
    $action = New-ScheduledTaskAction -Execute $Php -Argument "yii jobs/$($job.Name)" -WorkingDirectory $Api
    if ($job.Every) {
        # Starts a minute from now and repeats for ten years (PowerShell 5.1 has no "indefinitely").
        $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
            -RepetitionInterval (New-TimeSpan -Minutes $job.Every) -RepetitionDuration (New-TimeSpan -Days 3650)
        $when = "every $($job.Every) min"
    } else {
        $trigger = New-ScheduledTaskTrigger -Daily -At $job.Daily
        $when = "daily at $($job.Daily)"
    }
    if ($DryRun) { Write-Host ("would register  {0,-45} {1,-16} {2} yii jobs/{3}" -f $name, $when, $Php, $job.Name); continue }
    Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -Principal $principal `
        -Description "Smart Mine Governance: yii jobs/$($job.Name) ($when). scripts\register_tasks.ps1" -Force | Out-Null
    Write-Host ("registered  {0,-45} {1}" -f $name, $when)
}
if (-not $DryRun) {
    Write-Host ''
    Write-Host "Done. Check with: Get-ScheduledTask -TaskName '$Prefix*'   and   api\yii.bat jobs/status"
}
