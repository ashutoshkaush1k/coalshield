"""Inspection prioritisation ranking (PRD 4.1).

Tie behaviour gets the most attention here. An inspection queue that reorders between two
identical requests is worse than useless - an inspector cannot act on a list that will not sit
still - so every tie must resolve the same way every time.
"""

import pytest

from app.services.compliance.scoring import compute_compliance_score
from app.services.compliance.weights import ScoringWeights
from app.services.risk.prioritisation import (
    InspectionCandidate,
    build_inspection_queue,
    compute_urgency,
    rank_candidates,
)
from app.services.risk.trend import TrendDirection, TrendSignal

WEIGHTS = ScoringWeights(weight_ppe=5.0, weight_env=3.0)


def signal(mine_id=1, recent_v=0, recent_b=0, prev_v=0, prev_b=0, window=24) -> TrendSignal:
    return TrendSignal(
        mine_id=mine_id,
        window_hours=window,
        recent_violations=recent_v,
        recent_breaches=recent_b,
        previous_violations=prev_v,
        previous_breaches=prev_b,
    )


def candidate(mine_id, score_violations=0, score_breaches=0, trend=None, weight_trend=2.0):
    compliance = compute_compliance_score(score_violations, score_breaches, WEIGHTS)
    trend = trend or signal(mine_id)
    return InspectionCandidate(
        mine_id=mine_id,
        code=f"T-{mine_id:02d}",
        name=f"Mine {mine_id}",
        location="Test",
        region="Test",
        compliance=compliance,
        trend=trend,
        urgency=compute_urgency(compliance, trend, weight_trend),
        reasons=[],
    )


class TestTrendSignal:
    def test_direction_and_pressure(self):
        rising = signal(recent_v=3, prev_v=1)
        assert rising.delta == 2
        assert rising.direction is TrendDirection.RISING
        assert rising.pressure == 2

        falling = signal(recent_v=1, prev_v=4)
        assert falling.direction is TrendDirection.FALLING
        assert falling.delta == -3

    def test_improving_mine_gets_no_negative_pressure(self):
        """An improving mine should drop down the queue, not leapfrog a worse mine."""
        assert signal(recent_b=1, prev_b=9).pressure == 0

    def test_steady_is_zero(self):
        steady = signal(recent_v=2, recent_b=1, prev_v=2, prev_b=1)
        assert steady.direction is TrendDirection.STEADY
        assert steady.pressure == 0

    def test_events_combine_violations_and_breaches(self):
        assert signal(recent_v=2, recent_b=3).recent_events == 5


class TestUrgency:
    def test_severity_alone_orders_the_risk_bands(self):
        """Bands are score ranges, so severity orders HIGH > MEDIUM > LOW with no band weighting."""
        high = compute_urgency(compute_compliance_score(6, 8, WEIGHTS), signal(), 2.0)    # 46
        medium = compute_urgency(compute_compliance_score(3, 5, WEIGHTS), signal(), 2.0)  # 70
        low = compute_urgency(compute_compliance_score(0, 4, WEIGHTS), signal(), 2.0)     # 88
        assert high > medium > low
        assert (high, medium, low) == (54.0, 30.0, 12.0)

    def test_rising_trend_adds_urgency(self):
        compliance = compute_compliance_score(0, 4, WEIGHTS)
        flat = compute_urgency(compliance, signal(), 2.0)
        rising = compute_urgency(compliance, signal(recent_v=5, prev_v=1), 2.0)
        assert rising == flat + 8.0     # delta 4 x weight 2

    def test_weight_trend_is_configurable(self):
        compliance = compute_compliance_score(0, 4, WEIGHTS)
        trend = signal(recent_v=5, prev_v=1)
        assert compute_urgency(compliance, trend, 0.0) == 12.0    # trend disabled
        assert compute_urgency(compliance, trend, 10.0) == 52.0


class TestRankingAndTies:
    """The tie-break chain: urgency, then severity, then recent events, then mine_id."""

    def test_ranks_are_assigned_most_urgent_first(self):
        ranked = rank_candidates([
            candidate(1, score_breaches=4),               # urgency 12
            candidate(2, score_violations=6, score_breaches=8),   # urgency 54
            candidate(3, score_violations=3, score_breaches=5),   # urgency 30
        ])
        assert [c.mine_id for c in ranked] == [2, 3, 1]
        assert [c.rank for c in ranked] == [1, 2, 3]

    def test_exact_urgency_tie_breaks_on_severity(self):
        """Equal urgency: the mine already in the worse state wins.

        A low score is a measured fact; a trend is an inference from a short window. When the two
        produce the same number, trust the fact.
        """
        # A: score 76 (severity 24), steady          -> urgency 24
        # B: score 88 (severity 12), delta +6 x2=12  -> urgency 24
        worse_now = candidate(1, score_violations=0, score_breaches=8)
        rising = candidate(2, score_breaches=4, trend=signal(2, recent_v=6, prev_v=0))
        assert worse_now.urgency == rising.urgency == 24.0

        ranked = rank_candidates([rising, worse_now])
        assert [c.mine_id for c in ranked] == [1, 2]
        assert ranked[0].severity > ranked[1].severity

    def test_urgency_and_severity_tie_breaks_on_recent_events(self):
        """Same score and same delta, but one mine is far more active right now."""
        quiet = candidate(1, score_breaches=4, trend=signal(1, recent_v=1, prev_v=0))
        busy = candidate(2, score_breaches=4, trend=signal(2, recent_v=9, recent_b=4, prev_v=8,
                                                          prev_b=4))
        assert quiet.urgency == busy.urgency == 14.0
        assert quiet.severity == busy.severity

        ranked = rank_candidates([quiet, busy])
        assert [c.mine_id for c in ranked] == [2, 1]

    def test_total_tie_breaks_on_mine_id_deterministically(self):
        """Identical mines must not swap places between requests."""
        a = candidate(7, score_breaches=4)
        b = candidate(3, score_breaches=4)
        c = candidate(5, score_breaches=4)
        assert a.urgency == b.urgency == c.urgency

        first = [x.mine_id for x in rank_candidates([a, b, c])]
        shuffled = [x.mine_id for x in rank_candidates([c, a, b])]
        reversed_in = [x.mine_id for x in rank_candidates([b, c, a])]
        assert first == shuffled == reversed_in == [3, 5, 7]

    def test_ranking_is_stable_across_repeated_calls(self):
        pool = [candidate(i, score_breaches=4) for i in (4, 1, 9, 2)]
        assert [c.mine_id for c in rank_candidates(pool)] == \
               [c.mine_id for c in rank_candidates(pool)]

    def test_rising_low_risk_mine_can_overtake_a_stable_medium_one(self):
        """The point of including trend at all."""
        stable_medium = candidate(1, score_violations=3, score_breaches=5)   # 70, urgency 30
        rising_low = candidate(2, score_breaches=4,                          # 88, severity 12
                               trend=signal(2, recent_v=10, prev_v=0))       # +10 x2 = 20 -> 32
        ranked = rank_candidates([stable_medium, rising_low])
        assert [c.mine_id for c in ranked] == [2, 1]
        assert ranked[0].compliance.risk_level.value == "LOW"

    def test_boundary_score_80_ranks_below_boundary_score_79(self):
        """The LOW/MEDIUM boundary: 80 is LOW, 79 is MEDIUM, and the queue must agree."""
        at_80 = candidate(1, score_violations=4)          # exactly 80, severity 20
        at_79 = candidate(2, score_violations=0, score_breaches=7)  # 79, severity 21
        ranked = rank_candidates([at_80, at_79])
        assert [c.mine_id for c in ranked] == [2, 1]
        assert ranked[0].compliance.risk_level.value == "MEDIUM"
        assert ranked[1].compliance.risk_level.value == "LOW"

    def test_two_floored_mines_still_order_deterministically(self):
        """Both clamp to score 0, so severity ties at 100 - only mine_id can separate them."""
        a = candidate(9, score_violations=40, score_breaches=40)
        b = candidate(2, score_violations=60, score_breaches=60)
        assert a.compliance.score == b.compliance.score == 0.0
        assert [c.mine_id for c in rank_candidates([a, b])] == [2, 9]

    def test_empty_input(self):
        assert rank_candidates([]) == []


class TestQueueFromDatabase:
    def test_queue_orders_seeded_style_mines(self, db, make_mine):
        clean = make_mine(violations=0, breaches=0)
        middling = make_mine(violations=3, breaches=5)
        worst = make_mine(violations=6, breaches=8)

        queue = build_inspection_queue(db)
        assert [c.mine_id for c in queue] == [worst.id, middling.id, clean.id]
        assert queue[0].rank == 1

    def test_queue_is_scoped_to_requested_mines(self, db, make_mine):
        first = make_mine(violations=6, breaches=8)
        make_mine(violations=0, breaches=0)
        queue = build_inspection_queue(db, mine_ids=[first.id])
        assert [c.mine_id for c in queue] == [first.id]

    def test_empty_database(self, db):
        assert build_inspection_queue(db) == []

    def test_reasons_are_populated(self, db, make_mine):
        mine = make_mine(violations=6, breaches=8)
        candidate_row = build_inspection_queue(db)[0]
        assert candidate_row.reasons
        assert "High Risk" in candidate_row.reasons[0]

    @pytest.mark.parametrize("weight", [0.0, 5.0])
    def test_weight_trend_override_flows_through(self, db, make_mine, weight):
        make_mine(violations=1, breaches=1)
        queue = build_inspection_queue(db, weight_trend=weight)
        assert queue[0].urgency == 8.0 + queue[0].trend.pressure * weight
