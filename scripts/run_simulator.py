"""Replay the seeded sensor dataset as a live feed (PRD 4.2).

One tick = every mine reports gas, dust and temperature. Breaches raise alerts and immediately
move the mine's compliance score, exactly like a CV detection does.

Usage:
    python scripts/run_simulator.py                    # 6s ticks, one full pass, then stop
    python scripts/run_simulator.py --loop             # keep cycling until Ctrl+C - the live demo
    python scripts/run_simulator.py --interval 2       # faster, for rehearsal (see the window note)
    python scripts/run_simulator.py --ticks 5          # stop after 5 ticks
    python scripts/run_simulator.py --dry-run          # roll back; nothing is persisted
    python scripts/run_simulator.py --check-only       # pre-flight the database, run nothing
    python scripts/run_simulator.py --require-clean    # refuse to start unless state is pristine

Scores fall AND recover: a breach counts against its mine only for BREACH_WINDOW_HOURS (36s by
default - six ticks at the default 6s interval), so --loop settles into a live rise-and-fall rather
than grinding mines to zero, and stopping the feed lets every mine climb back within one window.
The window is tuned for 6s ticks; at --interval 2 set BREACH_WINDOW_HOURS=0.0033 to keep it at six.
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
from app.services.compliance.scoring import configured_breach_window  # noqa: E402
from app.services.iot.simulator import SensorSimulator  # noqa: E402

ARROW = "->"
RULE = "=" * 78


def _window_label() -> str:
    hours = configured_breach_window()
    return "all-time (no window)" if hours is None else f"{hours * 3600:.0f}s"


def preflight(report: BaselineReport) -> bool:
    """Report how far the database has drifted from a freshly seeded baseline.

    Open PPE violations persist until a clean re-inspection resolves them, so extra ones from an
    earlier run - a CV demo, a rehearsal - start the board lower than the demo script expects.
    Breaches only count inside the rolling window, so breach drift clears on its own and shows
    here only if a run finished moments ago. This makes both visible before a run, not on stage.

    Returns True when the scores match the seeded baseline.
    """
    if report.is_clean:
        print(f"Pre-flight   : OK - all {len(report.mines)} mines match the seeded baseline.")
        return True

    print()
    print(RULE)
    print("  WARNING: SCORES DO NOT MATCH THE CLEAN BASELINE")
    print(RULE)
    print("  Extra PPE violations below persist until a clean re-inspection resolves them.")
    print(f"  Extra breaches are recent ones still inside the {_window_label()} scoring window;")
    print("  those age out on their own, so if a run just ended, wait a moment and re-check.")
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
    """One line per mine, measured from its last recorded score so recoveries show as well."""
    stamp = time.strftime("%H:%M:%S")
    print(f"\n[{stamp}] tick {tick.index}  readings={tick.total_readings}  "
          f"breaches={tick.total_breaches}  recovering={len(tick.recovered)}")

    for mine in tick.mines:
        after = mine.score_after
        flag = "  <-- RISK LEVEL CHANGED" if mine.band_moved else ""
        note = ""
        if mine.breaches:
            note = "  " + ", ".join(f"{r.sensor_type}={r.value}{r.unit}" for r in mine.breaches)
        elif mine.recovered:
            note = "  (older breaches aged out)"
        delta = f"{mine.movement:+.0f}" if mine.movement else "  ."
        print(f"   {mine.code:<11} {mine.reference_score:>5.1f} {ARROW} {after.score:>5.1f} "
              f"({delta:>3})  {after.risk_level.value:<6} {after.risk_level.colour:<6}"
              f"{note}{flag}")


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
        window = configured_breach_window()
        if window is None:
            print("Score window : none - every breach counts forever (BREACH_WINDOW_HOURS=0)")
        else:
            seconds = window * 3600
            print(f"Score window : {seconds:.0f}s (BREACH_WINDOW_HOURS={window:g}) = "
                  f"{seconds / args.interval:.0f} ticks at this interval")
            if seconds >= sim.total_ticks * args.interval:
                # A window of a whole pass or more holds an almost constant breach count while
                # looping, so the rise-and-fall the window exists for would not be visible.
                print("  NOTE: the window spans a full pass at this interval - while looping, "
                      "scores will barely move. Shorten BREACH_WINDOW_HOURS or raise --interval.")
        print("Ctrl+C to stop.")

        completed = replay(sim, limit, args.interval, commit=not args.dry_run)

        if args.dry_run:
            db.rollback()
            print("\n(dry run - nothing was written)")

        print(f"\nTicks run: {completed}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
