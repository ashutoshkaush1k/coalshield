"""Pytest fixtures: temp SQLite DB, seeded mines, Government and Mine Head test clients."""

from __future__ import annotations

import sys
from pathlib import Path

import pytest
from sqlalchemy import create_engine
from sqlalchemy.orm import Session, sessionmaker
from sqlalchemy.pool import StaticPool

BACKEND = Path(__file__).resolve().parents[1]
if str(BACKEND) not in sys.path:
    sys.path.insert(0, str(BACKEND))

from app.db.base import Base  # noqa: E402
from app.models.mine import Mine  # noqa: E402
from app.models.sensor_reading import SensorReading  # noqa: E402
from app.models.violation import Violation  # noqa: E402
from app.services.compliance.weights import ScoringWeights  # noqa: E402
from app.services.iot.thresholds import SensorType  # noqa: E402


@pytest.fixture
def db() -> Session:
    """In-memory database, rebuilt per test.

    StaticPool keeps every connection pointed at the same in-memory database; without it each
    connection would get its own empty one.
    """
    engine = create_engine(
        "sqlite://",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    Base.metadata.create_all(engine)
    session = sessionmaker(bind=engine, expire_on_commit=False)()
    try:
        yield session
    finally:
        session.close()
        Base.metadata.drop_all(engine)
        engine.dispose()


@pytest.fixture
def weights() -> ScoringWeights:
    """The PRD 6.1 default weights, pinned so tuning WEIGHT_PPE/WEIGHT_ENV cannot break the suite."""
    return ScoringWeights(weight_ppe=5.0, weight_env=3.0)


@pytest.fixture
def make_mine(db: Session):
    """Create a mine with a given number of PPE violations and breaching sensor readings."""

    counter = {"n": 0}

    def _make(violations: int = 0, breaches: int = 0, clean_readings: int = 0) -> Mine:
        counter["n"] += 1
        n = counter["n"]
        mine = Mine(
            code=f"TEST-{n:02d}",
            name=f"Test Mine {n}",
            location="Test Location",
            region="Test",
            operator="Test Operator",
        )
        db.add(mine)
        db.flush()

        for i in range(violations):
            db.add(
                Violation(
                    mine_id=mine.id,
                    violation_type="no_helmet",
                    confidence=0.9,
                    frame_ref=f"test_{i}.jpg",
                )
            )
        # Breaching readings sit above the gas limit; compliant ones sit below it. Only the
        # breached flag feeds scoring, so the exact values matter less than the classification.
        for _ in range(breaches):
            db.add(
                SensorReading(
                    mine_id=mine.id,
                    sensor_type=SensorType.GAS.value,
                    value=SensorType.GAS.limit + 10,
                    unit=SensorType.GAS.unit,
                    breached=True,
                )
            )
        for _ in range(clean_readings):
            db.add(
                SensorReading(
                    mine_id=mine.id,
                    sensor_type=SensorType.GAS.value,
                    value=SensorType.GAS.limit - 10,
                    unit=SensorType.GAS.unit,
                    breached=False,
                )
            )
        db.flush()
        return mine

    return _make


@pytest.fixture
def api(db: Session):
    """TestClient wired to the in-memory test session.

    Overrides get_db so requests and assertions see the same transaction; without this
    the app would open its own connection to the real database file.
    """
    from fastapi.testclient import TestClient

    from app.api.deps import get_db
    from app.main import app

    app.dependency_overrides[get_db] = lambda: db
    try:
        with TestClient(app) as client:
            yield client
    finally:
        app.dependency_overrides.pop(get_db, None)


@pytest.fixture
def accounts(db: Session, make_mine):
    """A Government user plus a Mine Head bound to each of two mines."""
    from app.core.security import hash_password
    from app.models.user import User

    first, second = make_mine(violations=0, breaches=0), make_mine(violations=0, breaches=0)
    users = [
        User(email="gov@test.gov", password_hash=hash_password("pw"), full_name="Gov",
             role="GOVERNMENT", mine_id=None),
        User(email="head1@test.in", password_hash=hash_password("pw"), full_name="Head One",
             role="MINE_HEAD", mine_id=first.id),
        User(email="head2@test.in", password_hash=hash_password("pw"), full_name="Head Two",
             role="MINE_HEAD", mine_id=second.id),
    ]
    db.add_all(users)
    db.flush()
    return {"mine": first, "other_mine": second}


def _login(api, email: str) -> dict:
    token = api.post("/api/v1/auth/login", json={"email": email, "password": "pw"}).json()
    return {"Authorization": f"Bearer {token['access_token']}"}


@pytest.fixture
def gov(api, accounts):
    return _login(api, "gov@test.gov")


@pytest.fixture
def head(api, accounts):
    """Mine Head for `accounts["mine"]`."""
    return _login(api, "head1@test.in")


@pytest.fixture
def other_head(api, accounts):
    """Mine Head for a different mine, for scope-leak checks."""
    return _login(api, "head2@test.in")
