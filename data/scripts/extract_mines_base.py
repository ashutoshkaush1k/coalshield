"""Stage D0 / source S01: extract the repo's 74 seeded mines into reference/mines_base.csv.

Reads the backend seed files and never writes to backend/ (brief rule 1). Every field the seed
holds for a mine is carried over, plus the mine head's login and the mine's standing on the
current demo board (open PPE violations, readings, breaches, score, risk band).

The demo score is recomputed here rather than read from the running backend, so the extraction
needs neither the API nor its database. To prove the recomputation matches what the backend
actually shows, the result is checked against the baseline documented in docs/demo-script.md;
any disagreement exits non-zero instead of writing a plausible-looking but wrong file.

Run:  data\\.venv\\Scripts\\python.exe data\\scripts\\extract_mines_base.py
"""

from __future__ import annotations

import csv
import hashlib
import json
import sys
from collections import Counter
from pathlib import Path

import yaml

REPO = Path(__file__).resolve().parents[2]
DATA = REPO / "data"
SEED = REPO / "backend" / "data" / "seed"
OUT = DATA / "reference" / "mines_base.csv"

COLUMNS = [
    # mines.json, every field, in a stable order
    "id", "code", "name", "location", "district", "state", "region", "operator",
    # users.json: the mine head account for this mine (password deliberately not copied)
    "head_email",
    # the demo board today
    "demo_named", "seed_violations", "seed_readings", "seed_breaches",
    "demo_score", "demo_risk_level",
]


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def risk_band(score: float, bands: dict) -> str:
    if score >= bands["low_min"]:
        return "LOW"
    if score >= bands["medium_min"]:
        return "MEDIUM"
    return "HIGH"


def main() -> int:
    config = yaml.safe_load((DATA / "config.yaml").read_text(encoding="utf-8"))
    base = config["repo_demo_baseline"]

    inputs = {name: SEED / name for name in
              ("mines.json", "users.json", "violations.json", "sensor_readings.csv")}
    for name, path in inputs.items():
        if not path.exists():
            print(f"ERROR: seed file missing: {path}", file=sys.stderr)
            return 1

    mines = json.loads(inputs["mines.json"].read_text(encoding="utf-8"))
    users = json.loads(inputs["users.json"].read_text(encoding="utf-8"))
    violations = json.loads(inputs["violations.json"].read_text(encoding="utf-8"))
    with inputs["sensor_readings.csv"].open(encoding="utf-8", newline="") as fh:
        readings = list(csv.DictReader(fh))

    head_email = {u["mine_id"]: u["email"] for u in users if u["role"] == "MINE_HEAD"}
    # The seed carries no resolved flag, so every seeded violation is open.
    open_violations = Counter(v["mine_id"] for v in violations)
    reading_count = Counter(int(r["mine_id"]) for r in readings)
    breach_count = Counter(
        int(r["mine_id"]) for r in readings
        if float(r["value"]) > base["thresholds"][r["sensor_type"]]
    )

    named = set(base["named_mines"])
    rows = []
    for m in sorted(mines, key=lambda m: m["id"]):
        violations_n = open_violations.get(m["id"], 0)
        raw = 100.0 - (violations_n * base["weight_ppe"]
                       + base["in_window_breaches_at_seed"] * base["weight_env"])
        score = round(min(100.0, max(0.0, raw)), 1)
        rows.append({
            "id": m["id"], "code": m["code"], "name": m["name"], "location": m["location"],
            "district": m["district"], "state": m["state"], "region": m["region"],
            "operator": m["operator"],
            "head_email": head_email.get(m["id"], ""),
            "demo_named": "true" if m["code"] in named else "false",
            "seed_violations": violations_n,
            "seed_readings": reading_count.get(m["id"], 0),
            "seed_breaches": breach_count.get(m["id"], 0),
            "demo_score": f"{score:.1f}",
            # Band from the rounded score, as the backend does, so number and colour agree.
            "demo_risk_level": risk_band(score, base["risk_bands"]),
        })

    # --- cross-check against the documented baseline before writing anything ---------------
    expected = base["expected"]
    scores = [float(r["demo_score"]) for r in rows]
    got = {
        "mine_count": len(rows),
        "average_score": round(sum(scores) / len(scores), 1),
        "band_counts": dict(Counter(r["demo_risk_level"] for r in rows)),
        "named_scores": {r["code"]: float(r["demo_score"]) for r in rows if r["code"] in named},
    }
    problems = []
    if got["mine_count"] != expected["mine_count"]:
        problems.append(f"mine_count {got['mine_count']} != {expected['mine_count']}")
    if got["average_score"] != expected["average_score"]:
        problems.append(f"average_score {got['average_score']} != {expected['average_score']}")
    for band, n in expected["band_counts"].items():
        if got["band_counts"].get(band, 0) != n:
            problems.append(f"{band} count {got['band_counts'].get(band, 0)} != {n}")
    for code, score in expected["named_scores"].items():
        if got["named_scores"].get(code) != float(score):
            problems.append(f"{code} score {got['named_scores'].get(code)} != {score}")
    missing_heads = [r["code"] for r in rows if not r["head_email"]]
    if missing_heads:
        problems.append(f"mines without a mine head account: {missing_heads}")

    if problems:
        print("ERROR: recomputed demo board does not match docs/demo-script.md:", file=sys.stderr)
        for p in problems:
            print(f"  - {p}", file=sys.stderr)
        print("Nothing written.", file=sys.stderr)
        return 1

    OUT.parent.mkdir(parents=True, exist_ok=True)
    # newline="\n" and a fixed column order keep the file byte-identical across runs and OSes.
    with OUT.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)

    print(f"Wrote {OUT.relative_to(REPO)}: {len(rows)} mines, {len(COLUMNS)} columns")
    print(f"Matches docs/demo-script.md: avg {got['average_score']}, "
          f"{got['band_counts'].get('HIGH', 0)} High / {got['band_counts'].get('MEDIUM', 0)} Medium / "
          f"{got['band_counts'].get('LOW', 0)} Low, named "
          + " / ".join(f"{got['named_scores'][c]:g}" for c in base["named_mines"]))
    print("Input checksums (sha256):")
    for name, path in inputs.items():
        print(f"  {sha256(path)}  backend/data/seed/{name}")
    print(f"Output checksum: {sha256(OUT)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
