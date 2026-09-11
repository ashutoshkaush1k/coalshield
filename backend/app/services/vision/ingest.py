"""Orchestrates one CV run: detect -> persist violations -> raise alerts -> rescore the mine.

Kept separate from detector.py so detection stays a pure function of pixels, and everything with
a database side effect lives in one reviewable place.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path

from sqlalchemy.orm import Session

from app.models.alert import Alert
from app.models.violation import Violation
from app.services.alerts.engine import alert_for_violation
from app.services.audit.recorder import record
from app.services.compliance.resolution import (
    ResolutionOutcome,
    is_compliance_evidence,
    resolve_open_violations,
)
from app.services.compliance.scoring import ComplianceResult, record_score, score_mine
from app.services.vision.annotate import annotate_image
from app.services.vision.detector import Detection, get_detector
from app.services.vision.ppe_rules import (
    PpePolicy,
    ViolationCandidate,
    model_names_have_negatives,
    violations_from_detections,
)


@dataclass
class IngestResult:
    """Everything one analysis produced, including the score either side of it."""

    mine_id: int
    backend: str
    detections: list[Detection]
    candidates: list[ViolationCandidate]
    violations: list[Violation] = field(default_factory=list)
    alerts: list[Alert] = field(default_factory=list)
    score_before: ComplianceResult | None = None
    score_after: ComplianceResult | None = None
    annotated_path: Path | None = None
    frames_processed: int = 1
    resolution: ResolutionOutcome | None = None

    @property
    def resolved_count(self) -> int:
        return self.resolution.count if self.resolution else 0

    @property
    def is_clean_run(self) -> bool:
        """A run that produced no violations and was accepted as evidence."""
        return bool(self.resolution and self.resolution.accepted and not self.candidates)

    @property
    def score_delta(self) -> float:
        if self.score_before is None or self.score_after is None:
            return 0.0
        return round(self.score_after.score - self.score_before.score, 1)

    @property
    def risk_changed(self) -> bool:
        if self.score_before is None or self.score_after is None:
            return False
        return self.score_before.risk_level is not self.score_after.risk_level


def _persist(
    db: Session,
    mine_id: int,
    candidates: list[ViolationCandidate],
    frame_ref: str,
    actor: str,
    detected_at: datetime | None = None,
) -> tuple[list[Violation], list[Alert]]:
    violations: list[Violation] = []
    alerts: list[Alert] = []

    for candidate in candidates:
        violation = Violation(
            mine_id=mine_id,
            violation_type=candidate.violation_type,
            confidence=round(candidate.confidence, 3),
            source="VISION",
            frame_ref=frame_ref,
            **({"detected_at": detected_at} if detected_at else {}),
        )
        db.add(violation)
        db.flush()
        violations.append(violation)
        alerts.append(alert_for_violation(db, violation))
        record(
            db,
            "PPE_VIOLATION_DETECTED",
            mine_id=mine_id,
            actor=actor,
            entity_type="Violation",
            entity_id=violation.id,
            detail=(
                f"{candidate.violation_type} ({candidate.basis}) at "
                f"{candidate.confidence:.0%} confidence in {frame_ref}"
            ),
        )
    return violations, alerts


def analyse_image(
    db: Session,
    mine_id: int,
    image_path: str | Path,
    *,
    detector=None,
    policy: PpePolicy | None = None,
    actor: str = "SYSTEM",
    annotate: bool = True,
    commit: bool = True,
) -> IngestResult:
    """Run PPE detection on one image and apply the outcome to the mine.

    The score is captured before and after so a caller - the demo script, the dashboard, the audit
    trail - can show exactly what this frame cost the mine.
    """
    detector = detector or get_detector()
    policy = policy or PpePolicy.from_settings()

    before = score_mine(db, mine_id)
    detections = detector.detect(image_path)

    # If the model names violations itself, trust it and skip person-based inference, which would
    # otherwise raise a second violation for the same bare head.
    infer = not model_names_have_negatives(detector.class_names)
    candidates = violations_from_detections(detections, policy=policy, infer=infer)

    result = IngestResult(
        mine_id=mine_id,
        backend=detector.backend,
        detections=detections,
        candidates=candidates,
        score_before=before,
    )

    if annotate:
        result.annotated_path = annotate_image(image_path, detections, candidates)

    frame_ref = (
        f"annotated/{result.annotated_path.name}"
        if result.annotated_path
        else Path(str(image_path)).name
    )
    result.violations, result.alerts = _persist(db, mine_id, candidates, frame_ref, actor)

    # A clean re-inspection is the one piece of evidence that can clear a mine's open
    # violations, so a score is not a one-way ratchet. Guarded: an empty frame has zero
    # violations too, and must not be able to wipe a violation history.
    accepted, reason = is_compliance_evidence(detections, candidates)
    if accepted:
        result.resolution = resolve_open_violations(
            db, mine_id, evidence_ref=frame_ref, reason=reason, actor=actor
        )
    else:
        result.resolution = ResolutionOutcome(False, reason, [], [])

    after = score_mine(db, mine_id)
    result.score_after = after

    # Write a history point whenever the score actually moved - in either direction. A
    # recovery is as much a part of the trend as a drop.
    if result.violations or result.resolved_count:
        record_score(db, mine_id, after)
        record(
            db,
            "COMPLIANCE_SCORED",
            mine_id=mine_id,
            actor=actor,
            entity_type="Mine",
            entity_id=mine_id,
            detail=(
                f"Score {before.score} -> {after.score} "
                f"({before.risk_level.value} -> {after.risk_level.value}) after "
                f"{len(result.violations)} new violation(s) and "
                f"{result.resolved_count} resolved, from {frame_ref}"
            ),
        )

    if commit:
        db.commit()
    return result


def analyse_video(
    db: Session,
    mine_id: int,
    video_path: str | Path,
    *,
    detector=None,
    policy: PpePolicy | None = None,
    sample_every: int = 15,
    max_frames: int = 20,
    actor: str = "SYSTEM",
    commit: bool = True,
) -> IngestResult:
    """Sample frames from a video and apply the aggregate outcome.

    Delegates frame extraction to video_pipeline. Every sampled frame is scored as one detection
    pass; deduplication across frames happens there, so a worker without a helmet standing still
    for ten seconds is one violation, not ten.
    """
    from app.services.vision.video_pipeline import sample_frames

    detector = detector or get_detector()
    policy = policy or PpePolicy.from_settings()
    before = score_mine(db, mine_id)

    all_detections: list[Detection] = []
    all_candidates: list[ViolationCandidate] = []
    infer = not model_names_have_negatives(detector.class_names)
    frames = 0

    for frame_index, frame in sample_frames(video_path, sample_every, max_frames):
        detections = detector.detect(frame, frame_index=frame_index)
        all_detections.extend(detections)
        all_candidates.extend(
            violations_from_detections(detections, policy=policy, infer=infer)
        )
        frames += 1

    from app.services.vision.video_pipeline import deduplicate

    unique = deduplicate(all_candidates)

    result = IngestResult(
        mine_id=mine_id,
        backend=detector.backend,
        detections=all_detections,
        candidates=unique,
        score_before=before,
        frames_processed=frames,
    )
    frame_ref = Path(str(video_path)).name
    result.violations, result.alerts = _persist(db, mine_id, unique, frame_ref, actor)

    result.score_after = score_mine(db, mine_id)
    if result.violations:
        record_score(db, mine_id, result.score_after)

    if commit:
        db.commit()
    return result
