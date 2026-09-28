"""The detectors and the model against the shared fixtures (see make_expected.py).

The PHP twins are checked against the same *.expected.json by api/tests/unit/DetectorParityTest.php
and RiskModelParityTest.php.
"""

import json
from pathlib import Path

import pytest

from detectors import DETECTORS
from risk import model as risk_model

FIXTURES = Path(__file__).resolve().parents[2] / "api" / "tests" / "_data" / "detectors"


def load(name: str):
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


@pytest.mark.parametrize("name", sorted(DETECTORS))
def test_detector_matches_fixture(name):
    assert DETECTORS[name](load(f"{name}.input.json")) == load(f"{name}.expected.json")["flags"]


@pytest.mark.parametrize("name", sorted(DETECTORS))
def test_detector_is_stateless(name):
    payload = load(f"{name}.input.json")
    before = json.dumps(payload, sort_keys=True)
    first = DETECTORS[name](payload)
    assert json.dumps(payload, sort_keys=True) == before, "a detector must not change its input"
    assert DETECTORS[name](payload) == first


def test_every_flag_has_the_contract_shape():
    for name in DETECTORS:
        for flag in load(f"{name}.expected.json")["flags"]:
            assert flag["detector"] == name
            assert set(flag) >= {"detector", "mine_id", "subject", "from", "to", "score", "reasons", "entities"}
            assert flag["reasons"] and all(set(r) == {"code", "params"} for r in flag["reasons"])


def test_empty_payload_gives_no_flags():
    for name, detect in DETECTORS.items():
        payload = load(f"{name}.input.json")
        empty = {k: ([] if isinstance(v, list) else v) for k, v in payload.items()}
        assert detect(empty) == [], name


def test_risk_prediction_matches_fixture():
    result = risk_model.predict(load("risk_predict.input.json")["mines"])
    assert result.pop("engine") == "ai-service"
    assert result == load("risk_predict.expected.json")


def test_risk_prediction_is_monotonic_in_violations():
    doc = risk_model.load()
    x = dict(doc["reference"])
    base = risk_model.probability(doc, x)
    for f in ("violations_per_100", "roof_strata_per_100", "lost_time_per_100"):
        higher = dict(x, **{f: x[f] * 3 + 10})
        assert risk_model.probability(doc, higher) >= base, f


def test_model_card_states_the_transfer():
    card = risk_model.card()
    assert "MSHA" in card["trained_on"] and "US" in card["trained_on"]
    assert "Indian" in card["transfer"]
    assert card["test"]["model"]["auc"] > card["test"]["baseline"]["auc"]
    assert card["model"]["exported_trees_max_diff"] < 1e-9
