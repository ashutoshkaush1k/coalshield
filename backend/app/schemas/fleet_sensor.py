"""Fleet-wide current sensor standing (Government risk view)."""

from datetime import datetime

from pydantic import BaseModel


class SensorStandingOut(BaseModel):
    sensor_type: str
    unit: str
    threshold: float
    value: float | None
    recorded_at: datetime | None
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


class FleetSensorOut(BaseModel):
    mine_count: int
    breaching_mines: int
    mines: list[MineSensorStandingOut]
