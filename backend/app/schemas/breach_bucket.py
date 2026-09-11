"""Breach frequency buckets, split by sensor category."""

from pydantic import BaseModel


class BreachBucketOut(BaseModel):
    start_ms: int
    gas: int = 0
    dust: int = 0
    temperature: int = 0
    breaches: int


class BreachBucketsOut(BaseModel):
    bucket_hours: int
    mine_count: int
    buckets: list[BreachBucketOut]
