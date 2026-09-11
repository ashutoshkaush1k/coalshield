"""Run a sample image through the CV module and show the compliance score it costs a mine.

Usage:
    python scripts/demo_vision.py                          # mine 1, bundled sample image
    python scripts/demo_vision.py --mine-id 3 --image path/to/frame.jpg
    python scripts/demo_vision.py --dry-run                # analyse without writing to the DB

This is the judge-facing moment from PRD Section 6: a detection lands and the mine visibly moves.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

BACKEND = Path(__file__).resolve().parents[1] / "backend"
sys.path.insert(0, str(BACKEND))

from app.db.session import SessionLocal  # noqa: E402
from app.models.mine import Mine  # noqa: E402
from app.services.vision.detector import build_detector, resolve_weights_path  # noqa: E402
from app.services.vision.ingest import analyse_image  # noqa: E402

# A real photograph, because the real model reads pixels: the synthetic ppe_sample.jpg is a
# fixture for the offline backend and means nothing to YOLO.
DEFAULT_IMAGE = BACKEND / "data" / "samples" / "images" / "metro_shaft_workers.jpg"

# Singrauli sits at 83 - one violation crosses it into MEDIUM, so the default run shows a risk
# band change rather than a score nudge.
DEFAULT_MINE_ID = 2

BAR_WIDTH = 40


def bar(score: float) -> str:
    filled = int(round(score / 100 * BAR_WIDTH))
    return "#" * filled + "." * (BAR_WIDTH - filled)


def main() -> int:
    parser = argparse.ArgumentParser(description="Demo the CV module against a mine.")
    parser.add_argument("--mine-id", type=int, default=DEFAULT_MINE_ID)
    parser.add_argument("--image", type=Path, default=DEFAULT_IMAGE)
    parser.add_argument("--fixture", action="store_true", help="force the fixture backend")
    parser.add_argument("--dry-run", action="store_true", help="roll back instead of committing")
    args = parser.parse_args()

    if not args.image.exists():
        print(f"ERROR: image not found: {args.image}")
        return 1

    detector = build_detector(prefer_fixture=args.fixture)
    print(f"Detector backend : {detector.backend}")
    if detector.backend == "fixture":
        print(f"  (no YOLO weights at {resolve_weights_path()} - using the JSON sidecar)")
    print(f"Image            : {args.image}")

    with SessionLocal() as db:
        mine = db.get(Mine, args.mine_id)
        if mine is None:
            print(f"ERROR: mine {args.mine_id} not found. Run scripts/seed_db.py first.")
            return 1

        result = analyse_image(
            db, mine.id, args.image, detector=detector, actor="DEMO", commit=not args.dry_run
        )
        if args.dry_run:
            db.rollback()

        before, after = result.score_before, result.score_after

        print(f"\nMine             : {mine.name} ({mine.code})")
        print(f"Detections       : {len(result.detections)}")
        for det in result.detections:
            print(f"  - {det.raw_label:<14} -> {str(det.label):<16} {det.confidence:.2f}")

        print(f"\nViolations found : {len(result.candidates)}")
        for candidate in result.candidates:
            print(f"  - {candidate.violation_type:<18} {candidate.basis:<9} {candidate.confidence:.2f}")

        if result.annotated_path:
            print(f"\nAnnotated frame  : {result.annotated_path}")

        print("\n--- COMPLIANCE SCORE ---")
        print(f"  before  {before.score:>6.1f}  [{bar(before.score)}]  {before.risk_level.value} ({before.risk_level.colour})")
        print(f"  after   {after.score:>6.1f}  [{bar(after.score)}]  {after.risk_level.value} ({after.risk_level.colour})")
        print(f"  delta   {result.score_delta:>+6.1f}   "
              f"({after.violation_count} violations x {after.weights.weight_ppe} + "
              f"{after.breach_count} breaches x {after.weights.weight_env})")

        if result.risk_changed:
            print(f"\n  >>> RISK LEVEL CHANGED: {before.risk_level.value} -> {after.risk_level.value} <<<")
        elif result.violations:
            print(f"\n  Score dropped but stayed in the {after.risk_level.value} band.")
        else:
            print("\n  No violations detected - score unchanged.")

        if args.dry_run:
            print("\n(dry run - nothing was written)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
