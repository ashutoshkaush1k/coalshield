"""Write the expected outputs of the detector and model parity fixtures.

The inputs (api/tests/_data/detectors/*.input.json) are dumped from the demo database by
`php yii ai/fixtures`. This runs the Python side on them and writes *.expected.json next to them.
The pytest suite (ai-service/tests) then checks the Python side still produces them, and the PHP
suite (DetectorParityTest, RiskModelParityTest) checks the PHP twins produce the same thing - so
the fallback engine and the service answer alike.

    backend\\.venv\\Scripts\\python ai-service\\tests\\make_expected.py
"""

import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from detectors import DETECTORS  # noqa: E402
from risk import model as risk_model  # noqa: E402

FIXTURES = HERE.parents[1] / "api" / "tests" / "_data" / "detectors"


def write(path: Path, data) -> None:
    path.write_text(json.dumps(data, indent=1, ensure_ascii=False) + "\n", encoding="utf-8")


def main() -> None:
    for name, detect in DETECTORS.items():
        payload = json.loads((FIXTURES / f"{name}.input.json").read_text(encoding="utf-8"))
        flags = detect(payload)
        write(FIXTURES / f"{name}.expected.json", {"flags": flags})
        print(f"{name:20} {len(flags):3} flag(s)")
    payload = json.loads((FIXTURES / "risk_predict.input.json").read_text(encoding="utf-8"))
    result = risk_model.predict(payload["mines"])
    del result["engine"]
    write(FIXTURES / "risk_predict.expected.json", result)
    print(f"{'risk_predict':20} {len(result['predictions']):3} mine(s)")


if __name__ == "__main__":
    main()
