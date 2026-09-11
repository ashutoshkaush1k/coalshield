# One-time setup: Python venv + deps, node deps, env files, seeded database.
$root = Resolve-Path "$PSScriptRoot\.."

Set-Location "$root\backend"
python -m venv .venv
& .\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
if (-not (Test-Path ".env")) { Copy-Item ".env.example" ".env" }

Set-Location "$root\frontend"
npm install
if (-not (Test-Path ".env")) { Copy-Item ".env.example" ".env" }

Set-Location "$root"
python scripts\generate_sensor_data.py
python scripts\seed_db.py
python scripts\train_sensor_model.py
