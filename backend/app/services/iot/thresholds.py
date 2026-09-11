"""Per-sensor safe limits; classifies each reading as normal or breach."""

from enum import StrEnum

from app.core.config import settings


class SensorType(StrEnum):
    GAS = "gas"
    DUST = "dust"
    TEMPERATURE = "temperature"

    @property
    def unit(self) -> str:
        return _UNITS[self]

    @property
    def limit(self) -> float:
        """Safe upper limit, read from settings so it stays tunable (PRD 8.1)."""
        return _LIMIT_GETTERS[self]()


_UNITS: dict[SensorType, str] = {
    SensorType.GAS: "ppm",
    SensorType.DUST: "mg/m3",
    SensorType.TEMPERATURE: "C",
}

# Indirection through lambdas so a settings change is picked up without reimporting this module.
_LIMIT_GETTERS = {
    SensorType.GAS: lambda: settings.threshold_gas_ppm,
    SensorType.DUST: lambda: settings.threshold_dust_mgm3,
    SensorType.TEMPERATURE: lambda: settings.threshold_temp_c,
}


def is_breach(sensor_type: SensorType | str, value: float) -> bool:
    """True when a reading exceeds its safe limit. Strictly greater — a reading exactly at the
    limit is compliant, so a threshold of 50 ppm means 50.0 passes and 50.1 breaches."""
    return value > SensorType(sensor_type).limit


def breach_margin(sensor_type: SensorType | str, value: float) -> float:
    """How far past the limit a reading is; 0.0 when compliant. Used for alert severity."""
    return max(0.0, value - SensorType(sensor_type).limit)
