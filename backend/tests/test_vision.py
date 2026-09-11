"""PPE detection on a sample frame maps to the expected violation types."""

import json

import pytest

from app.services.vision.detector import Detection, FixtureDetector
from app.services.vision.ingest import analyse_image
from app.services.vision.ppe_rules import (
    PpePolicy,
    ViolationCandidate,
    containment,
    model_names_have_negatives,
    normalise_label,
    violations_from_detections,
)
from app.services.vision.video_pipeline import deduplicate


def det(label, confidence=0.9, bbox=(0, 0, 10, 10), frame_index=0):
    return Detection(
        label=normalise_label(label),
        raw_label=label,
        confidence=confidence,
        bbox=bbox,
        frame_index=frame_index,
    )


class TestLabelNormalisation:
    """Two model vocabularies, one canonical set of tokens."""

    @pytest.mark.parametrize(
        ("raw", "expected"),
        [
            ("Hardhat", "helmet"),
            ("hard hat", "helmet"),
            ("NO-Hardhat", "missing_helmet"),
            ("head", "missing_helmet"),          # positive-only datasets mark a bare head
            ("Safety Vest", "vest"),
            ("NO-Safety Vest", "missing_vest"),
            ("without vest", "missing_vest"),    # unseen spelling, still resolved
            ("Person", "person"),
            ("forklift", None),                  # irrelevant class dropped
        ],
    )
    def test_aliases(self, raw, expected):
        assert normalise_label(raw) == expected

    def test_negative_vocabulary_is_detected(self):
        assert model_names_have_negatives(["Hardhat", "NO-Hardhat", "Person"]) is True
        assert model_names_have_negatives(["helmet", "person"]) is False


class TestExplicitNegatives:
    def test_negative_classes_become_violations(self):
        found = violations_from_detections([det("NO-Hardhat"), det("NO-Safety Vest")], infer=False)
        assert {v.violation_type for v in found} == {"no_helmet", "no_safety_vest"}
        assert all(v.basis == "explicit" for v in found)

    def test_low_confidence_is_gated_out(self):
        policy = PpePolicy(min_confidence=0.45)
        assert violations_from_detections([det("NO-Hardhat", 0.20)], policy, infer=False) == []
        assert len(violations_from_detections([det("NO-Hardhat", 0.80)], policy, infer=False)) == 1


class TestPersonInference:
    """Positive-only models: a person without required PPE is the violation."""

    PERSON = (100, 60, 200, 340)

    def test_unequipped_person_raises_both_violations(self):
        found = violations_from_detections([det("person", 0.92, self.PERSON)])
        assert {v.violation_type for v in found} == {"no_helmet", "no_safety_vest"}
        assert all(v.basis == "inferred" for v in found)

    def test_fully_equipped_person_raises_nothing(self):
        found = violations_from_detections([
            det("person", 0.94, self.PERSON),
            det("Hardhat", 0.91, (116, 78, 184, 116)),
            det("Safety Vest", 0.88, (115, 150, 185, 260)),
        ])
        assert found == []

    def test_partial_ppe_raises_only_what_is_missing(self):
        found = violations_from_detections([
            det("person", 0.94, self.PERSON),
            det("Hardhat", 0.91, (116, 78, 184, 116)),
        ])
        assert [v.violation_type for v in found] == ["no_safety_vest"]

    def test_ppe_worn_by_someone_else_does_not_count(self):
        """A helmet on a different worker must not clear this one - the box has to overlap."""
        found = violations_from_detections([
            det("person", 0.94, self.PERSON),
            det("Hardhat", 0.91, (500, 78, 560, 116)),  # far away
        ])
        assert {v.violation_type for v in found} == {"no_helmet", "no_safety_vest"}

    def test_inference_is_skipped_for_negative_vocabulary_models(self):
        """Otherwise a bare head would be counted twice: once named, once inferred."""
        detections = [det("person", 0.92, self.PERSON), det("NO-Hardhat", 0.9, (116, 78, 184, 116))]
        assert len(violations_from_detections(detections, infer=False)) == 1


class TestGeometry:
    def test_containment_of_helmet_in_person(self):
        assert containment((116, 78, 184, 116), (100, 60, 200, 340)) == 1.0

    def test_no_overlap_is_zero(self):
        assert containment((0, 0, 10, 10), (100, 100, 200, 200)) == 0.0


class TestFixtureDetector:
    """The offline backend reads a sidecar; it never invents detections."""

    def test_reads_sidecar(self, tmp_path):
        image = tmp_path / "frame.jpg"
        image.write_bytes(b"not-a-real-jpeg")
        FixtureDetector.sidecar_for(image).write_text(
            json.dumps([{"label": "NO-Hardhat", "confidence": 0.9, "bbox": [1, 2, 3, 4]}]),
            encoding="utf-8",
        )
        found = FixtureDetector().detect(image)
        assert len(found) == 1
        assert found[0].label == "missing_helmet"
        assert found[0].raw_label == "NO-Hardhat"

    def test_no_sidecar_yields_nothing(self, tmp_path):
        image = tmp_path / "bare.jpg"
        image.write_bytes(b"x")
        assert FixtureDetector().detect(image) == []


class TestDeduplication:
    def test_same_violation_across_frames_counts_once(self):
        repeated = [
            ViolationCandidate("no_helmet", 0.7 + i / 100, (10, 10, 60, 60), frame_index=i)
            for i in range(8)
        ]
        assert len(deduplicate(repeated)) == 1

    def test_highest_confidence_instance_survives(self):
        candidates = [
            ViolationCandidate("no_helmet", 0.6, (10, 10, 60, 60)),
            ViolationCandidate("no_helmet", 0.95, (12, 12, 62, 62)),
        ]
        assert deduplicate(candidates)[0].confidence == 0.95

    def test_separate_workers_are_kept_apart(self):
        candidates = [
            ViolationCandidate("no_helmet", 0.9, (10, 10, 60, 60)),
            ViolationCandidate("no_helmet", 0.9, (500, 500, 560, 560)),
        ]
        assert len(deduplicate(candidates)) == 2


class TestIngestAffectsScore:
    """The whole point: a detection must move the mine's live score."""

    def _sample(self, tmp_path, detections):
        image = tmp_path / "scene.jpg"
        image.write_bytes(b"x")
        FixtureDetector.sidecar_for(image).write_text(json.dumps(detections), encoding="utf-8")
        return image

    def test_violation_lowers_score_and_writes_rows(self, db, make_mine, tmp_path):
        mine = make_mine(violations=0, breaches=0)
        image = self._sample(tmp_path, [
            {"label": "person", "confidence": 0.92, "bbox": [100, 60, 200, 340]}
        ])
        result = analyse_image(db, mine.id, image, detector=FixtureDetector(),
                               annotate=False, commit=False)

        assert result.score_before.score == 100.0
        assert result.score_after.score == 90.0   # 2 violations x 5
        assert result.score_delta == -10.0
        assert len(result.violations) == 2
        assert len(result.alerts) == 2
        assert all(v.source == "VISION" for v in result.violations)

    def test_clean_frame_leaves_score_untouched(self, db, make_mine, tmp_path):
        mine = make_mine(violations=0, breaches=0)
        image = self._sample(tmp_path, [
            {"label": "person", "confidence": 0.94, "bbox": [100, 60, 200, 340]},
            {"label": "Hardhat", "confidence": 0.91, "bbox": [116, 78, 184, 116]},
            {"label": "Safety Vest", "confidence": 0.88, "bbox": [115, 150, 185, 260]},
        ])
        result = analyse_image(db, mine.id, image, detector=FixtureDetector(),
                               annotate=False, commit=False)
        assert result.violations == []
        assert result.score_delta == 0.0
        assert result.risk_changed is False

    def test_detection_can_move_the_risk_band(self, db, make_mine, tmp_path):
        """A mine at 84 (LOW) crossing into MEDIUM - the demo moment."""
        mine = make_mine(violations=0, breaches=4)   # 100 - 12 = 88, LOW
        image = self._sample(tmp_path, [
            {"label": "person", "confidence": 0.92, "bbox": [100, 60, 200, 340]}
        ])
        result = analyse_image(db, mine.id, image, detector=FixtureDetector(),
                               annotate=False, commit=False)
        assert result.score_before.risk_level.value == "LOW"
        assert result.score_after.score == 78.0
        assert result.score_after.risk_level.value == "MEDIUM"
        assert result.risk_changed is True


class TestRequiredPpePolicy:
    """required_ppe governs BOTH detection paths, not just inference.

    This model emits NO-Mask for anyone without a face mask. On a site where masks are not
    mandatory those must not become violations, or they swamp the helmet and vest findings.
    """

    def test_explicit_negative_outside_required_ppe_is_ignored(self):
        policy = PpePolicy(required_ppe=("helmet", "vest"))
        found = violations_from_detections([det("NO-Mask", 0.9)], policy, infer=False)
        assert found == []

    def test_same_detection_counts_when_masks_are_mandatory(self):
        policy = PpePolicy(required_ppe=("helmet", "vest", "mask"))
        found = violations_from_detections([det("NO-Mask", 0.9)], policy, infer=False)
        assert [v.violation_type for v in found] == ["no_dust_mask"]

    def test_required_ppe_does_not_suppress_helmet_or_vest(self):
        policy = PpePolicy(required_ppe=("helmet", "vest"))
        found = violations_from_detections(
            [det("NO-Hardhat", 0.9), det("NO-Safety Vest", 0.9), det("NO-Mask", 0.9)],
            policy, infer=False,
        )
        assert {v.violation_type for v in found} == {"no_helmet", "no_safety_vest"}

    def test_policy_reads_settings(self, monkeypatch):
        from app.core import config

        monkeypatch.setattr(config.settings, "required_ppe", "helmet, vest , mask")
        assert PpePolicy.from_settings().required_ppe == ("helmet", "vest", "mask")
