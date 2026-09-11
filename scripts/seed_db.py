"""Creates tables and loads mines, users, and the sensor dataset. Run once before the demo.

Usage:
    python scripts/seed_db.py            # create tables and seed if empty
    python scripts/seed_db.py --reset    # drop everything and rebuild from the seed files
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

BACKEND = Path(__file__).resolve().parents[1] / "backend"
sys.path.insert(0, str(BACKEND))

from app.db.init_db import drop_all, init_db  # noqa: E402
from app.db.seed import SeedDataMissingError, is_seeded, seed_database  # noqa: E402
from app.db.session import SessionLocal  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Initialise and seed the demo database.")
    parser.add_argument(
        "--reset", action="store_true", help="drop all tables first (destroys existing data)"
    )
    args = parser.parse_args()

    if args.reset:
        print("Dropping all tables...")
        drop_all()

    init_db()

    with SessionLocal() as db:
        if is_seeded(db):
            print("Database already seeded. Use --reset to rebuild.")
            return 0
        try:
            summary = seed_database(db)
        except SeedDataMissingError as exc:
            print(f"ERROR: {exc}")
            return 1

    width = max(len(k) for k in summary)
    for key, value in summary.items():
        print(f"  {key:<{width}}  {value}")
    print("\nSeed complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
