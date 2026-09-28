@echo off
rem ai-service (PPE vision, anomaly detectors, predicted risk) on http://127.0.0.1:8001. Uses backend\.venv (see requirements.txt).
setlocal
cd /d "%~dp0.."
if not exist "backend\.venv\Scripts\python.exe" (
  echo backend\.venv is missing - run scripts\setup.ps1 once.
  exit /b 1
)
if not defined AI_PORT set "AI_PORT=8001"
"backend\.venv\Scripts\python.exe" -m uvicorn main:app --app-dir ai-service --host 127.0.0.1 --port %AI_PORT%
