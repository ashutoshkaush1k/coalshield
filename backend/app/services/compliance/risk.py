"""Score bands to risk level: 80-100 Low/Green, 50-79 Medium/Yellow, 0-49 High/Red."""

from enum import StrEnum


class RiskLevel(StrEnum):
    LOW = "LOW"
    MEDIUM = "MEDIUM"
    HIGH = "HIGH"

    @property
    def colour(self) -> str:
        """Dashboard colour token (PRD 6.1)."""
        return {"LOW": "green", "MEDIUM": "yellow", "HIGH": "red"}[self.value]

    @property
    def label(self) -> str:
        return {"LOW": "Low Risk", "MEDIUM": "Medium Risk", "HIGH": "High Risk"}[self.value]


# (inclusive_min, inclusive_max, level) — exported so the frontend legend and the docs read
# from the same source as the logic.
RISK_BANDS: tuple[tuple[float, float, RiskLevel], ...] = (
    (80, 100, RiskLevel.LOW),
    (50, 79, RiskLevel.MEDIUM),
    (0, 49, RiskLevel.HIGH),
)

MEDIUM_THRESHOLD = 50.0
LOW_THRESHOLD = 80.0


def risk_from_score(score: float) -> RiskLevel:
    """Map a 0-100 compliance score to its risk band.

    Boundaries are inclusive at the bottom of each band: exactly 80 is LOW, exactly 50 is MEDIUM.
    """
    if score >= LOW_THRESHOLD:
        return RiskLevel.LOW
    if score >= MEDIUM_THRESHOLD:
        return RiskLevel.MEDIUM
    return RiskLevel.HIGH
