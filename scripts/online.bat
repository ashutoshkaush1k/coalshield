@echo off
rem Run a yii command against the ONLINE database - see scripts\online.ps1 and docs\DEPLOYMENT.md.
rem   scripts\online.bat online/reset-data
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0online.ps1" %*
exit /b %ERRORLEVEL%
