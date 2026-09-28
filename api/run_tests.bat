@echo off
rem Rebuild the TEST database from scratch and run every test (brief: migrations from scratch + seed + tests).
rem   run_tests.bat            all suites
rem   run_tests.bat api        one suite (extra arguments go to codecept run)
rem Needs PostgreSQL running (scripts\db.bat start) and data\out\demo (data\run_data.bat demo).
rem The demo preset is used so the demo-score test checks the real numbers (100/80/70/60/45, 83.2).
setlocal
cd /d "%~dp0"
if not defined PHP set "PHP=C:\xampp\php\php.exe"
if not defined TEST_PRESET set "TEST_PRESET=demo"

if not exist "..\data\out\%TEST_PRESET%\_manifest.json" (
  echo data\out\%TEST_PRESET% is missing: generating it with data\run_data.bat %TEST_PRESET%
  call "..\data\run_data.bat" %TEST_PRESET% || exit /b 1
)

echo === locales: every key in every language; no hard-coded UI text (Phase 6)
node "..\scripts\check_locales.mjs" || exit /b 1
node "..\scripts\check_hardcoded_strings.mjs" || exit /b 1

echo === ai-service: detectors and the risk model against the shared fixtures (Phase 7)
if exist "..\ai-service\.venv\Scripts\python.exe" (
  "..\ai-service\.venv\Scripts\python.exe" -m pytest "..\ai-service\tests" -q || exit /b 1
) else (
  echo ai-service\.venv not found - skipping the ai-service tests
)

echo === migrations down/up on the test database
"%PHP%" yii_test migrate/down all --interactive=0 >nul || exit /b 1
"%PHP%" yii_test migrate --interactive=0 >nul || exit /b 1
"%PHP%" yii_test rbac/init >nul || exit /b 1
echo === seed %TEST_PRESET%
"%PHP%" yii_test seed %TEST_PRESET% || exit /b 1
"%PHP%" yii_test audit/verify || exit /b 1

echo === codeception
"%PHP%" vendor\bin\codecept build >nul || exit /b 1
"%PHP%" vendor\bin\codecept run %*
set "RESULT=%ERRORLEVEL%"
echo === audit chain after the test run (brief Phase 8)
"%PHP%" yii_test audit/verify || exit /b 1
exit /b %RESULT%
