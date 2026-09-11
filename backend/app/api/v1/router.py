"""Aggregates every endpoint module under /api/v1."""

from fastapi import APIRouter

from app.api.v1.endpoints import (
    alerts,
    audit,
    auth,
    dashboard,
    inspections,
    mines,
    sensors,
    violations,
    vision,
)

api_router = APIRouter(prefix="/api/v1")
api_router.include_router(auth.router)
api_router.include_router(mines.router)
api_router.include_router(dashboard.router)
api_router.include_router(sensors.router)
api_router.include_router(inspections.router)
api_router.include_router(violations.router)
api_router.include_router(alerts.router)
api_router.include_router(audit.router)
api_router.include_router(vision.router)

# Remaining endpoint modules (compliance, corrective_actions) are scaffolded and get
# mounted here as each is implemented.
