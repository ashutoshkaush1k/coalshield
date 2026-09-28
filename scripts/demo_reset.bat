@echo off
rem Phase 8: restore the demo database - "yii seed demo + yii jobs/all" - from a snapshot, in seconds,
rem and check the demo scores and the audit chain. Options: -Rebuild, -Status (scripts\demo_reset.ps1).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0demo_reset.ps1" %*
exit /b %ERRORLEVEL%
