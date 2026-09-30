<#
.SYNOPSIS
  Run a `yii` command against the ONLINE database and file storage (docs/DEPLOYMENT.md).

.DESCRIPTION
  Loads deploy\online.local.env (git-ignored; copy of deploy\online.env.example) into this process
  only, marks the run as online (ONLINE_TARGET=1, which the online/* commands require) and keeps a
  separate cache, then runs api\yii with the arguments given. Values are never printed. The laptop's
  own settings (api\.env) and database are not touched.

  scripts\online.bat migrate --interactive=0
  scripts\online.bat online/reset-data
#>
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$YiiArgs)
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$EnvFile = if ($env:ONLINE_ENV_FILE) { $env:ONLINE_ENV_FILE } else { Join-Path $Root 'deploy\online.local.env' }
$Php = if ($env:PHP) { $env:PHP } else { 'C:\xampp\php\php.exe' }

if (-not (Test-Path $EnvFile)) {
    Write-Host "[X] $EnvFile not found. Copy deploy\online.env.example to deploy\online.local.env and fill it in (docs\DEPLOYMENT.md)." -ForegroundColor Red
    exit 2
}
if (-not $YiiArgs) {
    Write-Host 'Usage: scripts\online.bat <yii command> [options]   e.g. scripts\online.bat online/reset-data'
    exit 2
}

foreach ($line in Get-Content $EnvFile) {
    $t = $line.Trim()
    if ($t -eq '' -or $t.StartsWith('#')) { continue }
    $i = $t.IndexOf('=')
    if ($i -lt 1) { continue }
    $name = $t.Substring(0, $i).Trim()
    $value = $t.Substring($i + 1).Trim()
    if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    [Environment]::SetEnvironmentVariable($name, $value, 'Process')
}

foreach ($required in 'DB_HOST', 'DB_USER', 'DB_PASSWORD') {
    if (-not [Environment]::GetEnvironmentVariable($required, 'Process')) {
        Write-Host "[X] $required is empty in $EnvFile." -ForegroundColor Red
        exit 2
    }
}
# Never the laptop's own database by mistake (ONLINE_ALLOW_LOCAL=1 only for a scratch copy).
if ($env:DB_HOST -in @('127.0.0.1', 'localhost', '::1') -and $env:ONLINE_ALLOW_LOCAL -ne '1') {
    Write-Host '[X] DB_HOST points at this laptop. The online commands run against the Supabase database only.' -ForegroundColor Red
    exit 2
}
if ($env:DB_HOST -in @('127.0.0.1', 'localhost', '::1') -and $env:DB_NAME -in @('coalshield', 'coalshield_test', '')) {
    Write-Host '[X] Refusing to touch the laptop demo or test database.' -ForegroundColor Red
    exit 2
}

$env:ONLINE_TARGET = '1'
$env:CACHE_DIR = '@runtime/cache-online'
Write-Host ("online: {0} (database {1})" -f ($YiiArgs -join ' '), $env:DB_NAME)
& $Php (Join-Path $Root 'api\yii') @YiiArgs
exit $LASTEXITCODE
