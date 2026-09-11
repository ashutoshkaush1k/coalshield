"""Maps model classes to violation types (no_helmet, no_vest, ...) and applies confidence gating.

Public PPE models do not agree on a class vocabulary. Two families are common:

  * explicit-negative models, e.g. ['Hardhat', 'NO-Hardhat', 'Safety Vest', 'NO-Safety Vest', ...]
    which name the violation directly;
  * positive-only models, e.g. ['helmet', 'head', 'person'] where a bare head is the signal.

This module normalises both into one canonical vocabulary so the rest of the pipeline - and the
compliance engine behind it - never has to care which model was loaded.
"""

from __future__ import annotations

from dataclasses import dataclass

BBox = tuple[float, float, float, float]  # x1, y1, x2, y2

# Raw model class name (lowercased, punctuation-normalised) -> canonical token.
CLASS_ALIASES: dict[str, str] = {
    # positive PPE
    "hardhat": "helmet", "hard hat": "helmet", "helmet": "helmet", "safety helmet": "helmet",
    "safety vest": "vest", "vest": "vest", "reflective vest": "vest", "safety-vest": "vest",
    "mask": "mask", "face mask": "mask", "dust mask": "mask",
    "gloves": "gloves", "glove": "gloves", "safety gloves": "gloves",
    "boots": "boots", "safety boots": "boots", "safety shoes": "boots",
    # explicit negatives
    "no-hardhat": "missing_helmet", "no hardhat": "missing_helmet",
    "no-helmet": "missing_helmet", "no helmet": "missing_helmet",
    "head": "missing_helmet",  # bare head in the positive-only helmet datasets
    "no-safety vest": "missing_vest", "no safety vest": "missing_vest",
    "no-vest": "missing_vest", "no vest": "missing_vest",
    "no-mask": "missing_mask", "no mask": "missing_mask",
    "no-gloves": "missing_gloves", "no gloves": "missing_gloves",
    "no-boots": "missing_boots", "no boots": "missing_boots",
    # people
    "person": "person", "worker": "person", "people": "person",
}

# Canonical negative -> the violation_type stored in the violations table. These strings must match
# the ones used by the seed generator, or the dashboard would show two spellings of one violation.
VIOLATION_FOR_MISSING: dict[str, str] = {
    "missing_helmet": "no_helmet",
    "missing_vest": "no_safety_vest",
    "missing_mask": "no_dust_mask",
    "missing_gloves": "no_gloves",
    "missing_boots": "no_safety_boots",
}

# Canonical positive PPE -> the violation raised when a person is detected without it.
VIOLATION_FOR_ABSENT_PPE: dict[str, str] = {
    "helmet": "no_helmet",
    "vest": "no_safety_vest",
    "mask": "no_dust_mask",
    "gloves": "no_gloves",
    "boots": "no_safety_boots",
}


def normalise_label(raw: str) -> str | None:
    """Canonical token for a raw model class name, or None if it is not PPE-relevant."""
    key = " ".join(str(raw).strip().lower().replace("_", " ").split())
    if key in CLASS_ALIASES:
        return CLASS_ALIASES[key]
    # Tolerate unseen "no-x" spellings rather than silently dropping a real violation.
    for prefix in ("no ", "no-", "without "):
        if key.startswith(prefix):
            base = CLASS_ALIASES.get(key[len(prefix):].strip())
            if base and (missing := f"missing_{base}") in VIOLATION_FOR_MISSING:
                return missing
    return CLASS_ALIASES.get(key)


def containment(inner: BBox, outer: BBox) -> float:
    """Fraction of `inner` that falls inside `outer`. 0.0 when they do not overlap.

    Containment rather than IoU: a helmet box is far smaller than the worker box around it, so
    IoU would be near zero even for a perfectly worn helmet.
    """
    ix1, iy1, ix2, iy2 = inner
    ox1, oy1, ox2, oy2 = outer
    inner_area = max(0.0, ix2 - ix1) * max(0.0, iy2 - iy1)
    if inner_area <= 0:
        return 0.0
    ow = max(0.0, min(ix2, ox2) - max(ix1, ox1))
    oh = max(0.0, min(iy2, oy2) - max(iy1, oy1))
    return (ow * oh) / inner_area


@dataclass(frozen=True)
class ViolationCandidate:
    """One PPE violation, before it becomes a database row."""

    violation_type: str
    confidence: float
    bbox: BBox
    frame_index: int = 0
    basis: str = "explicit"  # "explicit" = model named it; "inferred" = person seen without PPE

    @property
    def is_inferred(self) -> bool:
        return self.basis == "inferred"


def ppe_type_for(missing_token: str) -> str:
    """"missing_helmet" -> "helmet". Lets one required-PPE list govern both detection paths."""
    return missing_token.removeprefix("missing_")


@dataclass(frozen=True)
class PpePolicy:
    """What counts as a violation. Tunable without touching the detection code."""

    required_ppe: tuple[str, ...] = ("helmet", "vest")
    min_confidence: float = 0.45
    # Fraction of a PPE box that must sit inside a person box to count as worn by them.
    containment_threshold: float = 0.5
    infer_from_person: bool = True

    @classmethod
    def from_settings(cls) -> "PpePolicy":
        """Read REQUIRED_PPE and DETECTION_CONFIDENCE from the environment.

        Which PPE is mandatory is a site rule, not a code constant: a mine that mandates dust
        masks sets REQUIRED_PPE="helmet,vest,mask" and the same model starts scoring them.
        """
        from app.core.config import settings

        required = tuple(
            part.strip().lower() for part in settings.required_ppe.split(",") if part.strip()
        )
        return cls(
            required_ppe=required or ("helmet", "vest"),
            min_confidence=settings.detection_confidence,
        )


def model_names_have_negatives(class_names) -> bool:
    """True when the loaded model names violations directly.

    When it does, person-based inference is switched off: the model is already the authority, and
    running both would double-count every bare head.
    """
    return any(
        normalise_label(name) in VIOLATION_FOR_MISSING for name in (class_names or [])
    )


def violations_from_detections(
    detections,
    policy: PpePolicy | None = None,
    infer: bool | None = None,
) -> list[ViolationCandidate]:
    """Turn raw detections into violation candidates.

    Two paths, deliberately not combined:

      1. Explicit negatives ("NO-Hardhat") become violations directly.
      2. If the model has no negative classes, each detected person is checked for the required
         PPE and a violation is raised for whatever is missing.

    Args:
        detections: objects with .label (already normalised), .confidence, .bbox, .frame_index.
        policy: thresholds and required PPE.
        infer: force person-based inference on or off; defaults to policy.infer_from_person.
    """
    policy = policy or PpePolicy()
    infer = policy.infer_from_person if infer is None else infer

    kept = [d for d in detections if d.confidence >= policy.min_confidence]
    candidates: list[ViolationCandidate] = []

    for det in kept:
        # A model may name negatives the site does not treat as mandatory - this model emits
        # NO-Mask for everyone not wearing a face mask. Without this filter those would flood the
        # violation log and swamp the helmet and vest findings that actually matter.
        if det.label in VIOLATION_FOR_MISSING and ppe_type_for(det.label) not in policy.required_ppe:
            continue
        if (violation_type := VIOLATION_FOR_MISSING.get(det.label)) is not None:
            candidates.append(
                ViolationCandidate(
                    violation_type=violation_type,
                    confidence=det.confidence,
                    bbox=det.bbox,
                    frame_index=det.frame_index,
                    basis="explicit",
                )
            )

    if not infer:
        return candidates

    people = [d for d in kept if d.label == "person"]
    for person in people:
        for ppe in policy.required_ppe:
            worn = any(
                d.label == ppe
                and d.frame_index == person.frame_index
                and containment(d.bbox, person.bbox) >= policy.containment_threshold
                for d in kept
            )
            if not worn and (violation_type := VIOLATION_FOR_ABSENT_PPE.get(ppe)):
                candidates.append(
                    ViolationCandidate(
                        violation_type=violation_type,
                        confidence=person.confidence,
                        bbox=person.bbox,
                        frame_index=person.frame_index,
                        basis="inferred",
                    )
                )

    return candidates
