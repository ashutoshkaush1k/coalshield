"""Tunable weight_ppe and weight_env. PRD 8.1 open question."""

from dataclasses import dataclass

from app.core.config import settings


@dataclass(frozen=True)
class ScoringWeights:
    """Penalty per PPE violation and per out-of-threshold sensor reading.

    Frozen so a set of weights cannot be mutated mid-calculation, which would make a score
    impossible to reproduce or explain.
    """

    weight_ppe: float
    weight_env: float

    def __post_init__(self) -> None:
        if self.weight_ppe < 0 or self.weight_env < 0:
            raise ValueError("Scoring weights must be non-negative")

    @classmethod
    def from_settings(cls) -> "ScoringWeights":
        """Read the live env-configured values (WEIGHT_PPE / WEIGHT_ENV)."""
        return cls(weight_ppe=settings.weight_ppe, weight_env=settings.weight_env)
