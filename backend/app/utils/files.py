"""Safe upload paths and media type checks for image/video ingestion."""

from __future__ import annotations

import re
from pathlib import Path
from uuid import uuid4

from app.core.config import BACKEND_DIR

UPLOAD_DIR = BACKEND_DIR / "data" / "uploads"

# Anything outside this set is replaced, so a hostile filename cannot escape the directory.
_UNSAFE = re.compile(r"[^A-Za-z0-9._-]+")
# Uploads are stored as "<original-stem>_<8 hex>.<ext>".
UPLOAD_ID_PATTERN = re.compile(r"_[0-9a-f]{8}$")

IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}
VIDEO_SUFFIXES = {".mp4", ".avi", ".mov", ".mkv"}


class UnsupportedMediaError(ValueError):
    """Raised for a file extension the CV pipeline cannot read."""


def is_video(filename: str) -> bool:
    return Path(filename).suffix.lower() in VIDEO_SUFFIXES


def save_upload(filename: str, data: bytes) -> Path:
    """Persist an upload under a generated name.

    The client-supplied filename is never used as a path: only its extension is kept, and the
    stored name is a fresh UUID. That removes directory traversal and collisions in one step.
    """
    suffix = Path(filename).suffix.lower()
    if suffix not in IMAGE_SUFFIXES | VIDEO_SUFFIXES:
        raise UnsupportedMediaError(
            f"Unsupported file type '{suffix}'. "
            f"Images: {sorted(IMAGE_SUFFIXES)}; video: {sorted(VIDEO_SUFFIXES)}"
        )
    UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
    # Keep a sanitised stem for traceability, but always append a unique id so two uploads of
    # "frame.jpg" cannot overwrite each other.
    stem = _UNSAFE.sub("-", Path(filename).stem).strip("-.")[:48] or "upload"
    path = UPLOAD_DIR / f"{stem}_{uuid4().hex[:8]}{suffix}"
    path.write_bytes(data)
    return path
