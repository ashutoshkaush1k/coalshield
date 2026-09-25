"""Shared helpers for the dataset track: paths, config, the date window, checksums, manifest I/O."""

from __future__ import annotations

import datetime as dt
import hashlib
import re
from pathlib import Path

import yaml

DATA = Path(__file__).resolve().parents[1]
REPO = DATA.parent
MANIFEST = DATA / "sources.yaml"


def load_config() -> dict:
    return yaml.safe_load((DATA / "config.yaml").read_text(encoding="utf-8"))


def date_window(config: dict | None = None, days: int | None = None) -> tuple[dt.date, dt.date]:
    """(first, last) day of the generated window, both inclusive.

    end_date comes from config.yaml (pinned, so runs are reproducible); None means today (UTC).
    """
    config = config or load_config()
    end = config["date_range"].get("end_date")
    last = dt.date.fromisoformat(str(end)) if end else dt.datetime.now(dt.timezone.utc).date()
    n = days or config["date_range"]["days"]
    return last - dt.timedelta(days=n - 1), last


def sha256_file(path: Path, chunk: int = 1 << 20) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for block in iter(lambda: fh.read(chunk), b""):
            h.update(block)
    return h.hexdigest()


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def slug(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", text.lower()).strip("_")[:80]


# --- manifest -----------------------------------------------------------------------------
def _header_of(text: str) -> str:
    """The leading comment block, kept verbatim when the manifest is rewritten."""
    lines = []
    for line in text.splitlines():
        if line.startswith("#") or not line.strip():
            lines.append(line)
        else:
            break
    return "\n".join(lines).rstrip() + "\n\n"


def load_manifest() -> tuple[dict, str]:
    raw = MANIFEST.read_text(encoding="utf-8")
    return yaml.safe_load(raw), _header_of(raw)


def save_manifest(manifest: dict, header: str) -> None:
    body = yaml.safe_dump(manifest, sort_keys=False, allow_unicode=True, width=100)
    MANIFEST.write_text(header + body, encoding="utf-8", newline="\n")
