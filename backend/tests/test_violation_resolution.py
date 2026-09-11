"""Score recovery: a clean re-inspection clears open violations (PRD 6.1 follow-up).

Before this, compliance was a one-way ratchet - a mine that fixed a problem carried it
forever. These cover the drop, the recovery, and the guards that stop the recovery path
from being trivially abusable.
"""

import json

import pytest

from app.models.corrective_action import CorrectiveAction
from app.models.audit_log import AuditLog
from app.models.violation import Violation
from app.services.compliance.resolution import is_compliance_evidence, resolve_open_violations
from app.services.compliance.scoring import score_mine
from app.services.vision.detector import Detection, FixtureDetector
from app.services.vision.ingest import analyse_image
from app.services.vision.ppe_rules import normalise_label

VIOLATION_FRAME = [{"label": "person", "confidence": 0.92, "bbox": [100, 60, 200, 340]}]
CLEAN_FRAME = [
    {"label": "Person", "confidence": 0.89, "bbox": [100, 60, 200, 340]},
    {"label": "Hardhat", "confidence": 0.74, "bbox": [116, 78, 184, 116]},
    {"label": "Safety Vest", "confidence": 0.81, "bbox": [115, 150, 185, 260]},
]
EMPTY_FRAME = []


def detection(raw, confidence=0.9, bbox=(0, 0, 10, 10)):
    return Detection(label=normalise_label(raw), raw_label=raw, confidence=confidence, bbox=bbox)


@pytest.fixture
def frame(tmp_path):
    """Write an image plus the sidecar the fixture detector reads."""
    counter = {"n": 0}

    def _make(detections):
        counter["n"] += 1
        path = tmp_path / f"frame_{counter['n']}.jpg"
        path.write_bytes(b"x")
        FixtureDetector.sidecar_for(path).write_text(json.dumps(detections), encoding="utf-8")
        return path

    return _make


class TestComplianceEvidence:
    """Zero violations alone is not proof of anything."""

    def test_clean_frame_with_workers_is_evidence(self):
        accepted, reason = is_compliance_evidence(
            [detection("Person"), detection("Hardhat"), detection("Safety Vest")], []
        )
        assert accepted is True
        assert "helmet" in reason and "person" in reason

    def test_empty_frame_is_not_evidence(self):
        """A photograph of an empty corridor also has zero violations."""
        accepted, reason = is_compliance_evidence([], [])
        assert accepted is False
        assert "no workers or PPE" in reason

    def test_frame_of_only_irrelevant_objects_is_not_evidence(self):
        accepted, _ = is_compliance_evidence([detection("machinery"), detection("vehicle")], [])
        assert accepted is False

    def test_frame_with_violations_is_not_evidence(self):
        accepted, reason = is_compliance_evidence([detection("Person")], ["a violation"])
        assert accepted is False
        assert "1 violation" in reason


class TestScoreRecovery:
    """The headline behaviour: a score can go back up."""

    def test_score_drops_then_recovers_after_a_clean_run(self, db, make_mine, frame, weights):
        mine = make_mine(violations=0, breaches=0)
        detector = FixtureDetector()

        dirty = analyse_image(db, mine.id, frame(VIOLATION_FRAME), detector=detector,
                              annotate=False, commit=False)
        assert dirty.score_before.score == 100.0
        assert dirty.score_after.score == 90.0          # 2 violations x weight_ppe 5
        assert len(dirty.violations) == 2
        assert dirty.resolved_count == 0

        clean = analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=detector,
                              annotate=False, commit=False)
        assert clean.violations == []
        assert clean.resolved_count == 2
        assert clean.is_clean_run is True
        assert clean.score_before.score == 90.0
        assert clean.score_after.score == 100.0         # fully recovered
        assert clean.score_delta == 10.0

    def test_recovery_is_capped_at_100(self, db, make_mine, frame, weights):
        mine = make_mine(violations=0, breaches=0)
        result = analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=FixtureDetector(),
                               annotate=False, commit=False)
        assert result.score_after.score == 100.0
        assert result.score_after.score <= 100.0

    def test_breaches_still_hold_the_score_down(self, db, make_mine, frame):
        """Resolution clears PPE violations only. A mine with live environmental
        breaches must not be able to score 100 by uploading a tidy photograph."""
        mine = make_mine(violations=0, breaches=4)      # 100 - 12 = 88
        analyse_image(db, mine.id, frame(VIOLATION_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)
        clean = analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=FixtureDetector(),
                              annotate=False, commit=False)
        assert clean.score_after.score == 88.0
        assert clean.score_after.breach_count == 4

    def test_empty_frame_cannot_clear_violations(self, db, make_mine, frame, weights):
        mine = make_mine(violations=0, breaches=0)
        analyse_image(db, mine.id, frame(VIOLATION_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)
        before = score_mine(db, mine.id, weights).score

        result = analyse_image(db, mine.id, frame(EMPTY_FRAME), detector=FixtureDetector(),
                               annotate=False, commit=False)
        assert result.resolved_count == 0
        assert result.resolution.accepted is False
        assert score_mine(db, mine.id, weights).score == before

    def test_a_clean_run_on_a_clean_mine_changes_nothing(self, db, make_mine, frame):
        mine = make_mine(violations=0, breaches=0)
        result = analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=FixtureDetector(),
                               annotate=False, commit=False)
        assert result.score_delta == 0.0
        assert result.resolved_count == 0


class TestHistoryIsPreserved:
    """Resolved does not mean deleted."""

    def test_resolved_violations_stay_in_the_table(self, db, make_mine, frame):
        mine = make_mine(violations=0, breaches=0)
        analyse_image(db, mine.id, frame(VIOLATION_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)
        analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)

        rows = db.query(Violation).filter_by(mine_id=mine.id).all()
        assert len(rows) == 2                              # still there
        assert all(v.resolved for v in rows)
        assert all(v.resolved_at is not None for v in rows)

    def test_audit_trail_records_both_the_finding_and_the_resolution(self, db, make_mine, frame):
        mine = make_mine(violations=0, breaches=0)
        analyse_image(db, mine.id, frame(VIOLATION_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)
        analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)

        actions = [a.action for a in db.query(AuditLog).filter_by(mine_id=mine.id).all()]
        assert "PPE_VIOLATION_DETECTED" in actions
        assert "VIOLATIONS_RESOLVED" in actions

    def test_a_corrective_action_is_written_per_resolved_violation(self, db, make_mine, frame):
        mine = make_mine(violations=0, breaches=0)
        analyse_image(db, mine.id, frame(VIOLATION_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)
        analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)

        actions = db.query(CorrectiveAction).filter_by(mine_id=mine.id).all()
        assert len(actions) == 2
        assert all(a.status == "RESOLVED" for a in actions)
        assert all(a.violation_id is not None for a in actions)
        assert all("Evidence frame" in a.description for a in actions)

    def test_resolution_is_idempotent(self, db, make_mine, frame):
        """A second clean upload has nothing left to clear and must not double-count."""
        mine = make_mine(violations=0, breaches=0)
        analyse_image(db, mine.id, frame(VIOLATION_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)
        analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=FixtureDetector(),
                      annotate=False, commit=False)
        again = analyse_image(db, mine.id, frame(CLEAN_FRAME), detector=FixtureDetector(),
                             annotate=False, commit=False)

        assert again.resolved_count == 0
        assert db.query(CorrectiveAction).filter_by(mine_id=mine.id).count() == 2


class TestDirectResolution:
    def test_resolve_marks_and_logs(self, db, make_mine):
        mine = make_mine(violations=3, breaches=0)
        outcome = resolve_open_violations(db, mine.id, evidence_ref="frame.jpg",
                                          reason="clean frame", actor="tester")
        assert outcome.count == 3
        assert db.query(Violation).filter_by(mine_id=mine.id, resolved=True).count() == 3

    def test_resolve_with_nothing_open_is_a_no_op(self, db, make_mine):
        mine = make_mine(violations=0, breaches=0)
        outcome = resolve_open_violations(db, mine.id, evidence_ref="f.jpg", reason="clean")
        assert outcome.count == 0
        assert db.query(CorrectiveAction).count() == 0
