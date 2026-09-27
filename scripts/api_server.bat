@echo off
rem Serve the API through XAMPP's Apache on port 8080 (docs/PERFORMANCE.md).
rem   scripts\api_server.bat start | stop | restart | status | config
rem Fallback: api\serve.bat (PHP's built-in server, one request at a time).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0api_server.ps1" %*
exit /b %ERRORLEVEL%
