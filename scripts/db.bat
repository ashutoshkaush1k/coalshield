@echo off
rem Start / stop the native PostgreSQL 16 + PostGIS cluster (docs/SETUP_WINDOWS.md).
rem It runs as a normal user process, not a Windows service, so start it after each reboot.
rem   scripts\db.bat start | stop | status | psql [database]
rem Override the locations with PG_HOME, PGDATA and PG_LOG if you installed elsewhere.
setlocal
if not defined PG_HOME set "PG_HOME=D:\tools\pgsql"
if not defined PGDATA set "PGDATA=D:\tools\pgdata16"
if not defined PG_LOG set "PG_LOG=D:\tools\pglog\postgres.log"
set "PGCTL=%PG_HOME%\bin\pg_ctl.exe"

if not exist "%PGCTL%" (
  echo pg_ctl not found at %PGCTL% - set PG_HOME, see docs\SETUP_WINDOWS.md
  exit /b 1
)

if /i "%~1"=="start" goto :start
if /i "%~1"=="stop" goto :stop
if /i "%~1"=="status" goto :status
if /i "%~1"=="psql" goto :psql
echo Usage: scripts\db.bat start ^| stop ^| status ^| psql [database]
exit /b 2

:start
"%PGCTL%" -D "%PGDATA%" status >nul 2>&1 && (
  echo PostgreSQL is already running.
  exit /b 0
)
"%PGCTL%" -D "%PGDATA%" -l "%PG_LOG%" -w -t 60 start
exit /b %ERRORLEVEL%

:stop
"%PGCTL%" -D "%PGDATA%" -m fast -w stop
exit /b %ERRORLEVEL%

:status
"%PGCTL%" -D "%PGDATA%" status
exit /b %ERRORLEVEL%

:psql
set "DB=%~2"
if "%DB%"=="" set "DB=coalshield"
"%PG_HOME%\bin\psql.exe" -h 127.0.0.1 -U coalshield -d %DB%
exit /b %ERRORLEVEL%
