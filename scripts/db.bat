@echo off
rem Start / stop the native PostgreSQL 16 + PostGIS cluster (docs/SETUP_WINDOWS.md).
rem It runs as a normal user process, not a Windows service.
rem   scripts\db.bat start | stop | status | wait | psql [database]
rem start  launches the server in its own hidden console (so closing any other window never stops
rem        it) and waits until it accepts connections - exit code 1 with the log tail if it does not.
rem stop   is a clean "fast" shutdown: connections are closed and a checkpoint is written, so the
rem        data directory is always consistent afterwards.
rem Override the locations with PG_HOME, PGDATA, PG_LOG and PG_PORT.
setlocal EnableExtensions
if not defined PG_HOME set "PG_HOME=D:\tools\pgsql"
if not defined PGDATA set "PGDATA=D:\tools\pgdata16"
if not defined PG_LOG set "PG_LOG=D:\tools\pglog\postgres.log"
if not defined PG_PORT set "PG_PORT=5432"
set "PGCTL=%PG_HOME%\bin\pg_ctl.exe"
set "PGREADY=%PG_HOME%\bin\pg_isready.exe"

if not exist "%PGCTL%" (
  echo [X] pg_ctl not found at %PGCTL%
  echo     Set PG_HOME or install PostgreSQL as described in docs\SETUP_WINDOWS.md.
  exit /b 1
)
if not exist "%PGDATA%\PG_VERSION" (
  echo [X] No database cluster at %PGDATA% - see docs\SETUP_WINDOWS.md step 1.
  exit /b 1
)

if /i "%~1"=="start" goto :start
if /i "%~1"=="stop" goto :stop
if /i "%~1"=="status" goto :status
if /i "%~1"=="wait" goto :wait
if /i "%~1"=="psql" goto :psql
echo Usage: scripts\db.bat start ^| stop ^| status ^| wait ^| psql [database]
exit /b 2

:start
"%PGREADY%" -h 127.0.0.1 -p %PG_PORT% -q
if not errorlevel 1 (
  echo [ok] PostgreSQL is already accepting connections on port %PG_PORT%.
  exit /b 0
)
"%PGCTL%" -D "%PGDATA%" status >nul 2>&1
if errorlevel 1 (
  echo Starting PostgreSQL - log: %PG_LOG%
  powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%PGCTL%' -ArgumentList @('-D','%PGDATA%','-l','%PG_LOG%','start') -WindowStyle Hidden"
)
goto :wait

:wait
set /a TRIES=0
:waitloop
"%PGREADY%" -h 127.0.0.1 -p %PG_PORT% -q
if errorlevel 1 goto :notready
rem Hidden processes may be held on the efficiency cores by Windows: opt PostgreSQL out (no_throttle.ps1).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0no_throttle.ps1" -Names postgres -Quiet
echo [ok] PostgreSQL is accepting connections on port %PG_PORT%.
exit /b 0
:notready
set /a TRIES+=1
if %TRIES% GEQ 30 goto :startfailed
ping -n 2 127.0.0.1 >nul
goto :waitloop

:startfailed
echo [X] PostgreSQL did not accept connections within 30 seconds.
echo     Last lines of %PG_LOG%:
powershell -NoProfile -Command "if (Test-Path '%PG_LOG%') { Get-Content '%PG_LOG%' -Tail 15 }"
echo     Common causes: port %PG_PORT% taken by another PostgreSQL, a stale postmaster.pid after a
echo     crash (the log says so; delete %PGDATA%\postmaster.pid only if no postgres.exe is running),
echo     or missing DLLs (docs\SETUP_WINDOWS.md, PostGIS pitfall).
exit /b 1

:stop
"%PGCTL%" -D "%PGDATA%" status >nul 2>&1
if errorlevel 1 (
  echo [ok] PostgreSQL is not running.
  exit /b 0
)
"%PGCTL%" -D "%PGDATA%" -m fast -w -t 60 stop
exit /b %ERRORLEVEL%

:status
"%PGCTL%" -D "%PGDATA%" status
"%PGREADY%" -h 127.0.0.1 -p %PG_PORT%
exit /b %ERRORLEVEL%

:psql
set "DB=%~2"
if "%DB%"=="" set "DB=coalshield"
"%PG_HOME%\bin\psql.exe" -h 127.0.0.1 -p %PG_PORT% -U coalshield -d %DB%
exit /b %ERRORLEVEL%
