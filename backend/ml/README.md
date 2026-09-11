# ML assets

PRD Section 8 locks in a **pretrained** PPE detection model — no custom training for the prototype.

- `weights/` — YOLO `.pt` weights. Gitignored: download them during setup, do not commit.
- `notebooks/` — scratch work for tuning `weight_ppe` / `weight_env` and sensor thresholds (PRD 8.1).

The weights path is configured via `YOLO_WEIGHTS_PATH` in `backend/.env`
(default: `ml/weights/ppe.pt`, resolved relative to `backend/`).

## Two detector backends

`services/vision/detector.py` picks one automatically:

| Backend | When | What it does |
|---|---|---|
| `YoloDetector` | weights file exists | Real inference via ultralytics |
| `FixtureDetector` | no weights found | Reads detections from a `.detections.json` sidecar |

`FixtureDetector` is a **test double, not a model**. It exists so the
detect → violation → score → dashboard path can be tested on any machine with no weights and no
GPU. It never invents detections: no sidecar means no detections. Check `.backend` when you need
to know which one ran — the API returns it in every `/vision/analyze` response.

## Installed model

| | |
|---|---|
| Source | [Hansung-Cho/yolov8-ppe-detection](https://huggingface.co/Hansung-Cho/yolov8-ppe-detection) (Hugging Face) |
| File | `best.pt` -> stored as `ml/weights/ppe.pt` |
| Size | 6.25 MB (YOLOv8n) |
| SHA-256 | `2419700bbe3b8d38f9000655d9cf952a4bc93ef6c143baf8b49a0abde5d0760f` |
| Licence | MIT |
| Architecture | YOLOv8-nano — chosen over larger variants because the demo runs CPU-only |

**Class list (10)** and how `ppe_rules.py` maps each one:

| # | Model class | Canonical | Effect |
|---|---|---|---|
| 0 | `Hardhat` | `helmet` | clears a helmet requirement |
| 1 | `Mask` | `mask` | clears a mask requirement |
| 2 | `NO-Hardhat` | `missing_helmet` | -> `no_helmet` violation |
| 3 | `NO-Mask` | `missing_mask` | -> `no_dust_mask`, **only if `mask` is in `REQUIRED_PPE`** |
| 4 | `NO-Safety Vest` | `missing_vest` | -> `no_safety_vest` violation |
| 5 | `Person` | `person` | subject for inference (unused here) |
| 6 | `Safety Cone` | — | ignored |
| 7 | `Safety Vest` | `vest` | clears a vest requirement |
| 8 | `machinery` | — | ignored |
| 9 | `vehicle` | — | ignored |

Because this model names negatives itself, `model_names_have_negatives()` returns True and
person-based inference is switched off automatically — no double counting.

**Vocabulary changes needed: none.** Every class already resolved through the existing alias table.

### Measured CPU inference

Roughly 50–80 ms per 1280px frame after warm-up (first call ~2 s including model load). Comfortably
fast enough to sample video frames live.

## Installing weights

Drop a `.pt` file at `ml/weights/ppe.pt`. Nothing else changes: the same code path runs, and
`build_detector()` picks up the real model on next start.

**The model must expose PPE classes.** Either family works — the label normaliser in
`ppe_rules.py` handles both:

- explicit negatives: `['Hardhat', 'NO-Hardhat', 'Safety Vest', 'NO-Safety Vest', 'Person', ...]`
- positive-only: `['helmet', 'head', 'person']` (a bare `head` is the violation)

**A plain COCO model such as `yolov8n.pt` is not suitable.** COCO has a `person` class but no
helmet or vest class, so the inference path would find no PPE on anyone and flag *every worker* as
a double violation. That is a false-positive generator, not a detector. Use a PPE-trained model.

## Verifying a new model

```
python scripts/demo_vision.py --dry-run
```

Prints the detector backend, every raw class the model emitted with its canonical mapping, and the
before/after compliance score. `--dry-run` rolls the database back, so it is safe to run
repeatedly while tuning.
