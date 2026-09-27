@echo off
rem FALLBACK: serve the API on http://127.0.0.1:8080 with PHP's built-in server (development only).
rem It handles ONE request at a time, so two open dashboards queue behind each other; the normal
rem server is Apache: scripts\api_server.bat start (run_all.bat does it). docs/PERFORMANCE.md.
rem Needs PostgreSQL running: scripts\db.bat start
cd /d "%~dp0"
if not defined PHP set "PHP=C:\xampp\php\php.exe"
if not defined API_PORT set "API_PORT=8080"
echo CoalShield API on http://127.0.0.1:%API_PORT%/v1/health  (Ctrl+C to stop)
"%PHP%" -d zend_extension=opcache -d opcache.enable=1 -S 127.0.0.1:%API_PORT% -t web web\index.php
