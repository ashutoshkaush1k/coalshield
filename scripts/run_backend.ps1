# Starts the FastAPI server on http://localhost:8000
Set-Location "$PSScriptRoot\..\backend"
uvicorn app.main:app --reload --port 8000
