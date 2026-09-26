@echo off
rem Rebuild the TEST database from scratch and run every test (brief: migrations from scratch + seed + tests).
rem   run_tests.bat            all suites
rem   run_tests.bat api        one suite (extra arguments go to codecept run)
rem Needs PostgreSQL running (scripts\db.bat start) and data\out\small (data\run_data.bat small).
setlocal
cd /d "%~dp0"
if not defined PHP set "PHP=C:\xampp\php\php.exe"

if not exist "..\data\out\small\_manifest.json" (
  echo data\out\small is missing: generating it with data\run_data.bat small
  call "..\data\run_data.bat" small || exit /b 1
)

echo === migrations down/up on the test database
"%PHP%" yii_test migrate/down all --interactive=0 >nul || exit /b 1
"%PHP%" yii_test migrate --interactive=0 >nul || exit /b 1
echo === seed small
"%PHP%" yii_test seed small || exit /b 1
"%PHP%" yii_test audit/verify || exit /b 1

echo === codeception
"%PHP%" vendor\bin\codecept build >nul || exit /b 1
"%PHP%" vendor\bin\codecept run %*
exit /b %ERRORLEVEL%
