"""Replay the seeded sensor dataset as a live feed (PRD 4.2).

One tick = every mine reports gas, dust and temperature. Breaches raise alerts and immediately
move the mine's compliance score, exactly like a CV detection does.

Usage:
    python scripts/run_simulator.py                    # 6s ticks, one full pass, then stop
    python scripts/run_simulator.py --interval 2       # faster, for rehearsal
    python scripts/run_simulator.py --ticks 5          # stop after 5 ticks
    python scripts/run_simulator.py --loop             # keep cycling (see the warning below)
    python scripts/run_simulator.py --dry-run          # roll back; nothing is persisted
    python scripts/run_simulator.py --check-only       # pre-flight the database, run nothing
    python scripts/run_simulator.py --require-clean    # refuse to start unless state is pristine

Warning: scoring counts every breach on record, so each pass permanently lowers every mine. One
pass is the demo. --loop will grind all five mines to zero.
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

BACKEND = Path(__file__).resolve().parents[1] / "backend"
sys.path.insert(0, str(BACKEND))

from app.db.seed import BaselineReport, baseline_report  # noqa: E402
from app.db.session import SessionLocal  # noqa: E402
from app.services.iot.simulator import SensorSimulator  # noqa: E402

ARROW = "->"
RULE = "=" * 78


def preflight(report: BaselineReport) -> bool:
    """Report how far the database has drifted from a freshly seeded baseline.

    Scores are cumulative by design (see docs/demo-script.md), so every demo run permanently
    lowers every mine. Running the simulator twice without re-seeding starts from an already
    degraded board and lands mines in bands the demo script does not expect. This makes that
    visible before a run instead of on stage.

    Returns True when the database is pristine.
    """
    if report.is_clean:
        print(f"Pre-flight   : OK - all {len(report.mines)} mines match the seeded baseline.")
        return True

    print()
    print(RULE)
    print("  WARNING: DATABASE IS NOT AT THE CLEAN BASELINE")
    print(RULE)
    print("  Compliance scoring counts every violation and breach on record (all-time, by")
    print("  design). This database already carries results from an earlier demo run, so")
    print("  scores start LOWER than the rehearsed baseline and this run will push them")
    print("  further than the demo script expects.")
    print()
    print(f"  {'MINE':<12}{'SCORE':>16}{'PPE':>10}{'BREACHES':>12}")
    for mine in report.mines:
        if mine.is_clean:
            print(f"  {mine.code:<12}{mine.actual_score:>9.1f} (ok){'':>10}{'':>12}")
            continue
        band = f"  {mine.expected_risk} -> {mine.actual_risk}" if mine.band_changed else ""
        score = f"{mine.expected_score:.0f} -> {mine.actual_score:.0f}"
        ppe = f"+{mine.extra_violations}" if mine.extra_violations else "-"
        breaches = f"+{mine.extra_breaches}" if mine.extra_breaches else "-"
        print(f"  {mine.code:<12}{score:>16}{ppe:>10}{breaches:>12}{band}")

    if report.bands_changed:
        codes = ", ".join(m.code for m in report.bands_changed)
        print()
        print(f"  {len(report.bands_changed)} mine(s) already moved risk band: {codes}")

    print()
    print("  FIX BEFORE DEMOING:  python scripts/seed_db.py --reset")
    print(RULE)
    return False


def render(tick) -> None:
    stamp = time.strftime("%H:%M:%S")
    print(f"\n[{stamp}] tick {tick.index}  "
          f"readings={tick.total_readings}  breaches={tick.total_breaches}")

    for mine in tick.mines:
        before, after = mine.score_before, mine.score_after
        flag = "  <-- RISK LEVEL CHANGED" if mine.risk_changed else ""
        breach_note = ""
        if mine.breaches:
            breach_note = "  " + ", ".join(
                f"{r.sensor_type}={r.value}{r.unit}" for r in mine.breaches
            )
        delta = f"{mine.score_delta:+.0f}" if mine.score_delta else "  ."
        print(f"   {mine.code:<11} {before.score:>5.1f} {ARROW} {after.score:>5.1f} "
              f"({delta:>3})  {after.risk_level.value:<6} {after.risk_level.colour:<6}"
              f"{breach_note}{flag}")


def tick_limit(ticks: int | None, loop: bool, full_pass: int) -> int | None:
    """How many ticks this run emits; None means until Ctrl+C.

    An explicit --ticks always wins. Otherwise a single pass stops when the dataset does,
    and --loop keeps cycling. It used to fall back to one full pass either way, so --loop
    on its own stopped after 12 ticks and the dashboards went quiet mid-demo.
    """
    if ticks:
        return ticks
    return None if loop else full_pass


def replay(sim, limit: int | None, interval: float, *, commit: bool = True,
           on_tick=render, sleep=time.sleep) -> int:
    """Tick until the limit, the end of a non-looping dataset, or Ctrl+C. Returns ticks run."""
    completed = 0
    try:
        while limit is None or completed < limit:
            if sim.exhausted and not sim.loop:
                print("\nDataset exhausted - replay complete.")
                break
            on_tick(sim.tick(commit=commit))
            completed += 1
            if limit is None or completed < limit:
                sleep(interval)
    except KeyboardInterrupt:
        print("\n\nStopped by user.")
    return completed


def main() -> int:
    parser = argparse.ArgumentParser(description="Replay seeded sensor data as a live feed.")
    parser.add_argument("--interval", type=float, default=6.0, help="seconds between ticks")
    parser.add_argument("--ticks", type=int, default=None, help="stop after N ticks")
    parser.add_argument("--loop", action="store_true", help="restart the dataset when exhausted")
    parser.add_argument("--dry-run", action="store_true", help="roll back instead of committing")
    parser.add_argument("--check-only", action="store_true",
                        help="run the pre-flight baseline check and exit")
    parser.add_argument("--require-clean", action="store_true",
                        help="abort unless the database is at the clean seeded baseline")
    args = parser.parse_args()

    with SessionLocal() as db:
        try:
            clean = preflight(baseline_report(db))
        except FileNotFoundError as exc:
            print(f"ERROR: cannot pre-flight - {exc}")
            return 1

        if args.check_only:
            return 0 if clean else 1

        if not clean and args.require_clean:
            print()
            print("Aborting: --require-clean was set and the database has drifted.")
            return 1

        if not clean:
            # Deliberately not fatal: the demo script runs the vision demo before this, so some
            # drift is expected mid-run. The banner above is the safeguard; --require-clean is
            # the hard gate for a pre-demo rehearsal check.
            print()
            print("Continuing anyway (pass --require-clean to make this fatal).")
            print()

        try:
            sim = SensorSimulator(db, loop=args.loop)
        except FileNotFoundError as exc:
            print(f"ERROR: {exc}")
            return 1

        if not sim.mines:
            print("ERROR: no mines in the database. Run scripts/seed_db.py first.")
            return 1

        limit = tick_limit(args.ticks, args.loop, sim.total_ticks)
        print(f"Mines        : {len(sim.mines)}")
        print(f"Full pass    : {sim.total_ticks} ticks "
              f"({sim.total_ticks * len(sim.mines) * 3} readings)")
        duration = ("until Ctrl+C" if limit is None
                    else f"about {limit * args.interval:.0f}s for this run")
        print(f"Interval     : {args.interval}s   -> {duration}")
        print(f"Mode         : {'LOOP' if args.loop else 'single pass'}"
              f"{'  (dry run)' if args.dry_run else ''}")
        print("Ctrl+C to stop.")

        completed = replay(sim, limit, args.interval, commit=not args.dry_run)

        if args.dry_run:
            db.rollback()
            print("\n(dry run - nothing was written)")

        print(f"\nTicks run: {completed}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
