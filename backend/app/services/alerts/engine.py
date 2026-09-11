"""Turns a PPE violation or a threshold breach into a persisted Alert."""

from __future__ import annotations

from sqlalchemy.orm import Session

from app.models.alert import Alert
from app.models.sensor_reading import SensorReading
from app.models.violation import Violation
from app.services.iot.thresholds import SensorType, breach_margin

# A violation the model is confident about is worth waking someone up for; a marginal one is not.
HIGH_CONFIDENCE = 0.80
MEDIUM_CONFIDENCE = 0.60


def severity_for_confidence(confidence: float) -> str:
    if confidence >= HIGH_CONFIDENCE:
        return "HIGH"
    if confidence >= MEDIUM_CONFIDENCE:
        return "MEDIUM"
    return "LOW"


def severity_for_margin(sensor_type: SensorType | str, value: float) -> str:
    """Severity scales with how far past the limit a reading sits, not just that it crossed."""
    sensor_type = SensorType(sensor_type)
    margin_ratio = breach_margin(sensor_type, value) / max(sensor_type.limit, 1e-9)
    if margin_ratio >= 0.30:
        return "HIGH"
    if margin_ratio >= 0.10:
        return "MEDIUM"
    return "LOW"


def alert_for_violation(db: Session, violation: Violation) -> Alert:
    """Raise a PPE alert. PRD 4.5: a violation must surface visibly, not just in a table."""
    label = violation.violation_type.replace("_", " ")
    alert = Alert(
        mine_id=violation.mine_id,
        source="VISION",
        severity=severity_for_confidence(violation.confidence),
        message=f"PPE violation detected: {label} (confidence {violation.confidence:.0%})",
        reference_id=violation.id,
    )
    db.add(alert)
    db.flush()
    return alert


def alert_for_breach(db: Session, reading: SensorReading) -> Alert:
    """Raise an environmental alert for an out-of-threshold sensor reading."""
    sensor_type = SensorType(reading.sensor_type)
    alert = Alert(
        mine_id=reading.mine_id,
        source="SENSOR",
        severity=severity_for_margin(sensor_type, reading.value),
        message=(
            f"{sensor_type.value.title()} threshold breached: "
            f"{reading.value}{reading.unit} exceeds {sensor_type.limit}{sensor_type.unit}"
        ),
        reference_id=reading.id,
    )
    db.add(alert)
    db.flush()
    return alert
