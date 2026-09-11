"""Government-raised directives, and the proof-backed resolution loop that closes them.

Distinct from the automated alerts in engine.py: a directive is a person telling a mine
to act, so it carries an author, a lifecycle, and evidence when it is closed.

Resolution evidence lives in `corrective_actions`, one row per attempt. That matters
because a directive can be reopened: keeping the proof on the alert itself would let a
second attempt overwrite the first, and the point of reopening is to compare them.
"""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.db.base import utcnow
from app.models.alert import (
    ALERT_TYPE_DIRECTIVE,
    STATUS_OPEN,
    STATUS_RESOLVED,
    Alert,
)
from app.models.corrective_action import CorrectiveAction
from app.services.audit.recorder import record

VALID_SEVERITIES = ("LOW", "MEDIUM", "HIGH")

# A flag raised against a mine in the red band is not the same call to action as one
# against a compliant mine, so severity follows the band rather than asking for it.
SEVERITY_FOR_BAND = {"HIGH": "HIGH", "MEDIUM": "MEDIUM", "LOW": "LOW"}


def describe_mine_flag(db: Session, mine, actor_name: str) -> tuple[str, str]:
    """Compose a directive's message and severity from a mine's state right now.

    Generated here rather than in the browser: the drill-down polls on an interval, so
    whatever it last rendered can be several seconds stale, and a record that says
    "score 46" should mean the score when the button was pressed.
    """
    from app.services.compliance.scoring import score_mine

    result = score_mine(db, mine.id)
    counts = []
    if result.violation_count:
        counts.append(f"{result.violation_count} violation{'' if result.violation_count == 1 else 's'}")
    if result.breach_count:
        counts.append(f"{result.breach_count} breach{'es' if result.breach_count != 1 else ''}")
    tail = f", {' and '.join(counts)} open" if counts else ", no open findings"

    message = (
        f"Flagged by {actor_name} — score {result.score:g}, "
        f"{result.risk_level.label}{tail}"
    )
    return message[:255], SEVERITY_FOR_BAND.get(result.risk_level.value, "MEDIUM")


class DirectiveError(ValueError):
    """Raised when a directive action is not valid for the alert's current state."""


def raise_directive(
    db: Session,
    mine_id: int,
    *,
    message: str,
    raised_by: str,
    severity: str = "MEDIUM",
    reference_id: int | None = None,
    now: datetime | None = None,
) -> Alert:
    """Create a directive from a Government user against one mine."""
    message = (message or "").strip()
    if not message:
        raise DirectiveError("A directive needs a message describing the concern")

    severity = (severity or "MEDIUM").upper()
    if severity not in VALID_SEVERITIES:
        raise DirectiveError(f"Severity must be one of {', '.join(VALID_SEVERITIES)}")

    alert = Alert(
        mine_id=mine_id,
        source="GOVERNMENT",
        severity=severity,
        message=message[:255],
        reference_id=reference_id,
        alert_type=ALERT_TYPE_DIRECTIVE,
        status=STATUS_OPEN,
        raised_by=raised_by,
        acknowledged=False,
        created_at=now or utcnow(),
    )
    db.add(alert)
    db.flush()

    record(
        db,
        "DIRECTIVE_RAISED",
        mine_id=mine_id,
        actor=raised_by,
        entity_type="Alert",
        entity_id=alert.id,
        detail=f"{severity} directive raised: {message[:180]}",
    )
    return alert


def resolve_directive(
    db: Session,
    alert: Alert,
    *,
    proof_text: str,
    resolved_by: str,
    proof_image_path: str | None = None,
    now: datetime | None = None,
) -> CorrectiveAction:
    """Close a directive with evidence of the corrective action taken."""
    if not alert.is_directive:
        raise DirectiveError("Only government-raised directives are resolved this way")
    if alert.status == STATUS_RESOLVED:
        raise DirectiveError("This directive is already resolved")

    proof_text = (proof_text or "").strip()
    if not proof_text:
        raise DirectiveError("Describe the corrective action taken before resolving")

    now = now or utcnow()
    action = CorrectiveAction(
        mine_id=alert.mine_id,
        alert_id=alert.id,
        description=proof_text,
        status="RESOLVED",
        created_by=resolved_by,
        proof_image_path=proof_image_path,
        created_at=now,
        resolved_at=now,
    )
    db.add(action)

    alert.status = STATUS_RESOLVED
    # A resolved directive must also stop counting as an outstanding alert on the
    # Government board, which counts unacknowledged alerts.
    alert.acknowledged = True
    db.flush()

    record(
        db,
        "DIRECTIVE_RESOLVED",
        mine_id=alert.mine_id,
        actor=resolved_by,
        entity_type="Alert",
        entity_id=alert.id,
        detail=(
            f"Directive resolved with proof: {proof_text[:150]}"
            + (f" (evidence image {proof_image_path})" if proof_image_path else "")
        ),
    )
    return action


def reopen_directive(
    db: Session, alert: Alert, *, reopened_by: str, reason: str = ""
) -> Alert:
    """Send a directive back to the mine because the proof was not sufficient."""
    if not alert.is_directive:
        raise DirectiveError("Only government-raised directives can be reopened")
    if alert.status != STATUS_RESOLVED:
        raise DirectiveError("This directive is not resolved, so there is nothing to reopen")

    alert.status = STATUS_OPEN
    alert.acknowledged = False
    db.flush()

    record(
        db,
        "DIRECTIVE_REOPENED",
        mine_id=alert.mine_id,
        actor=reopened_by,
        entity_type="Alert",
        entity_id=alert.id,
        detail=f"Directive reopened: {reason.strip()[:180] or 'proof not sufficient'}",
    )
    return alert


def actions_for_alerts(db: Session, alert_ids: list[int]) -> dict[int, list[CorrectiveAction]]:
    """Resolution attempts per alert, newest first, in one query rather than one per row."""
    if not alert_ids:
        return {}
    rows = db.scalars(
        select(CorrectiveAction)
        .where(CorrectiveAction.alert_id.in_(alert_ids))
        .order_by(CorrectiveAction.created_at.desc(), CorrectiveAction.id.desc())
    ).all()
    grouped: dict[int, list[CorrectiveAction]] = {}
    for row in rows:
        grouped.setdefault(row.alert_id, []).append(row)
    return grouped
