"""ORM models. Importing this package registers every table on the shared Base metadata."""

from app.models.alert import Alert
from app.models.audit_log import AuditLog
from app.models.compliance_score import ComplianceScore
from app.models.corrective_action import CorrectiveAction
from app.models.mine import Mine
from app.models.sensor_reading import SensorReading
from app.models.user import User
from app.models.violation import Violation

__all__ = [
    "Alert",
    "AuditLog",
    "ComplianceScore",
    "CorrectiveAction",
    "Mine",
    "SensorReading",
    "User",
    "Violation",
]
