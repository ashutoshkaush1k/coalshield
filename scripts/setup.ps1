<#
.SYNOPSIS
  One-time project setup on Windows, after the tools are installed (docs/SETUP_WINDOWS.md 1-5):
  API dependencies and env file, frontend dependencies and env file, the ai-service's Python
  environment. Safe to run again: it skips what is already there.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\setup.ps1
  powershell -ExecutionPolicy Bypass -File scripts\setup.ps1 -SkipAi     # no Python / PPE vision
#>
[CmdletBinding()]
param([switch]$SkipAi)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path "$PSScriptRoot\.."
$composer = if ($env:COMPOSER) { $env:COMPOSER } else { 'D:\tools\composer\composer.bat' }
$php = if ($env:PHP) { $env:PHP } else { 'C:\xampp\php\php.exe' }

Write-Host '== API (api\): Composer packages and api\.env'
Push-Location "$root\api"
if (-not (Test-Path 'vendor\autoload.php')) { & $composer install --no-interaction }
if (-not (Test-Path '.env')) {
    Copy-Item '.env.example' '.env'
    # A fresh JWT secret from the system's cryptographic generator (32 bytes as 64 hex characters).
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $secret = -join ($bytes | ForEach-Object { $_.ToString('x2') })
    $lines = (Get-Content '.env') -replace '^JWT_SECRET=.*', "JWT_SECRET=$secret"
    # UTF-8 without a byte-order mark (PowerShell 5's -Encoding utf8 would add one, and dotenv reads it).
    [System.IO.File]::WriteAllLines((Join-Path (Get-Location) '.env'), $lines, (New-Object System.Text.UTF8Encoding $false))
    Write-Host '   api\.env created with a new JWT_SECRET; set DB_PASSWORD in it (docs\SETUP_WINDOWS.md 3).'
}
Pop-Location

Write-Host '== Frontend (frontend\): npm packages and frontend\.env'
Push-Location "$root\frontend"
if (-not (Test-Path 'node_modules')) { npm install }
if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env' }
Pop-Location

if (-not $SkipAi) {
    Write-Host '== ai-service (ai-service\.venv): Python packages (torch is large, the first run takes a while)'
    $venv = "$root\ai-service\.venv"
    if (-not (Test-Path "$venv\Scripts\python.exe")) { py -3 -m venv $venv }
    & "$venv\Scripts\python.exe" -m pip install --upgrade pip
    & "$venv\Scripts\python.exe" -m pip install -r "$root\ai-service\requirements.txt"
    if (-not (Test-Path "$root\ai-service\ml\weights\ppe.pt")) {
        Write-Host '   No PPE weights yet: PPE vision uses the test fixture until you build them (about 25 min):'
        Write-Host '     ai-service\.venv\Scripts\python.exe scripts\build_ppe_model.py   (docs\SETUP_WINDOWS.md 11)'
    }
}

Write-Host ''
Write-Host 'Done. Next: the demo data (data\run_data.bat setup, then generate demo), then run_all.bat.'
