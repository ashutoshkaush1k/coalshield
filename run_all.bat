@echo off
setlocal EnableExtensions

REM ===========================================================================
REM  run_all.bat - starts the CoalShield stack in one action.
REM
REM    1. Checks the project is set up: PHP, api\vendor, frontend\node_modules
REM    2. Starts PostgreSQL through scripts\db.bat if it is not running, and
REM       waits until it accepts connections; stops with a clear message if not
REM    3. First run only: migrations, RBAC and "yii seed demo" (about 30 s),
REM       and an API key for the simulator
REM    4. Starts the API on 8080 through XAMPP's Apache, scripts\api_server.bat,
REM       falling back to a SIH-API window with api\serve.bat; opens windows
REM       SIH-AI on 8001 (PPE vision),
REM       SIH-Frontend on 5173 (React), and SIH-Simulator with --sim
REM    5. Waits until the API and the frontend listen, then opens the dashboard
REM
REM  Usage:
REM    run_all.bat           start the stack
REM    run_all.bat --sim     also replay live sensor data through the API
REM    run_all.bat --stop    close the SIH-* windows and stop PostgreSQL cleanly
REM    run_all.bat --help    show this summary
REM
REM  Stopping: closing the SIH-* windows never touches the database - PostgreSQL
REM  runs in its own hidden console. It stops only through run_all.bat --stop or
REM  scripts\db.bat stop, a "fast" shutdown that writes a checkpoint first.
REM  The old FastAPI stack is no longer started here; see docs\SETUP_WINDOWS.md
REM  ("Falling back to the FastAPI prototype").
REM
REM  Note for editors: no "(", ")" or "&" inside IF blocks - cmd ends a block at
REM  the first bare ")", so an innocent echo breaks the whole block.
REM ===========================================================================

set "ROOT=%~dp0"
set "WITH_SIM="
if not defined PHP set "PHP=C:\xampp\php\php.exe"

if /i "%~1"=="--sim"  set "WITH_SIM=1"
if /i "%~1"=="--stop" goto :stop
if /i "%~1"=="--help" goto :usage
if /i "%~1"=="-h"     goto :usage
if /i "%~1"=="/?"     goto :usage

echo.
echo  CoalShield - starting the stack
echo  ===============================
echo.

REM --- pre-flight ------------------------------------------------------------
if not exist "%PHP%" (
    echo  [X] PHP not found at %PHP% - install XAMPP or set PHP to php.exe.
    goto :fail
)
if not exist "%ROOT%api\vendor\autoload.php" (
    echo  [X] API dependencies missing. Run once:
    echo          cd api
    echo          D:\tools\composer\composer.bat install
    goto :fail
)
if not exist "%ROOT%api\.env" (
    echo  [X] api\.env missing - copy api\.env.example and fill it in, see docs\SETUP_WINDOWS.md.
    goto :fail
)
if not exist "%ROOT%frontend\node_modules" (
    echo  [X] Frontend dependencies missing. Run once:  cd frontend  then  npm install
    goto :fail
)
echo  [ok] PHP, API and frontend dependencies found

REM --- database ----------------------------------------------------------------
call "%ROOT%scripts\db.bat" start
if errorlevel 1 (
    echo.
    echo  [X] PostgreSQL is not available, so nothing else was started.
    goto :fail
)

"%PHP%" "%ROOT%api\yii" seed/status >nul 2>&1
if errorlevel 1 goto :firstrun
goto :apikey

:firstrun
echo  First run: creating the schema and loading the demo data - about 30 seconds...
if not exist "%ROOT%data\out\demo\_manifest.json" (
    echo  [X] data\out\demo is missing. Generate it once:  data\run_data.bat demo
    goto :fail
)
"%PHP%" "%ROOT%api\yii" migrate --interactive=0 >nul
if errorlevel 1 goto :dbfail
"%PHP%" "%ROOT%api\yii" rbac/init >nul
"%PHP%" "%ROOT%api\yii" seed demo
if errorlevel 1 goto :dbfail

:apikey
"%PHP%" "%ROOT%api\yii" migrate --interactive=0 >nul
if errorlevel 1 goto :dbfail
if not exist "%ROOT%scripts\.simulator.key" "%PHP%" "%ROOT%api\yii" api-key/issue simulator --out="%ROOT%scripts\.simulator.key" >nul
echo  [ok] Database migrated and seeded
REM Contractor alerts due today (licence, training, medicals, documents, worker limit) - idempotent.
"%PHP%" "%ROOT%api\yii" contractor/check >nul
echo  [ok] Contractor alerts checked
REM Close past production periods and run the detailed-report deadlines - idempotent.
"%PHP%" "%ROOT%api\yii" production/check >nul
echo  [ok] Production periods and detailed-report deadlines checked

REM --- port checks -------------------------------------------------------------
REM netstat prints the state after the address, so the port comes first.
netstat -ano | findstr /r /c:":8080 .*LISTENING" >nul 2>&1
if not errorlevel 1 echo  [!] Port 8080 is already in use - by the API from an earlier start, or an old SIH-API window.
netstat -ano | findstr /r /c:":5173 .*LISTENING" >nul 2>&1
if not errorlevel 1 echo  [!] Port 5173 is already in use - close any old SIH-Frontend window.

REM --- launch ------------------------------------------------------------------
echo  Starting API       - Apache with mod_php and OPcache, port 8080...
call "%ROOT%scripts\api_server.bat" start
if not errorlevel 1 goto :apiup
echo  [!] Apache did not start - falling back to PHP's built-in server, window SIH-API.
echo  [!] It serves one request at a time, so dashboards will be slower. docs\PERFORMANCE.md
start "SIH-API" /d "%ROOT%api" cmd /k "serve.bat"
:apiup

if exist "%ROOT%backend\.venv\Scripts\python.exe" goto :checkweights
echo  [!] backend\.venv not found - PPE vision is off; uploads will answer 503.
goto :startfrontend
:checkweights
if exist "%ROOT%backend\ml\weights\ppe.pt" goto :startai
echo.
echo  [!] ================================================================
echo  [!]  PPE model weights missing: backend\ml\weights\ppe.pt
echo  [!]  PPE detection falls back to the test fixture - real photos
echo  [!]  will show no detections. Rebuild the weights, about 25 min:
echo  [!]    backend\.venv\Scripts\python.exe scripts\build_ppe_model.py
echo  [!]  See docs\AI_EVALUATION.md.
echo  [!] ================================================================
echo.
:startai
echo  Starting AI service - window SIH-AI, port 8001...
start "SIH-AI" /d "%ROOT%" cmd /k "ai-service\run_ai_service.bat"

:startfrontend
echo  Starting frontend  - window SIH-Frontend, port 5173...
start "SIH-Frontend" /d "%ROOT%frontend" cmd /k "npm run dev"

if not defined WITH_SIM goto :waitstart
set "SIMPY=python"
if exist "%ROOT%backend\.venv\Scripts\python.exe" set "SIMPY=%ROOT%backend\.venv\Scripts\python.exe"
echo  Starting simulator - window SIH-Simulator, 2 s ticks, looping...
start "SIH-Simulator" /d "%ROOT%" cmd /k ""%SIMPY%" scripts\run_simulator.py --interval 2 --loop"

:waitstart
echo.
echo  Waiting for the API and the frontend...
set /a ATTEMPT=0

:waitloop
set /a ATTEMPT+=1
set "API_UP="
set "FRONTEND_UP="
netstat -ano | findstr /r /c:":8080 .*LISTENING" >nul 2>&1
if not errorlevel 1 set "API_UP=1"
netstat -ano | findstr /r /c:":5173 .*LISTENING" >nul 2>&1
if not errorlevel 1 set "FRONTEND_UP=1"
if defined API_UP if defined FRONTEND_UP goto :ready
if %ATTEMPT% GEQ 25 goto :slow
REM ping is the sleep: timeout fails when stdin is redirected.
ping -n 3 127.0.0.1 >nul
goto :waitloop

:slow
echo  [!] Servers are taking longer than usual.
if not defined API_UP      echo      API on port 8080 is not listening yet - check the SIH-API window.
if not defined FRONTEND_UP echo      Frontend on port 5173 is not listening yet - check the SIH-Frontend window.
goto :open

:ready
echo  [ok] API and frontend are both listening.

:open
echo  Opening http://localhost:5173
start "" "http://localhost:5173"
echo.
echo  Done. Sign in with gov@dgms.gov.in / demo123 - demo accounts only.
echo  Stop everything with:  run_all.bat --stop
if not defined WITH_SIM echo  Tip: run_all.bat --sim also replays live sensor data.
echo.
ping -n 6 127.0.0.1 >nul
exit /b 0

:stop
echo  Closing the SIH-* windows...
for %%W in (SIH-Simulator SIH-Frontend SIH-AI SIH-API) do taskkill /fi "WINDOWTITLE eq %%W*" /t /f >nul 2>&1
call "%ROOT%scripts\api_server.bat" stop
call "%ROOT%scripts\db.bat" stop
exit /b %ERRORLEVEL%

:usage
echo.
echo  run_all.bat           start PostgreSQL if needed, the API, the AI service and the frontend
echo  run_all.bat --sim     also replay live sensor data through the API
echo  run_all.bat --stop    close the SIH-* windows and stop PostgreSQL cleanly
echo  run_all.bat --help    show this message
echo.
exit /b 0

:dbfail
echo.
echo  [X] Database setup failed - see the messages above.
goto :fail

:fail
echo.
echo  Startup aborted.
echo.
pause
exit /b 1
