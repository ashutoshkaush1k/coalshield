"""Fleet-wide current sensor standing (Government risk view)."""

from pydantic import BaseModel

from app.utils.datetimes import UTCDateTime


class SensorStandingOut(BaseModel):
    sensor_type: str
    unit: str
    threshold: float
    value: float | None
    recorded_at: UTCDateTime | None
    breached: bool
    margin: float
    severity: str
    status_label: str
    open_breaches: int


class MineSensorStandingOut(BaseModel):
    mine_id: int
    code: str
    name: str
    location: str
    worst_severity: str
    breaching_now: int
    total_open_breaches: int
    sensors: list[SensorStandingOut]
    # From the anomaly model over this mine's latest reading per sensor. Advisory only.
    anomaly_score: float | None = None
    is_anomaly: bool | None = None


class FleetSensorOut(BaseModel):
    mine_count: int
    breaching_mines: int
    anomalous_mines: int = 0
    mines: list[MineSensorStandingOut]
