"""Compliance formula and risk banding (PRD 6.1).

Boundaries get their own cases because an off-by-one there is invisible on a dashboard: a mine
sitting at exactly 80 would quietly show yellow instead of green, and nobody would notice until a
judge asked why.
"""

import pytest

from app.services.compliance.risk import RiskLevel, risk_from_score
from app.services.compliance.scoring import (
    compute_compliance_score,
    record_score,
    score_mine,
    score_mines,
)
from app.services.compliance.weights import ScoringWeights


class TestPureFormula:
    """PRD 6.1: Score = 100 - (violations x weight_ppe + breaches x weight_env)."""

    def test_clean_mine_scores_100_and_is_low_risk(self, weights):
        result = compute_compliance_score(violation_count=0, breach_count=0, weights=weights)
        assert result.score == 100.0
        assert result.risk_level is RiskLevel.LOW
        assert result.total_penalty == 0.0

    def test_ppe_violations_only(self, weights):
        # 3 violations x 5 = 15 penalty
        result = compute_compliance_score(violation_count=3, breach_count=0, weights=weights)
        assert result.violation_penalty == 15.0
        assert result.environmental_penalty == 0.0
        assert result.score == 85.0
        assert result.risk_level is RiskLevel.LOW

    def test_environmental_breaches_only(self, weights):
        # 9 breaches x 3 = 27 penalty
        result = compute_compliance_score(violation_count=0, breach_count=9, weights=weights)
        assert result.violation_penalty == 0.0
        assert result.environmental_penalty == 27.0
        assert result.score == 73.0
        assert result.risk_level is RiskLevel.MEDIUM

    def test_both_penalties_combine(self, weights):
        # 6 x 5 = 30, plus 8 x 3 = 24, total 54 -> 46
        result = compute_compliance_score(violation_count=6, breach_count=8, weights=weights)
        assert result.violation_penalty == 30.0
        assert result.environmental_penalty == 24.0
        assert result.total_penalty == 54.0
        assert result.score == 46.0
        assert result.risk_level is RiskLevel.HIGH

    def test_score_floors_at_zero_and_flags_it(self, weights):
        result = compute_compliance_score(violation_count=40, breach_count=40, weights=weights)
        assert result.score == 0.0
        assert result.risk_level is RiskLevel.HIGH
        # The raw score is kept so two floored mines can still be told apart.
        assert result.raw_score == -220.0
        assert result.was_floored is True

    def test_negative_counts_rejected(self, weights):
        with pytest.raises(ValueError):
            compute_compliance_score(violation_count=-1, breach_count=0, weights=weights)
        with pytest.raises(ValueError):
            compute_compliance_score(violation_count=0, breach_count=-1, weights=weights)


class TestRiskBoundaries:
    """80-100 Low (Green), 50-79 Medium (Yellow), 0-49 High (Red)."""

    @pytest.mark.parametrize(
        ("score", "expected"),
        [
            (100.0, RiskLevel.LOW),
            (80.0, RiskLevel.LOW),      # exactly 80 is LOW, not MEDIUM
            (79.9, RiskLevel.MEDIUM),
            (79.0, RiskLevel.MEDIUM),
            (50.0, RiskLevel.MEDIUM),   # exactly 50 is MEDIUM, not HIGH
            (49.9, RiskLevel.HIGH),
            (49.0, RiskLevel.HIGH),
            (0.0, RiskLevel.HIGH),
        ],
    )
    def test_band_edges(self, score, expected):
        assert risk_from_score(score) is expected

    def test_exactly_80_via_the_formula(self, weights):
        # 4 violations x 5 = 20 penalty -> exactly 80
        result = compute_compliance_score(violation_count=4, breach_count=0, weights=weights)
        assert result.score == 80.0
        assert result.risk_level is RiskLevel.LOW

    def test_exactly_50_via_the_formula(self, weights):
        # 10 violations x 5 = 50 penalty -> exactly 50
        result = compute_compliance_score(violation_count=10, breach_count=0, weights=weights)
        assert result.score == 50.0
        assert result.risk_level is RiskLevel.MEDIUM

    def test_colours_match_the_prd(self):
        assert RiskLevel.LOW.colour == "green"
        assert RiskLevel.MEDIUM.colour == "yellow"
        assert RiskLevel.HIGH.colour == "red"


class TestWeightsAreConfigurable:
    """Weights must be tunable without a code change (PRD 8.1)."""

    def test_same_counts_score_differently_under_different_weights(self):
        lenient = compute_compliance_score(2, 2, ScoringWeights(weight_ppe=1.0, weight_env=1.0))
        strict = compute_compliance_score(2, 2, ScoringWeights(weight_ppe=10.0, weight_env=10.0))
        assert lenient.score == 96.0
        assert strict.score == 60.0

    def test_weights_come_from_settings_when_omitted(self, monkeypatch):
        from app.core import config

        monkeypatch.setattr(config.settings, "weight_ppe", 20.0)
        monkeypatch.setattr(config.settings, "weight_env", 0.0)
        result = compute_compliance_score(violation_count=2, breach_count=50)
        assert result.score == 60.0  # 2 x 20, breaches weighted to zero

    def test_negative_weights_rejected(self):
        with pytest.raises(ValueError):
            ScoringWeights(weight_ppe=-1.0, weight_env=3.0)

    def test_weights_are_recorded_on_the_result(self, weights):
        result = compute_compliance_score(1, 1, weights)
        assert result.weights == weights


class TestScoringFromDatabase:
    """The same formula, fed by real violation and sensor rows."""

    def test_clean_mine(self, db, make_mine, weights):
        mine = make_mine(violations=0, breaches=0, clean_readings=12)
        result = score_mine(db, mine.id, weights)
        assert result.score == 100.0
        assert result.risk_level is RiskLevel.LOW

    def test_ppe_violations_only(self, db, make_mine, weights):
        mine = make_mine(violations=3, breaches=0, clean_readings=10)
        result = score_mine(db, mine.id, weights)
        assert result.violation_count == 3
        assert result.breach_count == 0
        assert result.score == 85.0

    def test_environmental_breaches_only(self, db, make_mine, weights):
        mine = make_mine(violations=0, breaches=9, clean_readings=10)
        result = score_mine(db, mine.id, weights)
        assert result.violation_count == 0
        assert result.breach_count == 9
        assert result.score == 73.0
        assert result.risk_level is RiskLevel.MEDIUM

    def test_both(self, db, make_mine, weights):
        mine = make_mine(violations=6, breaches=8, clean_readings=20)
        result = score_mine(db, mine.id, weights)
        assert result.score == 46.0
        assert result.risk_level is RiskLevel.HIGH

    def test_compliant_readings_do_not_penalise(self, db, make_mine, weights):
        """Only readings flagged as breaches count; the rest are evidence of compliance."""
        mine = make_mine(violations=0, breaches=2, clean_readings=100)
        result = score_mine(db, mine.id, weights)
        assert result.breach_count == 2
        assert result.score == 94.0

    def test_mines_are_scored_independently(self, db, make_mine, weights):
        clean = make_mine(violations=0, breaches=0)
        dirty = make_mine(violations=6, breaches=8)
        scores = score_mines(db, [clean.id, dirty.id], weights)
        assert scores[clean.id].score == 100.0
        assert scores[dirty.id].score == 46.0

    def test_score_is_recomputed_not_incremented(self, db, make_mine, weights):
        """Re-scoring unchanged data returns the same number, and removing a violation raises the
        score back. A running total could do neither."""
        from app.models.violation import Violation

        mine = make_mine(violations=2, breaches=0)
        first = score_mine(db, mine.id, weights)
        second = score_mine(db, mine.id, weights)
        assert first.score == second.score == 90.0

        db.delete(db.query(Violation).filter_by(mine_id=mine.id).first())
        db.flush()
        assert score_mine(db, mine.id, weights).score == 95.0

    def test_recorded_history_captures_the_weights_in_force(self, db, make_mine, weights):
        mine = make_mine(violations=2, breaches=2)
        result = score_mine(db, mine.id, weights)
        row = record_score(db, mine.id, result)
        assert row.score == 84.0
        assert row.risk_level == "LOW"
        assert (row.weight_ppe, row.weight_env) == (5.0, 3.0)
        assert (row.violation_count, row.breach_count) == (2, 2)
