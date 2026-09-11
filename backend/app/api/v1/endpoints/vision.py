"""Upload an image or video, run PPE detection, persist violations + alerts, return annotated output."""

from fastapi import APIRouter, File, Form, HTTPException, UploadFile, status

from app.api.deps import CurrentUser, DbSession, Scope
from app.api.v1.endpoints.mines import to_compliance_out
from app.models.mine import Mine
from app.schemas.violation import DetectionOut, VisionAnalysisOut, ViolationOut
from app.services.access.scope import MineAccessDenied
from app.services.vision.detector import get_detector
from app.services.vision.ingest import analyse_image, analyse_video
from app.utils.files import UnsupportedMediaError, is_video, save_upload

router = APIRouter(prefix="/vision", tags=["vision"])


@router.post("/analyze", response_model=VisionAnalysisOut, status_code=status.HTTP_201_CREATED)
async def analyze(
    db: DbSession,
    scope: Scope,
    user: CurrentUser,
    mine_id: int = Form(...),
    file: UploadFile = File(...),
) -> VisionAnalysisOut:
    """Detect PPE violations in an uploaded frame or clip and apply them to the mine.

    The mine is scope-checked first: a Mine Head cannot post detections against another mine, which
    would otherwise be a way to damage a competitor's compliance score.
    """
    try:
        scope.require(mine_id)
    except MineAccessDenied as exc:
        raise HTTPException(status.HTTP_403_FORBIDDEN, str(exc)) from exc

    if db.get(Mine, mine_id) is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, f"Mine {mine_id} not found")

    try:
        path = save_upload(file.filename or "upload.jpg", await file.read())
    except UnsupportedMediaError as exc:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, str(exc)) from exc

    detector = get_detector()
    analyse = analyse_video if is_video(path.name) else analyse_image
    result = analyse(db, mine_id, path, detector=detector, actor=user.email)

    return VisionAnalysisOut(
        mine_id=result.mine_id,
        backend=result.backend,
        frames_processed=result.frames_processed,
        detections=[
            DetectionOut(
                raw_label=d.raw_label, label=d.label, confidence=d.confidence, bbox=d.bbox
            )
            for d in result.detections
        ],
        violations=[ViolationOut.model_validate(v) for v in result.violations],
        alerts_raised=len(result.alerts),
        score_before=to_compliance_out(result.score_before),
        score_after=to_compliance_out(result.score_after),
        score_delta=result.score_delta,
        risk_changed=result.risk_changed,
        annotated_url=(
            f"/static/annotated/{result.annotated_path.name}" if result.annotated_path else None
        ),
        resolved_count=result.resolved_count,
        resolution_accepted=bool(result.resolution and result.resolution.accepted),
        resolution_reason=result.resolution.reason if result.resolution else "",
    )
