"""Role-aware dashboard payloads: scoped multi-mine view (Government) vs own-mine summary."""

from fastapi import APIRouter, Query

from app.api.deps import CurrentUser, DbSession, Scope
from app.api.v1.endpoints.inspections import to_candidate_out
from app.api.v1.endpoints.mines import (
    _mine_fields,
    available_states,
    open_alert_counts,
    to_compliance_out,
    visible_mines,
)
from app.core.roles import Role
from app.schemas.dashboard import DashboardOut, FleetStats
from app.schemas.mine import MineSummary
from app.services.compliance.risk import RiskLevel
from app.services.compliance.scoring import configured_breach_window, score_mines
from app.services.risk.prioritisation import build_inspection_queue

router = APIRouter(prefix="/dashboard", tags=["dashboard"])

# Top N shown inline; the full ranking lives at GET /api/v1/inspections.
INSPECTION_PREVIEW = 3

# With no state selected the board shows the worst mines nationally rather than every
# mine in the country. Rendering a thousand cores would be unreadable and slow, and the
# question a national view answers is "where is the worst risk", not "list everything".
NATIONAL_BOARD_LIMIT = 5


@router.get("", response_model=DashboardOut)
def get_dashboard(
    db: DbSession,
    scope: Scope,
    user: CurrentUser,
    state: str | None = Query(default=None, description="Narrow the view to one state"),
) -> DashboardOut:
    """One endpoint, three shapes: national summary, one state, or a Mine Head's own mine.

    The role decides how many mines are in scope at all; the state parameter narrows the
    view within that. A Mine Head is unaffected either way - their scope is a single mine,
    so filtering it by state can only ever return that mine or nothing.
    """
    is_government = user.role_enum is Role.GOVERNMENT
    selected_state = state or None

    # Everything in scope, which is what the headline numbers are computed over - not
    # just the handful of mines drawn on the board.
    in_scope = visible_mines(db, scope, selected_state)
    results = score_mines(db, [m.id for m in in_scope])

    scores = [r.score for r in results.values()]
    levels = [r.risk_level for r in results.values()]
    stats = FleetStats(
        mine_count=len(in_scope),
        average_score=round(sum(scores) / len(scores), 1) if scores else 0.0,
        high_risk_count=levels.count(RiskLevel.HIGH),
        medium_risk_count=levels.count(RiskLevel.MEDIUM),
        low_risk_count=levels.count(RiskLevel.LOW),
        total_violations=sum(r.violation_count for r in results.values()),
        total_breaches=sum(r.breach_count for r in results.values()),
        breach_window_hours=configured_breach_window(),
    )

    # Worst first. Nationally the board is capped; within a state every mine is shown,
    # and the frontend scrolls rather than shrinking the cores.
    ordered = sorted(in_scope, key=lambda m: results[m.id].score)
    truncated = is_government and not selected_state and len(ordered) > NATIONAL_BOARD_LIMIT
    shown = ordered[:NATIONAL_BOARD_LIMIT] if truncated else ordered

    alerts = open_alert_counts(db, [m.id for m in shown])
    summaries = [
        MineSummary(
            **_mine_fields(m),
            compliance=to_compliance_out(results[m.id]),
            open_alerts=alerts.get(m.id, 0),
        )
        for m in shown
    ]

    # Built from the same ranking function the /inspections endpoint uses, so the panel
    # and the full list can never disagree about who is most urgent.
    queue = (
        [
            to_candidate_out(c)
            for c in build_inspection_queue(db, [m.id for m in in_scope])[:INSPECTION_PREVIEW]
        ]
        if is_government
        else []
    )

    return DashboardOut(
        role=user.role,
        scope="ALL_MINES" if scope.is_unrestricted else f"MINE_{scope.mine_id}",
        state=selected_state,
        scope_label=selected_state or ("National" if is_government else "This mine"),
        states=available_states(db, scope) if is_government else [],
        stats=stats,
        mines=summaries,
        showing=len(summaries),
        is_truncated=truncated,
        inspection_queue=queue,
    )
