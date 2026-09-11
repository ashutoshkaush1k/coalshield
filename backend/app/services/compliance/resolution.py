"""Clearing violations when a mine produces evidence it has fixed the problem.

Compliance scoring counts open violations only, so something has to close them or a
mine can never recover. Today the only evidence accepted is a clean vision re-run.

Records are never deleted. Resolving a violation sets a flag and a timestamp; the
audit trail and the violation log keep showing it happened.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.db.base import utcnow
from app.models.corrective_action import CorrectiveAction
from app.models.violation import Violation
from app.services.audit.recorder import record
from app.services.vision.ppe_rules import VIOLATION_FOR_ABSENT_PPE

# Detections that show the camera actually saw a workforce. Zero violations on its own
# is NOT evidence of compliance: a photograph of an empty corridor also has zero
# violations. Requiring a person or a piece of worn PPE means a clean result says
# "workers were seen and they were equipped", which is the claim being made.
EVIDENCE_LABELS = {"person", *VIOLATION_FOR_ABSENT_PPE.keys()}


@dataclass
class ResolutionOutcome:
    """What a clean run cleared, and why it did or did not count."""

    accepted: bool
    reason: str
    resolved: list[Violation]
    actions: list[CorrectiveAction]

    @property
    def count(self) -> int:
        return len(self.resolved)


def is_compliance_evidence(detections, violations) -> tuple[bool, str]:
    """Decide whether a detection run is usable evidence that a site is compliant."""
    if violations:
        return False, f"{len(violations)} violation(s) detected"

    seen = {d.label for d in detections if d.label}
    supporting = seen & EVIDENCE_LABELS
    if not supporting:
        return False, "no workers or PPE detected in the frame"

    return True, f"clean frame showing {', '.join(sorted(supporting))}"


def open_violations(db: Session, mine_id: int) -> list[Violation]:
    return list(
        db.scalars(
            select(Violation).where(Violation.mine_id == mine_id, Violation.resolved.is_(False))
        ).all()
    )


def resolve_open_violations(
    db: Session,
    mine_id: int,
    *,
    evidence_ref: str,
    reason: str,
    actor: str = "SYSTEM",
    now: datetime | None = None,
) -> ResolutionOutcome:
    """Mark a mine's open violations resolved and log a corrective action for each.

    One corrective action per violation rather than one per upload: the table is shaped
    around `violation_id`, and an inspector reviewing a single violation should be able
    to see what closed it without reading through a batch record.
    """
    now = now or utcnow()
    violations = open_violations(db, mine_id)
    if not violations:
        return ResolutionOutcome(True, "nothing open to resolve", [], [])

    actions: list[CorrectiveAction] = []
    for violation in violations:
        violation.resolved = True
        violation.resolved_at = now

        action = CorrectiveAction(
            mine_id=mine_id,
            violation_id=violation.id,
            description=(
                f"Resolved by compliance re-inspection: {reason}. "
                f"Evidence frame {evidence_ref}. "
                f"Original finding: {violation.violation_type} at "
                f"{violation.confidence:.0%} confidence."
            ),
            status="RESOLVED",
            created_by=actor,
            resolved_at=now,
        )
        db.add(action)
        actions.append(action)

    db.flush()

    record(
        db,
        "VIOLATIONS_RESOLVED",
        mine_id=mine_id,
        actor=actor,
        entity_type="Mine",
        entity_id=mine_id,
        detail=(
            f"{len(violations)} open violation(s) cleared by a clean detection run "
            f"({reason}). Evidence frame {evidence_ref}."
        ),
    )
    return ResolutionOutcome(True, reason, violations, actions)
