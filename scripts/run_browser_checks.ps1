<#
.SYNOPSIS
  Every browser check, one after the other, each on a freshly reset demo board (Phase 8).

.DESCRIPTION
  Runs scripts\demo_reset.bat, then node scripts\browser_check.mjs <phase> for phases 2 to 7B, and
  resets the board again at the end. Screenshots go to docs\screenshots\<phase>\ as usual; a summary
  (phase, exit code, minutes, the last lines) is written to docs\screenshots\browser_checks.txt.
  Needs the stack running (run_all.bat) and, for phase 7B, the field app built (run_field.bat or
  npm run build in frontend).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\run_browser_checks.ps1
  powershell -ExecutionPolicy Bypass -File scripts\run_browser_checks.ps1 -Phases phase7,phase7b
#>
[CmdletBinding()]
param([string[]]$Phases = @('phase2', 'phase3', 'phase4', 'phase5', 'phase5b', 'phase6', 'phase7', 'phase7b'))

$ErrorActionPreference = 'Continue'
$Root = Split-Path -Parent $PSScriptRoot
$Summary = Join-Path $Root 'docs\screenshots\browser_checks.txt'
$Flags = @{ phase6 = @('--side-tabs', '--strict'); phase7 = @('--side-tabs'); phase7b = @('--strict') }
$lines = @("Browser checks, $(Get-Date -Format 'yyyy-MM-dd HH:mm')", '')
$failed = 0
Set-Location $Root
foreach ($phase in $Phases) {
    & cmd /c "scripts\demo_reset.bat" | Out-Null
    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    $extra = if ($Flags.ContainsKey($phase)) { $Flags[$phase] } else { @('--side-tabs') }
    # Through cmd: PowerShell 5 rewrites "--" arguments on their way to a native program.
    $out = & cmd /c "node scripts\browser_check.mjs $phase $($extra -join ' ') 2>&1" | ForEach-Object { "$_" }
    $code = $LASTEXITCODE
    if ($code -ne 0) { $failed++ }
    $tail = ($out | Select-Object -Last 3) -join ' | '
    $line = '{0,-8} {1,-6} {2,5:n1} min  {3}' -f $phase, $(if ($code -eq 0) { 'ok' } else { "FAIL $code" }), $watch.Elapsed.TotalMinutes, $tail
    Write-Host $line
    $lines += $line
}
& cmd /c "scripts\demo_reset.bat" | Out-Null
$lines += ''
$lines += $(if ($failed) { "$failed phase(s) failed" } else { 'all phases passed' })
Set-Content -Path $Summary -Value $lines -Encoding utf8
exit $failed
