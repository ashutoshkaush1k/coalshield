"""Refresh the SHA-256 checksums quoted in DATASETS.md after re-running a stage.

A card quotes a file's checksum either as the 64-hex value (replaced when the file changes: the old
value is the file's checksum at git HEAD) or, for a new card, as the placeholder
`sha256:<path relative to data/>`, which is filled in. Run after `run_data.bat clean`:

    data\\.venv\\Scripts\\python.exe data\\scripts\\update_card_checksums.py
"""

from __future__ import annotations

import hashlib
import re
import subprocess
import sys

from common import DATA, sha256_file

CARDS = DATA / "DATASETS.md"


def head_sha(rel: str) -> str | None:
    r = subprocess.run(["git", "show", f"HEAD:data/{rel}"], cwd=DATA, capture_output=True)
    return hashlib.sha256(r.stdout).hexdigest() if r.returncode == 0 else None


def main() -> int:
    text = CARDS.read_text(encoding="utf-8")
    changed = []
    for rel in sorted({p.relative_to(DATA).as_posix() for p in (DATA / "reference").glob("*") if p.is_file()}):
        new = sha256_file(DATA / rel)
        token = f"sha256:{rel}"
        if token in text:
            text = text.replace(token, new)
            changed.append(f"{rel}: filled in")
        old = head_sha(rel)
        if old and old != new and old in text:
            text = text.replace(old, new)
            changed.append(f"{rel}: updated")
    left = re.findall(r"sha256:reference/[\w./-]+", text)
    CARDS.write_text(text, encoding="utf-8")
    for c in changed:
        print(f"  {c}")
    if left:
        print(f"ERROR: no file for placeholders {left}", file=sys.stderr)
        return 1
    print(f"DATASETS.md checksums: {len(changed)} updated")
    return 0


if __name__ == "__main__":
    sys.exit(main())
