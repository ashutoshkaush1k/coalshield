"""Sensor reading and trend-series schemas."""

from datetime import datetime

from pydantic import BaseModel


class SensorReadingOut(BaseModel):
    id: int
    mine_id: int
    sensor_type: str
    value: float
    unit: str
    breached: bool
    recorded_at: datetime

    model_config = {"from_attributes": True}


class SensorSeries(BaseModel):
    """One sensor's history for a mine, with the threshold so the chart can draw the limit line."""

    sensor_type: str
    unit: str
    threshold: float
    breach_count: int
    points: list[SensorReadingOut]


class SensorTrendOut(BaseModel):
    mine_id: int
    series: list[SensorSeries]
