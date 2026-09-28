# ai-service ML assets

| Path | What | In git |
|---|---|---|
| `weights/ppe.pt` | the PPE detector: YOLO11n fine-tuned on the S13 PPE dataset | no (Ultralytics AGPL-3.0, size) - build it |
| `runs/` | training runs, logs and downloaded base weights (`scripts/build_ppe_model.py`) | no |
| `../risk/model.json` | the predictive model (gradient boosting on MSHA mine-years), exported trees | yes |
| `../samples/` | sample and held-out images for the demo and the tests (licences in their READMEs) | yes |

## PPE weights

Build them once per machine (about 25 minutes on a laptop CPU; the dataset is downloaded by the
data track, `data\run_data.bat download`):

```bat
ai-service\.venv\Scripts\python.exe scripts\build_ppe_model.py
```

The model, its licence and its held-out results are in `docs/AI_EVALUATION.md`, section 3.
`YOLO_WEIGHTS_PATH` (default `ml/weights/ppe.pt`, relative to `ai-service/`) points elsewhere if
needed.

## Two detector backends

`vision/detector.py` picks one automatically:

| Backend | When | What it does |
|---|---|---|
| `YoloDetector` | the weights file exists | real inference with Ultralytics |
| `FixtureDetector` | no weights, or `PPE_DETECTOR=fixture` | reads detections from a `.detections.json` sidecar next to a sample image |

`FixtureDetector` is a test double, not a model: it never invents detections (no sidecar, no
detections). `GET /health` and every `/vision/ppe` answer say which backend ran.

## Choosing the held-out demo images

```bat
ai-service\.venv\Scripts\python.exe scripts\select_ppe_demo_images.py
```

runs the installed model over the test split and copies images where the model and the ground
truth agree to `samples/heldout/` (see its `selection.json`).

## Not carried over from the prototype

The FastAPI prototype (removed in Phase 8) also had an IsolationForest over each sensor tick
(`sensor_anomaly.joblib`), trained on its own five-mine seed. That seed no longer exists and the
dashboards never showed the score, so it was not ported (decided in Phase 2, `docs/API_CHANGES.md`).
Sensor anomalies are covered by the Phase 7 detectors (a flatlined sensor, evaluated in
`docs/AI_EVALUATION.md`), and breaches stay tied to the legal limits.
