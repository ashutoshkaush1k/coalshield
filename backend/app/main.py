"""FastAPI app factory: CORS, exception handlers, v1 router mount, startup DB init."""

import threading
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request, status
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from fastapi.staticfiles import StaticFiles

from app.api.v1.router import api_router
from app.core.config import BACKEND_DIR, settings
from app.db.init_db import init_db
from app.services.access.scope import MineAccessDenied
from app.services.iot.anomaly import get_scorer


@asynccontextmanager
async def lifespan(_: FastAPI):
    """Create any missing tables before the first request. Seeding stays a manual step."""
    init_db()
    # Loading scikit-learn and the anomaly model takes a few seconds. Done off the startup
    # path so the server comes up at once; a sensor request that arrives first waits on the
    # same lock rather than loading a second copy.
    threading.Thread(target=get_scorer, name="anomaly-model-warmup", daemon=True).start()
    yield


def create_app() -> FastAPI:
    app = FastAPI(title=settings.app_name, debug=settings.debug, lifespan=lifespan)

    app.add_middleware(
        CORSMiddleware,
        allow_origins=["http://localhost:5173", "http://127.0.0.1:5173"],
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )

    @app.exception_handler(MineAccessDenied)
    def _access_denied(_: Request, exc: MineAccessDenied) -> JSONResponse:
        """Any scope violation that reaches the transport layer becomes a 403, never a 200 with
        empty data. Belt and braces behind the explicit checks in the endpoints."""
        return JSONResponse(status_code=status.HTTP_403_FORBIDDEN, content={"detail": str(exc)})

    @app.get("/health", tags=["meta"])
    def health() -> dict[str, str]:
        return {"status": "ok", "app": settings.app_name}

    # Annotated CV frames are served straight from disk so the dashboard can show the evidence
    # behind a violation without a second API round trip.
    annotated_dir = BACKEND_DIR / "storage" / "annotated"
    annotated_dir.mkdir(parents=True, exist_ok=True)
    app.mount("/static/annotated", StaticFiles(directory=annotated_dir), name="annotated")

    # Resolution proof images. Same store the vision uploads use - no detection is run
    # on them, they are evidence a Government reviewer needs to be able to open.
    proof_dir = BACKEND_DIR / "data" / "uploads"
    proof_dir.mkdir(parents=True, exist_ok=True)
    app.mount("/static/proof", StaticFiles(directory=proof_dir), name="proof")

    app.include_router(api_router)
    return app


app = create_app()
