"""Draws bounding boxes and labels; writes results to storage/annotated for the dashboard."""

from __future__ import annotations

from pathlib import Path
from uuid import uuid4

from app.core.config import BACKEND_DIR

ANNOTATED_DIR = BACKEND_DIR / "storage" / "annotated"

# BGR, because OpenCV. Violations red, compliant PPE green, people amber.
COLOUR_VIOLATION = (0, 0, 220)
COLOUR_PPE = (0, 170, 0)
COLOUR_PERSON = (0, 170, 240)


def _colour_for(label: str | None, is_violation: bool):
    if is_violation:
        return COLOUR_VIOLATION
    if label == "person":
        return COLOUR_PERSON
    return COLOUR_PPE


def annotate_image(
    source, detections, violations, output_name: str | None = None
) -> Path | None:
    """Write an annotated copy of the frame and return its path.

    Returns None when OpenCV or the source image is unavailable: a missing preview must never
    take down an ingest that has already produced valid violations.
    """
    try:
        import cv2
    except ImportError:
        return None

    image = cv2.imread(str(source)) if not hasattr(source, "shape") else source.copy()
    if image is None:
        return None

    violation_boxes = {tuple(v.bbox) for v in violations}

    for det in detections:
        x1, y1, x2, y2 = (int(v) for v in det.bbox)
        is_violation = tuple(det.bbox) in violation_boxes
        colour = _colour_for(det.label, is_violation)
        cv2.rectangle(image, (x1, y1), (x2, y2), colour, 2)
        caption = f"{det.raw_label} {det.confidence:.2f}"
        cv2.putText(image, caption, (x1, max(14, y1 - 6)),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.5, colour, 1, cv2.LINE_AA)

    # Violation boxes are redrawn last and thicker so they are not hidden under overlapping PPE
    # boxes in a crowded frame.
    #
    # One worker commonly triggers several violations (no helmet AND no vest), and inferred
    # violations all carry that worker's box - so labels are stacked by box instead of drawn at
    # the same coordinates, where they would overprint into unreadable mush.
    stacked: dict[tuple[int, ...], int] = {}
    for violation in violations:
        x1, y1, x2, y2 = (int(v) for v in violation.bbox)
        key = (x1, y1, x2, y2)
        row = stacked.get(key, 0)
        stacked[key] = row + 1

        if row == 0:
            cv2.rectangle(image, (x1, y1), (x2, y2), COLOUR_VIOLATION, 3)

        baseline = y2 + 18 + row * 18
        if baseline > image.shape[0] - 4:          # ran out of room below, stack upward instead
            baseline = max(14, y1 - 6 - row * 18)
        cv2.putText(image, violation.violation_type, (x1, baseline),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.55, COLOUR_VIOLATION, 2, cv2.LINE_AA)

    ANNOTATED_DIR.mkdir(parents=True, exist_ok=True)
    name = output_name or f"{Path(str(source)).stem}_{uuid4().hex[:8]}.jpg"
    out_path = ANNOTATED_DIR / name
    cv2.imwrite(str(out_path), image)
    return out_path
