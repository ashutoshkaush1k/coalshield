"""Replay the demo sensor dataset as a live feed through the API (brief section 2).

The simulator never touches the database. It reads data/out/<preset>/sensor_reading.csv, and each
tick POSTs one data-hour of readings for every mine to POST /v1/sensor-readings/ingest with an API
key. The API stamps them with the current time, applies the legal limits from
data/schema/rules.yaml, raises alerts and rescores. A breach costs its mine 3 points only inside
the 12 s window, so scores dip and recover while the feed runs.

Breaches are rare in the calibrated data (a handful a day across 74 mines), so by default the last
14 days are replayed, one data-hour per 2 s tick. That gives a genuine breach every few ticks rather
than a flat board. Per mine and sensor, a tick sends that hour's breached reading if there was one,
else its last reading.

Usage (stdlib only; any Python 3.10+):
    python scripts/run_simulator.py                   # 2 s ticks, one pass, then stop
    python scripts/run_simulator.py --loop            # keep cycling until Ctrl+C - the live demo
    python scripts/run_simulator.py --ticks 5         # stop after 5 ticks
    python scripts/run_simulator.py --days 3          # replay a shorter period
    python scripts/run_simulator.py --check-only      # pre-flight the scores, run nothing
    python scripts/run_simulator.py --require-clean   # refuse to start unless state is pristine

The API key comes from --key-file (default scripts/.simulator.key, written by
`api\\yii.bat api-key/issue simulator --out=..\\scripts\\.simulator.key`; run_all.bat does this).
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import sys
import time
import urllib.error
import urllib.request
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RULE = "=" * 78
CACHE_DIR = ROOT / "scripts" / ".cache"


# --- API -------------------------------------------------------------------------------------

class Api:
    def __init__(self, base: str, key: str, timeout: float = 30.0) -> None:
        self.base = base.rstrip("/")
        self.key = key
        self.timeout = timeout

    def call(self, method: str, path: str, body: dict | None = None) -> dict:
        data = json.dumps(body).encode() if body is not None else None
        request = urllib.request.Request(
            f"{self.base}{path}", data=data, method=method,
            headers={"X-Api-Key": self.key, "Content-Type": "application/json", "Accept": "application/json"},
        )
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                return json.loads(response.read() or b"{}")
        except urllib.error.HTTPError as exc:
            try:
                error = json.loads(exc.read()).get("error", {})
            except ValueError:
                error = {}
            raise ApiError(exc.code, error.get("code", "HTTP_%d" % exc.code), error) from None
        except urllib.error.URLError as exc:
            raise ApiError(0, "UNREACHABLE", {"reason": str(exc.reason)}) from None


class ApiError(RuntimeError):
    def __init__(self, status: int, code: str, detail: dict) -> None:
        super().__init__(f"{status} {code}")
        self.status, self.code, self.detail = status, code, detail


# --- replay data -----------------------------------------------------------------------------

def load_ticks(preset: str, days: int, mine_codes: set[str] | None) -> list[dict]:
    """Ticks oldest first: {"hour": iso, "readings": [{mine_code, sensor_type, value}]}.

    Cached in scripts/.cache keyed by the CSV's size and mtime - reading 1.7 M rows takes a few
    seconds, and the demo should not wait for it every time.
    """
    out_dir = ROOT / "data" / "out" / preset
    csv_path = out_dir / "sensor_reading.csv"
    if not csv_path.exists():
        raise FileNotFoundError(f"{csv_path} not found - run data\\run_data.bat {preset}")
    stat = csv_path.stat()
    cache = CACHE_DIR / f"replay_{preset}_{days}d_{stat.st_size}_{int(stat.st_mtime)}.json"
    if cache.exists():
        ticks = json.loads(cache.read_text(encoding="utf-8"))
    else:
        with (out_dir / "mine.csv").open(encoding="utf-8", newline="") as fh:
            code_of = {row["id"]: row["code"] for row in csv.DictReader(fh)}
        rows = []
        with csv_path.open(encoding="utf-8", newline="") as fh:
            for row in csv.DictReader(fh):
                rows.append((row["recorded_at"], row["mine_id"], row["sensor_type"], row["value"], row["breached"] == "true"))
        end = max(r[0] for r in rows)
        start = (datetime.fromisoformat(end.replace("Z", "+00:00")) - timedelta(days=days)).strftime("%Y-%m-%dT%H:%M:%SZ")
        chosen: dict[str, dict[tuple, tuple]] = defaultdict(dict)   # hour -> (mine, sensor) -> row
        for recorded_at, mine_id, sensor, value, breached in rows:
            if recorded_at <= start:
                continue
            hour = recorded_at[:13] + ":00:00Z"
            key = (mine_id, sensor)
            current = chosen[hour].get(key)
            # Keep the hour's breached reading if any, else its latest.
            if current is None or (breached and not current[2]) or (breached == current[2] and recorded_at > current[0]):
                chosen[hour][key] = (recorded_at, value, breached)
        ticks = [
            {"hour": hour, "readings": [
                {"mine_code": code_of[mine_id], "sensor_type": sensor, "value": float(value)}
                for (mine_id, sensor), (_, value, _) in sorted(chosen[hour].items())
            ]}
            for hour in sorted(chosen)
        ]
        CACHE_DIR.mkdir(parents=True, exist_ok=True)
        cache.write_text(json.dumps(ticks), encoding="utf-8")
    if mine_codes:
        for tick in ticks:
            tick["readings"] = [r for r in tick["readings"] if r["mine_code"] in mine_codes]
    return [t for t in ticks if t["readings"]]


# --- pre-flight ------------------------------------------------------------------------------

def preflight(report: dict) -> bool:
    """Print how far live scores are from the seeded baseline. True when they match."""
    if not report.get("has_baseline"):
        print("Pre-flight   : no seed baseline recorded - run `api\\yii.bat seed demo`.")
        return False
    mines = report["mines"]
    if report["is_clean"]:
        print(f"Pre-flight   : OK - all {len(mines)} mines match the seeded baseline ({report['preset']}).")
        return True
    window = report.get("breach_window_hours")
    print()
    print(RULE)
    print("  WARNING: SCORES DO NOT MATCH THE CLEAN BASELINE")
    print(RULE)
    print("  Extra violations persist until a corrective action or a clean re-inspection resolves them.")
    print(f"  Breaches count only inside the {window * 3600:.0f}s window and age out on their own." if window else "")
    print()
    print(f"  {'MINE':<12}{'SCORE':>16}{'VIOLATIONS':>12}{'BREACHES':>10}")
    for mine in mines:
        if mine["is_clean"]:
            continue
        score = f"{mine['expected_score']:.0f} -> {mine['actual_score']:.0f}"
        extra = f"{mine['extra_violations']:+d}" if mine["extra_violations"] else "-"
        breaches = str(mine["window_breaches"]) if mine["window_breaches"] else "-"
        band = f"  {mine['expected_risk']} -> {mine['actual_risk']}" if mine["expected_risk"] != mine["actual_risk"] else ""
        print(f"  {mine['code']:<12}{score:>16}{extra:>12}{breaches:>10}{band}")
    print()
    print("  FIX BEFORE DEMOING:  api\\yii.bat seed demo")
    print(RULE)
    return False


# --- replay ----------------------------------------------------------------------------------

def render(index: int, tick: dict, result: dict) -> None:
    stamp = time.strftime("%H:%M:%S")
    moved = [m for m in result["mines"] if m["score_before"] != m["score_after"] or m["breaches"]]
    print(f"[{stamp}] tick {index:>3}  data {tick['hour'][:13].replace('T', ' ')}h  "
          f"readings={result['stored']}  breaches={len(result['breaches'])}  moved={len(moved)}")
    by_mine = defaultdict(list)
    for breach in result["breaches"]:
        by_mine[breach["mine_id"]].append(f"{breach['sensor_type']}={breach['value']}")
    for mine in moved:
        delta = mine["score_after"] - mine["score_before"]
        band = "  <-- RISK LEVEL CHANGED" if mine["risk_before"] != mine["risk_after"] else ""
        note = ", ".join(by_mine.get(mine["mine_id"], [])) or "(older breaches aged out)"
        print(f"     mine {mine['mine_id']:>3}  {mine['score_before']:>5.1f} -> {mine['score_after']:>5.1f} "
              f"({delta:+.0f})  {mine['risk_after']:<6}  {note}{band}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Replay demo sensor data through the API.")
    parser.add_argument("--api", default=os.environ.get("API_URL", "http://127.0.0.1:8080"), help="API origin")
    parser.add_argument("--key-file", default=str(ROOT / "scripts" / ".simulator.key"))
    parser.add_argument("--preset", default="demo")
    parser.add_argument("--days", type=int, default=14, help="data period to replay")
    parser.add_argument("--mines", default="", help="comma-separated mine codes (default: all)")
    parser.add_argument("--interval", type=float, default=2.0, help="seconds between ticks")
    parser.add_argument("--ticks", type=int, default=None, help="stop after N ticks")
    parser.add_argument("--loop", action="store_true", help="restart the period when it ends")
    parser.add_argument("--check-only", action="store_true", help="run the pre-flight and exit")
    parser.add_argument("--require-clean", action="store_true", help="abort unless scores match the baseline")
    args = parser.parse_args()

    key_path = Path(args.key_file)
    if not key_path.exists():
        print(f"ERROR: no API key at {key_path}.")
        print("       Issue one:  api\\yii.bat api-key/issue simulator --out=..\\scripts\\.simulator.key")
        return 1
    api = Api(args.api, key_path.read_text(encoding="utf-8").strip())

    try:
        clean = preflight(api.call("GET", "/v1/sensor-readings/baseline"))
    except ApiError as exc:
        print(f"ERROR: pre-flight failed - {exc} {exc.detail or ''}")
        if exc.code == "UNREACHABLE":
            print(f"       Is the API running at {args.api}? (api\\serve.bat)")
        return 1
    if args.check_only:
        return 0 if clean else 1
    if not clean and args.require_clean:
        print("\nAborting: --require-clean was set and the scores have drifted.")
        return 1

    codes = {c.strip() for c in args.mines.split(",") if c.strip()} or None
    try:
        ticks = load_ticks(args.preset, args.days, codes)
    except FileNotFoundError as exc:
        print(f"ERROR: {exc}")
        return 1
    if not ticks:
        print("ERROR: no readings to replay for that selection.")
        return 1
    print(f"Replaying    : {len(ticks)} data-hours from the last {args.days} days of '{args.preset}', "
          f"{args.interval:g}s per tick{', looping' if args.loop else ''}; limits from data/schema/rules.yaml")

    limit = args.ticks or (None if args.loop else len(ticks))
    done, position = 0, 0
    try:
        while limit is None or done < limit:
            if position >= len(ticks):
                if not args.loop:
                    print("\nPeriod replayed - done.")
                    break
                position = 0
            tick = ticks[position]
            try:
                result = api.call("POST", "/v1/sensor-readings/ingest", {"readings": tick["readings"]})
                render(done + 1, tick, result)
            except ApiError as exc:
                print(f"[{time.strftime('%H:%M:%S')}] tick {done + 1} rejected: {exc} {exc.detail or ''}")
                if exc.status in (0, 401):
                    return 1
            done += 1
            position += 1
            if limit is None or done < limit:
                time.sleep(args.interval)
    except KeyboardInterrupt:
        print("\n\nStopped by user.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
