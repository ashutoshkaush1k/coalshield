@echo off
rem ai-service (PPE vision, anomaly detectors, predicted risk) on http://127.0.0.1:8001. Uses ai-service\.venv (see requirements.txt).
setlocal
cd /d "%~dp0.."
if not exist "ai-service\.venv\Scripts\python.exe" (
  echo ai-service\.venv is missing - see docs\SETUP_WINDOWS.md, section 6b.
  exit /b 1
)
if not defined AI_PORT set "AI_PORT=8001"
"ai-service\.venv\Scripts\python.exe" -m uvicorn main:app --app-dir ai-service --host 127.0.0.1 --port %AI_PORT%
