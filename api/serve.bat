@echo off
rem Serve the API on http://127.0.0.1:8080 with PHP's built-in server (development only).
rem Needs PostgreSQL running: scripts\db.bat start
cd /d "%~dp0"
if not defined PHP set "PHP=C:\xampp\php\php.exe"
if not defined API_PORT set "API_PORT=8080"
echo CoalShield API on http://127.0.0.1:%API_PORT%/v1/health  (Ctrl+C to stop)
"%PHP%" -S 127.0.0.1:%API_PORT% -t web web\index.php
