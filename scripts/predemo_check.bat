@echo off
rem Phase 8: before a demo - prints READY, or a numbered list of what to fix (scripts\predemo_check.ps1).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0predemo_check.ps1" %*
exit /b %ERRORLEVEL%
