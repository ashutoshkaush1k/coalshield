"""Frame sampling and batching so a recorded demo video processes at a watchable pace.

CPU inference on every frame of a 30fps clip is far too slow for a live demo, and consecutive
frames are nearly identical anyway. Sampling every Nth frame keeps the pace watchable without
losing violations that persist for more than a fraction of a second.
"""

from __future__ import annotations

from collections.abc import Iterator
from pathlib import Path

from app.services.vision.ppe_rules import ViolationCandidate, containment


def sample_frames(
    video_path: str | Path, sample_every: int = 15, max_frames: int = 20
) -> Iterator[tuple[int, object]]:
    """Yield (frame_index, frame) pairs, taking every `sample_every`-th frame.

    Stops after `max_frames` so a long clip cannot stall a demo. Yields nothing when OpenCV is
    missing or the file will not open, leaving the caller with an empty - not a broken - run.
    """
    try:
        import cv2
    except ImportError:
        return

    capture = cv2.VideoCapture(str(video_path))
    if not capture.isOpened():
        return

    try:
        index = 0
        emitted = 0
        while emitted < max_frames:
            ok, frame = capture.read()
            if not ok:
                break
            if index % max(1, sample_every) == 0:
                yield index, frame
                emitted += 1
            index += 1
    finally:
        capture.release()


def deduplicate(
    candidates: list[ViolationCandidate], overlap_threshold: float = 0.6
) -> list[ViolationCandidate]:
    """Collapse the same violation seen across several sampled frames into one.

    Two candidates are the same violation when they share a violation_type and their boxes
    substantially overlap. Without this, a stationary worker without a helmet would be penalised
    once per sampled frame and could drive a mine to zero from a single clip.

    The highest-confidence instance survives, so the audit trail keeps the clearest evidence.
    """
    kept: list[ViolationCandidate] = []
    for candidate in sorted(candidates, key=lambda c: c.confidence, reverse=True):
        duplicate = any(
            existing.violation_type == candidate.violation_type
            and containment(candidate.bbox, existing.bbox) >= overlap_threshold
            for existing in kept
        )
        if not duplicate:
            kept.append(candidate)
    return kept
