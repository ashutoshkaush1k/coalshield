@echo off
setlocal EnableExtensions

REM ===========================================================================
REM  run_all.bat - starts the whole Smart Mine Governance stack in one action.
REM
REM  Double-click this file (or run it from a terminal). It will:
REM    1. Check the project is actually set up - venv and node_modules present
REM    2. Open a window titled SIH-Backend   running the FastAPI API, port 8000
REM    3. Open a window titled SIH-Frontend  running the React app,   port 5173
REM    4. Wait until both ports are really listening, then open the dashboard
REM
REM  Usage:
REM    run_all.bat           start backend + frontend
REM    run_all.bat --sim     also open SIH-Simulator, replaying live sensor data
REM    run_all.bat --help    show this summary
REM
REM  To shut everything down after a demo, close the SIH-* windows.
REM  First-time setup, once per machine:
REM      powershell -ExecutionPolicy Bypass -File scripts\setup.ps1
REM
REM  Note for editors: this script avoids "(", ")" and "&" inside IF blocks.
REM  cmd ends a parenthesised block at the first bare ")", so an innocent-looking
REM  echo such as "starting (port 8000)" silently breaks the whole block.
REM ===========================================================================

set "ROOT=%~dp0"
set "WITH_SIM="

if /i "%~1"=="--sim"  set "WITH_SIM=1"
if /i "%~1"=="-sim"   set "WITH_SIM=1"
if /i "%~1"=="/sim"   set "WITH_SIM=1"
if /i "%~1"=="--help" goto :usage
if /i "%~1"=="-h"     goto :usage
if /i "%~1"=="/?"     goto :usage

echo.
echo  Smart Mine Governance - starting the stack
echo  =========================================
echo.

REM --- pre-flight: fail loudly, never silently ---------------------------
if not exist "%ROOT%backend\.venv\Scripts\python.exe" (
    echo  [X] Python virtual environment not found.
    echo      Expected: %ROOT%backend\.venv\Scripts\python.exe
    echo.
    echo      Run this first, then try again:
    echo          powershell -ExecutionPolicy Bypass -File scripts\setup.ps1
    goto :fail
)
echo  [ok] Python virtual environment found

if not exist "%ROOT%frontend\node_modules" (
    echo  [X] Frontend dependencies not installed.
    echo      Expected: %ROOT%frontend\node_modules
    echo.
    echo      Run this first, then try again:
    echo          cd frontend
    echo          npm install
    goto :fail
)
echo  [ok] Frontend dependencies found

REM --- non-fatal warnings ------------------------------------------------
REM An empty database is the classic silent demo failure: the API starts fine
REM and the dashboard renders with no mines at all.
if not exist "%ROOT%backend\smartmine.db" (
    echo  [!] No database found - the dashboard will be empty.
    echo      Seed it first:
    echo          backend\.venv\Scripts\python.exe scripts\seed_db.py --reset
    echo.
)

REM Catches the most common repeat-run mistake: an old stack still holding a
REM port, which makes the new window die instantly with a confusing error.
REM Pattern note: netstat prints the state AFTER the address, so the port must
REM come first in the regex. Matching "LISTENING.*:8000" never fires.
netstat -ano | findstr /r /c:":8000 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo  [!] Port 8000 is already in use - a backend may already be running.
    echo      Close any old SIH-Backend window before continuing.
    echo.
)
netstat -ano | findstr /r /c:":5173 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo  [!] Port 5173 is already in use - a frontend may already be running.
    echo      Close any old SIH-Frontend window before continuing.
    echo.
)

REM --- launch ------------------------------------------------------------
REM "start /d <dir>" sets each window's working directory, which avoids quoting
REM a "cd" inside the command and keeps this working from paths with spaces.
echo  Starting backend  - window SIH-Backend, port 8000...
start "SIH-Backend" /d "%ROOT%backend" cmd /k ".venv\Scripts\python.exe -m uvicorn app.main:app --reload"

echo  Starting frontend - window SIH-Frontend, port 5173...
start "SIH-Frontend" /d "%ROOT%frontend" cmd /k "npm run dev"

if defined WITH_SIM (
    echo  Starting simulator - window SIH-Simulator, 2s ticks...
    start "SIH-Simulator" /d "%ROOT%" cmd /k "backend\.venv\Scripts\python.exe scripts\run_simulator.py --interval 2"
)

REM --- wait until both ports are actually listening ----------------------
REM A fixed sleep is a guess. The backend imports torch through the vision
REM module, so a cold start can outrun any number picked in advance - and then
REM the browser opens on a dead page, which is the failure this script exists
REM to prevent. Polling the ports removes the guess.
echo.
echo  Waiting for both servers to come up...
set /a ATTEMPT=0

:waitloop
set /a ATTEMPT+=1
set "BACKEND_UP="
set "FRONTEND_UP="
netstat -ano | findstr /r /c:":8000 .*LISTENING" >nul 2>&1
if not errorlevel 1 set "BACKEND_UP=1"
netstat -ano | findstr /r /c:":5173 .*LISTENING" >nul 2>&1
if not errorlevel 1 set "FRONTEND_UP=1"
if defined BACKEND_UP if defined FRONTEND_UP goto :ready
if %ATTEMPT% GEQ 25 goto :slow
REM ping is the sleep here, not timeout: timeout aborts with "Input redirection
REM is not supported" whenever this script is run with redirected stdin, such as
REM from another script or a CI job. ping behaves the same either way.
ping -n 3 127.0.0.1 >nul
goto :waitloop

:slow
echo  [!] Servers are taking longer than usual.
if not defined BACKEND_UP  echo      Backend on port 8000 is not listening yet - check the SIH-Backend window.
if not defined FRONTEND_UP echo      Frontend on port 5173 is not listening yet - check the SIH-Frontend window.
echo      Opening the browser anyway - refresh once they finish starting.
goto :open

:ready
echo  [ok] Backend and frontend are both listening.

:open
echo  Opening http://localhost:5173
start "" "http://localhost:5173"

echo.
echo  Done. Sign in with gov@dgms.gov.in / demo123
echo  Close the SIH-Backend and SIH-Frontend windows to shut the stack down.
echo.
if not defined WITH_SIM echo  Tip: run_all.bat --sim also starts the live sensor feed.
echo.
ping -n 6 127.0.0.1 >nul
exit /b 0

:usage
echo.
echo  run_all.bat           start backend + frontend, then open the dashboard
echo  run_all.bat --sim     also start the IoT simulator - live sensor replay
echo  run_all.bat --help    show this message
echo.
exit /b 0

:fail
echo.
echo  Startup aborted - nothing was launched.
echo.
pause
exit /b 1
