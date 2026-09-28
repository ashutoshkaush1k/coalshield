@echo off
rem Phase 7B: the offline field app for phones (docs\FIELD_APP_SETUP.md).
rem   run_field.bat          build the app for the field server and start it
rem Needs the API running (run_all.bat). Serves:
rem   https://<this PC's LAN address>:5443/field   phones on the same Wi-Fi (CA installed on the phone)
rem   http://localhost:5180/field                  this PC, and phones over USB port forwarding
rem   http://<LAN address>:5080/ca.crt             the local CA certificate, for phones
setlocal
cd /d "%~dp0"
if not exist "certs\server.crt" (
  echo No HTTPS certificate yet: making a local CA and a server certificate in certs\
  node scripts\make_cert.mjs || exit /b 1
)
pushd frontend
call npm run build:field || (popd & exit /b 1)
popd
node scripts\field_server.mjs
