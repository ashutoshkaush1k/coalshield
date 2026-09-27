# AI evaluation - PPE detection

The ai-service (`ai-service/`) detects PPE in an uploaded image with a YOLO model and the
prototype's PPE rules (`backend/app/services/vision/ppe_rules.py`): a person detected without the
required PPE (helmet and vest by default, `REQUIRED_PPE`) becomes a violation. This page records
where the model comes from, its licences and how well it does on held-out data.

## Model choice (2026-09-27)

First choice was an openly licensed, downloadable pretrained PPE model. Candidates checked:

| Candidate | Why not used |
|---|---|
| `keremberke/yolov8n-hard-hat-detection` (Hugging Face) | No licence stated in the model card or metadata - no right to use or redistribute. |
| `Hansung-Cho/yolov8-ppe-detection` (Hugging Face) | Labelled MIT, but trained on "public PPE / construction-related datasets (Kaggle etc.)" whose licence "follows the data provider"; the training data cannot be identified, so the licence chain cannot be recorded. |
| SH17 release weights | The SH17 images need a Kaggle account (data/SOURCES.md, S13); not downloadable without login. |

So the model is **fine-tuned here on a dataset with a known licence**, reproducibly, by
`scripts/build_ppe_model.py`:

| Part | What | Licence |
|---|---|---|
| Base weights | Ultralytics YOLO11n, COCO-pretrained (`yolo11n.pt`, Ultralytics GitHub release assets, no login) | AGPL-3.0 (see below) |
| Training data | S13: Roboflow `ppe-detection-ozhfb` v14 (workspace `sdp-lfigk`), mirrored in `vyasdeepti/PPE-Object-Detection-using-YOLO11` at commit `98085c8a` - 6 classes: Gloves, Hard_hat, Mask, Person, Safety_boots, Vest | CC BY 4.0 (dataset's own `README.dataset.txt` and `data.yaml`) - attribution required |
| Splits | the export's own: 1,101 train / 305 valid / 213 **test** images. The test split is used only for the final numbers below. | - |
| Output | `backend/ml/weights/ppe.pt` (gitignored; rebuild with the script) | AGPL-3.0 per Ultralytics (trained with its code) |

Attribution for the data: "PPE Detection" dataset by Roboflow Universe user sdp-lfigk
(https://universe.roboflow.com/sdp-lfigk/ppe-detection-ozhfb/dataset/14), CC BY 4.0.

## Ultralytics and AGPL-3.0 - what it means for this project

`ultralytics` (the library the prototype already used, and the base weights) is licensed under
the **GNU Affero General Public License v3**, including its section 13 (remote network
interaction). Ultralytics states on https://www.ultralytics.com/license that "All Ultralytics YOLO
trained models fall under the AGPL-3.0 License by default", and that compliance means publicly
releasing "the complete corresponding source code for the entire derivative work, including the
larger application ... and, where applicable, model weights" - or buying an Enterprise License,
which it names for "SaaS platforms, APIs, or cloud systems that use YOLO behind the scenes".

For CoalShield that means:

- As a hackathon prototype whose source is shared, running YOLO in `ai-service/` is compatible
  with AGPL-3.0 **if** the project's source (including the ai-service, its scripts and the trained
  weights) is published under AGPL-3.0-compatible terms whenever the service is offered to users
  over a network.
- A closed-source or commercial deployment (for example a government or company rollout that is
  not open-sourced) would need an **Ultralytics Enterprise License**, or the detector would have to
  be replaced by a permissively licensed one. The ai-service boundary (HTTP, one endpoint) keeps
  such a swap contained to `ai-service/`.
- This is a licensing decision for the project owner, not a legal opinion. **Owner decision needed
  before any non-open deployment** (tracked in `PROGRESS.md`).

## Rebuilding the weights

```bat
backend\.venv\Scripts\python.exe scripts\build_ppe_model.py
```

About 20 minutes on the reference CPU (19.4 min on 2026-09-27; no GPU). It writes
`backend/ml/weights/ppe.pt` and the results section below. `run_all.bat` warns if the weights are missing; ai-service then falls back
to the test fixture (sidecar files next to the sample images), which the API tests also use
(`PPE_DETECTOR=fixture`).

Why these settings: one epoch at 640 px took 5.1 minutes on this CPU, and a run over 30 minutes
needs the owner's go-ahead. The run therefore uses 512 px, freezes the first 10 layers (the
backbone, which COCO pretraining already covers) and a one-epoch warm-up: about 2.5 minutes per
epoch, 10 epochs. A longer run (about 25 epochs, roughly 2 hours) would likely score higher.

## Limits - read before a demo

- **In-distribution numbers.** The test split comes from the same Roboflow project as the training
  data (construction-style photos). The numbers below say how well the model does on *that kind*
  of image. Nothing here is coal-mine footage (lighting, dust, lamps, underground scenes), so
  performance at a mine is **not measured**.
- **Mask** is weak (mAP50 0.57); masks are not in the default `REQUIRED_PPE` (helmet, vest).
- **Image size.** Evaluated at 512 px (the training size). At 640 px the test split scores the same
  (mAP50 0.848 vs 0.850), so the evaluated 512 px setting is kept rather than tuned on demo photos.
- **The repository's sample photos** (`backend/data/samples/images/`, not part of S13), at the
  default settings (512 px, confidence 0.45), 2026-09-27:

  | Image | What it shows | Model output | Outcome |
  |---|---|---|---|
  | `with_ppe.jpg` | two workers with hard hats and vests | 2 vests, 1 person, no hard hat | false `no_helmet` |
  | `demolition_site_workers.jpg` | same photograph | same | false `no_helmet` |
  | `metro_shaft_workers.jpg` | group in a shaft, hard hats | 1 hard hat, no person | "clean frame" (people missed) |
  | `derailment_inspector.jpg` | inspector with hard hat and vest | hard hat, 2 vests, person | clean frame - correct |
  | `ppe_sample.jpg` | synthetic drawing (fixture only) | 2 hard hats | not meaningful |

  So on photos unlike the training set the small model misses people and hard hats. A longer run
  (about 25 epochs at 640 px with the backbone unfrozen, estimated 1.5-2 hours on this CPU) is the
  obvious next step; it exceeds the 30-minute limit, so it needs the owner's go-ahead
  (`--epochs 25 --imgsz 640 --freeze 0`). Until then the scripted demo is most reliable with the
  fixture backend (`PPE_DETECTOR=fixture`, the numbers in `docs/demo-script.md`), and the real
  model is shown as what it is.

## How the detections are used

Only `Hard_hat` (→ helmet), `Vest`, `Person`, and optionally `Mask`, `Gloves` and `Safety_boots`
matter to the rules. The model has no "no helmet" class; a violation is inferred when a detected
person has no helmet or vest box inside it (`ppe_rules.py`, containment threshold 0.5). So person
and hard-hat recall drive how many violations are caught, and hard-hat and vest precision drive
false alarms.

<!-- BEGIN GENERATED: scripts/build_ppe_model.py -->
## Results on the held-out test split

Generated 2026-09-27 05:49 UTC by `scripts/build_ppe_model.py`. Test split: 213 images of S13, never used
for training or for choosing the checkpoint (the checkpoint with the best validation mAP is kept).

| Class | Precision | Recall | mAP50 | mAP50-95 |
|---|---|---|---|---|
| Gloves | 0.746 | 0.745 | 0.840 | 0.442 |
| Hard_hat | 0.826 | 0.909 | 0.928 | 0.687 |
| Mask | 0.579 | 0.768 | 0.573 | 0.324 |
| Person | 0.863 | 0.920 | 0.959 | 0.706 |
| Safety_boots | 0.787 | 0.832 | 0.850 | 0.502 |
| Vest | 0.863 | 0.911 | 0.950 | 0.703 |
| **all** | **0.777** | **0.848** | **0.850** | **0.560** |

Training: base `yolo11n.pt`, 10 epochs, image size 512, first 10 layers frozen, CPU, seed 2026,
19.4 min on Intel64 Family 6 Model 183 Stepping 1, GenuineIntel (torch 2.14.0+cpu,
ultralytics 8.4.161).
<!-- END GENERATED -->
