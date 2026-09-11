"""Settings loaded from .env: DB URL, JWT secret, YOLO weights path, scoring weights, sensor thresholds."""

from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

BACKEND_DIR = Path(__file__).resolve().parents[2]
DATA_DIR = BACKEND_DIR / "data"
SEED_DIR = DATA_DIR / "seed"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=BACKEND_DIR / ".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    app_name: str = "Smart Mine Governance API"
    debug: bool = True

    # SQLite for the prototype; swap this one value for a PostgreSQL URL later.
    database_url: str = f"sqlite:///{BACKEND_DIR / 'smartmine.db'}"

    jwt_secret: str = "dev-only-secret-change-me-before-the-demo-32b+"
    jwt_algorithm: str = "HS256"
    access_token_expire_minutes: int = 480

    # Computer vision (PRD 4.1)
    yolo_weights_path: str = "ml/weights/ppe.pt"
    detection_confidence: float = 0.45
    # Which PPE is mandatory on site. Governs both detection paths (explicit negative classes
    # and person-based inference). Comma-separated: helmet, vest, mask, gloves, boots.
    required_ppe: str = "helmet,vest"

    # Inspection prioritisation (PRD 4.1). Urgency = (100 - score) + rising-trend pressure.
    # Raising weight_trend makes a deteriorating mine outrank a slightly worse stable one.
    weight_trend: float = 2.0
    trend_window_hours: int = 24

    # Compliance scoring weights (PRD 6.1 / 8.1).
    # Env-configurable so they can be retuned without a code change or redeploy.
    weight_ppe: float = 5.0
    weight_env: float = 3.0

    # Sensor thresholds (PRD 4.2). A reading strictly above the limit is a breach.
    threshold_gas_ppm: float = 50.0
    threshold_dust_mgm3: float = 10.0
    threshold_temp_c: float = 45.0


@lru_cache
def get_settings() -> Settings:
    """Cached so every caller sees the same values within a process."""
    return Settings()


settings = get_settings()
