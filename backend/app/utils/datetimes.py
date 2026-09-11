"""Timezone-aware timestamp helpers shared across services."""

from datetime import UTC, datetime
from typing import Annotated

from pydantic import AfterValidator


def as_utc(value: datetime) -> datetime:
    """Treat a naive datetime as UTC; convert an aware one to UTC.

    SQLite drops tzinfo on the way back out, so every stored timestamp returns naive even
    though it was written as UTC. Serialised as-is it has no offset, and `new Date()` in
    the browser reads an offset-less string as local time - in IST that puts every sensor
    reading five and a half hours in the past.
    """
    return value.replace(tzinfo=UTC) if value.tzinfo is None else value.astimezone(UTC)


def to_epoch_ms(value: datetime) -> int:
    return int(as_utc(value).timestamp() * 1000)


# For response schemas: always serialises with a "Z", whatever the database handed back.
UTCDateTime = Annotated[datetime, AfterValidator(as_utc)]
