"""Sensor reading, trend-series and live-feed schemas."""

from pydantic import BaseModel

from app.utils.datetimes import UTCDateTime


class SensorReadingOut(BaseModel):
    id: int
    mine_id: int
    sensor_type: str
    value: float
    unit: str
    breached: bool
    recorded_at: UTCDateTime

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


class LiveReadingOut(BaseModel):
    """One tick: a mine's three sensors at one moment, the row a live chart appends."""

    timestamp: UTCDateTime
    timestamp_ms: int
    gas: float | None
    dust: float | None
    temperature: float | None
    breached: list[str]
    anomaly_score: float | None = None
    is_anomaly: bool | None = None


class FleetLiveReadingOut(LiveReadingOut):
    mine_id: int
    code: str
    name: str


class _LiveFeedEnvelope(BaseModel):
    cursor: int
    reset: bool
    thresholds: dict[str, float]
    units: dict[str, str]
    anomaly_threshold: float | None = None


class MineLiveFeedOut(_LiveFeedEnvelope):
    mine_id: int
    readings: list[LiveReadingOut]


class FleetLiveFeedOut(_LiveFeedEnvelope):
    readings: list[FleetLiveReadingOut]
