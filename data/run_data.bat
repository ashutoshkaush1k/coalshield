@echo off
setlocal EnableExtensions

REM ===========================================================================
REM  data\run_data.bat - the single entry point for the CoalShield dataset track.
REM
REM  Usage:
REM    data\run_data.bat setup      create data\.venv, install pinned packages,
REM                                 extract the repo's 74 mines to data\reference
REM    data\run_data.bat download   stage D2 - fetch every non-manual source
REM    data\run_data.bat clean      stage D3 - build data\reference from data\raw
REM    data\run_data.bat generate   stage D4 - write synthetic data to data\out
REM    data\run_data.bat validate   stage D5 - schema, FK, calibration checks
REM    data\run_data.bat all        every stage above, in order, stopping at a failure
REM
REM  Runs from any directory: every path is resolved from this file's location.
REM  A stage that is not built yet says which brief stage delivers it and exits
REM  non-zero, so "all" stops visibly instead of pretending to finish.
REM
REM  Note for editors: keep "(", ")" and "&" out of IF blocks. cmd ends a
REM  parenthesised block at the first bare ")" - the same rule run_all.bat follows.
REM ===========================================================================

set "DATA=%~dp0"
set "PY=%DATA%.venv\Scripts\python.exe"
set "STAGE=%~1"

if "%STAGE%"=="" goto :usage
if /i "%STAGE%"=="--help"   goto :usage
if /i "%STAGE%"=="-h"       goto :usage
if /i "%STAGE%"=="/?"       goto :usage
if /i "%STAGE%"=="setup"    goto :run_setup
if /i "%STAGE%"=="download" goto :run_download
if /i "%STAGE%"=="clean"    goto :run_clean
if /i "%STAGE%"=="generate" goto :run_generate
if /i "%STAGE%"=="validate" goto :run_validate
if /i "%STAGE%"=="all"      goto :run_all
echo  Unknown stage "%STAGE%".
call :usage
exit /b 1

REM --- dispatch --------------------------------------------------------------
:run_setup
call :setup
goto :done

:run_download
call :require_venv
if errorlevel 1 goto :done
call :download
goto :done

:run_clean
call :require_venv
if errorlevel 1 goto :done
call :clean
goto :done

:run_generate
call :require_venv
if errorlevel 1 goto :done
call :generate
goto :done

:run_validate
call :require_venv
if errorlevel 1 goto :done
call :validate
goto :done

:run_all
call :setup
if errorlevel 1 goto :done
call :download
if errorlevel 1 goto :done
call :clean
if errorlevel 1 goto :done
call :generate
if errorlevel 1 goto :done
call :validate
goto :done

:done
if errorlevel 1 goto :failed
echo.
echo  [ok] %STAGE% finished.
exit /b 0

:failed
echo.
echo  [X] %STAGE% stopped at the failure above. Fix it, then re-run: data\run_data.bat %STAGE%
exit /b 1

REM --- stages ----------------------------------------------------------------
:setup
echo.
echo  [setup] Python virtual environment: data\.venv
if not exist "%PY%" python -m venv "%DATA%.venv"
if not exist "%PY%" goto :no_python
echo  [setup] Installing pinned packages - data\requirements.txt, constrained by data\constraints.txt
"%PY%" -m pip install --quiet --upgrade pip
if errorlevel 1 exit /b 1
"%PY%" -m pip install --quiet -r "%DATA%requirements.txt" -c "%DATA%constraints.txt"
if errorlevel 1 exit /b 1
echo  [setup] Extracting the repo's 74 mines - source S01
"%PY%" "%DATA%scripts\extract_mines_base.py"
if errorlevel 1 exit /b 1
exit /b 0

:download
echo.
echo  [download] Fetching every automatic source and checking manual folders - data\sources.yaml
"%PY%" "%DATA%scripts\download_all.py"
if errorlevel 1 exit /b 1
"%PY%" "%DATA%scripts\render_sources_md.py"
if errorlevel 1 exit /b 1
exit /b 0

:clean
call :pending clean D3
exit /b 1

:generate
call :pending generate D4
exit /b 1

:validate
call :pending validate D5
exit /b 1

REM --- helpers ---------------------------------------------------------------
:pending
echo.
echo  [%~1] Not built yet - it arrives in stage %~2 of the dataset brief.
exit /b 0

:require_venv
if exist "%PY%" exit /b 0
echo  [X] data\.venv not found. Run this first: data\run_data.bat setup
exit /b 1

:no_python
echo  [X] Could not create data\.venv - is Python 3 on PATH? Try: python --version
exit /b 1

:usage
echo.
echo  data\run_data.bat setup      create data\.venv, install packages, extract the 74 mines
echo  data\run_data.bat download   stage D2 - fetch every non-manual source
echo  data\run_data.bat clean      stage D3 - build data\reference from data\raw
echo  data\run_data.bat generate   stage D4 - write synthetic data to data\out
echo  data\run_data.bat validate   stage D5 - schema, FK and calibration checks
echo  data\run_data.bat all        every stage in order, stopping at the first failure
echo.
exit /b 0
