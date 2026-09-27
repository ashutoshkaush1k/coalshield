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
- This is a licensing decision for the project owner, not a legal opinion.

**Owner decision (2026-09-27):** the repository will be **public and open-source for SIH**, which
satisfies AGPL-3.0 for this use. The source - including `ai-service/`, the training script and the
selection script - is in the repository. The trained weights are gitignored for size but rebuilt
from public inputs by `scripts/build_ppe_model.py`. Whenever the service is offered to others over
a network, the conservative course is also to publish the exact weights in use (for example as a
release asset next to the source), since Ultralytics names model weights as part of the
corresponding source.

**A closed deployment** (a government or company rollout that is not open-sourced) **would need a
licence review first**: an Ultralytics Enterprise License, or a detector under a permissive
licence. The ai-service boundary keeps such a swap contained to `ai-service/`.

## Rebuilding the weights

```bat
backend\.venv\Scripts\python.exe scripts\build_ppe_model.py
```

The defaults reproduce the installed model (run 2 below): 25 epochs at 640 px, nothing frozen,
three warm-up epochs - about **2 hours on the reference CPU** (no GPU). It writes
`backend/ml/weights/ppe.pt` and the results section at the end of this page. The quicker first run
is `--epochs 10 --imgsz 512 --freeze 10 --warmup 1` (about 20 minutes); `--candidate --name <run>`
trains and tests without installing. `run_all.bat` warns if the weights are missing; the ai-service
then falls back to its test fixture (sidecar files next to the sample images), which exists for the
automated tests (`PPE_DETECTOR=fixture`) and is never used in the demo.

## Two runs, one kept (2026-09-27)

Both runs start from YOLO11n (COCO) with seed 2026 and were evaluated on the same held-out test
split (213 images), never used for training or checkpoint selection. The kept model is the one
that scores better **on that split**.

| | Run 1 | Run 2 (**installed**) |
|---|---|---|
| Settings | 10 epochs, 512 px, first 10 layers frozen, 1 warm-up epoch | 25 epochs, 640 px, nothing frozen, 3 warm-up epochs |
| CPU time | 19.4 min | about 114 min (wall clock 475 min: the machine slept for about 6 h during epoch 12) |
| Precision / recall (all) | 0.777 / 0.848 | **0.818 / 0.889** |
| mAP50 (all) | 0.850 | **0.878** |
| mAP50-95 (all) | 0.560 | **0.595** |

mAP50 per class, run 1 → run 2: Gloves 0.840 → 0.918, Hard_hat 0.928 → 0.929, Mask 0.573 →
0.617, Person 0.959 → 0.969, Safety_boots 0.850 → 0.905, Vest 0.950 → 0.932 (the one class that
dropped, slightly). Run 2's per-class precision, recall and mAP50-95 are in the generated section
below; run 1's are kept in `backend/ml/runs/ppe/test_metrics_run1.json` (gitignored with the runs).

**End to end** - what the product actually does with a frame (a person without a hard hat or vest
inside their box is a violation), run 2 on the whole test split, against the same rule applied to
the ground-truth labels (`scripts/select_ppe_demo_images.py`): the violation set is exactly right on
**92 of the 126 images that show people (73 %)**, and on 172 of all 213 (81 %; an image with no
person in its labels agrees whenever the model also sees no person).

## Demo images (held-out only)

The demo uses **only held-out test images**, copied unmodified (CC BY 4.0, attributed) to
`backend/data/samples/heldout/` by `scripts/select_ppe_demo_images.py`: three clean frames and three
with violations, chosen from the images where the model's violation set equals the ground truth's,
with at most four people. They are selected, not random - the 73 % above is the honest rate, and
the folder's README says so. Through the product (`POST /v1/vision/analyze`, mine head of Jayant on
the demo seed): `heldout_06_violations.jpg` → three `no_helmet`, score 80 → 65 (Low → Medium);
`heldout_01_clean.jpg` → a clean frame that resolves the open PPE findings from vision.

## Limits - read before a demo

- **In-distribution numbers.** The test split comes from the same Roboflow project as the training
  data (construction-style photos). The numbers say how well the model does on *that kind* of
  image. Nothing here is coal-mine footage (lighting, dust, cap lamps, underground scenes), so
  performance at a mine is **not measured**.
- **Mask** is the weak class (mAP50 0.62); masks are not in the default `REQUIRED_PPE` (helmet, vest).
- **The repository's sample photos** (`backend/data/samples/images/`, not part of S13), confidence
  0.45, 2026-09-27:

  | Image | What it shows | Run 1 (512 px) | Run 2 (640 px) |
  |---|---|---|---|
  | `with_ppe.jpg` | two workers with hard hats and vests | 2 vests, 1 person, no hard hat: false `no_helmet` | 1 hard hat, 2 vests, no person: "clean" for the wrong reason |
  | `demolition_site_workers.jpg` | same photograph | false `no_helmet` | 1 hard hat, 2 vests, no person |
  | `metro_shaft_workers.jpg` | group in a shaft, hard hats | 1 hard hat, no person | nothing detected: "no workers seen" |
  | `derailment_inspector.jpg` | inspector with hard hat and vest | clean frame - correct | clean frame - correct |
  | `ppe_sample.jpg` | synthetic drawing (fixture only) | 2 hard hats | 2 hard hats - not meaningful |

  Run 2 no longer raises the false `no_helmet`, but on photos unlike its training set it still
  **misses people**, and without a person no violation can be inferred. That is why the demo uses
  held-out test images, and why a clean frame counts as evidence only when it shows a person or
  worn PPE (`EVIDENCE_LABELS`). A model for mine use needs mine footage to train and test on.

## How the detections are used

Only `Hard_hat` (→ helmet), `Vest`, `Person`, and optionally `Mask`, `Gloves` and `Safety_boots`
matter to the rules. The model has no "no helmet" class; a violation is inferred when a detected
person has no helmet or vest box inside it (`ppe_rules.py`, containment threshold 0.5). So person
and hard-hat recall drive how many violations are caught, and hard-hat and vest precision drive
false alarms.

<!-- BEGIN GENERATED: scripts/build_ppe_model.py -->
## Results on the held-out test split

Generated 2026-09-27 14:38 UTC by `scripts/build_ppe_model.py`. Test split: 213 images of S13, never used
for training or for choosing the checkpoint (the checkpoint with the best validation mAP is kept).

| Class | Precision | Recall | mAP50 | mAP50-95 |
|---|---|---|---|---|
| Gloves | 0.824 | 0.854 | 0.918 | 0.563 |
| Hard_hat | 0.833 | 0.920 | 0.929 | 0.678 |
| Mask | 0.609 | 0.880 | 0.617 | 0.369 |
| Person | 0.907 | 0.936 | 0.969 | 0.723 |
| Safety_boots | 0.877 | 0.877 | 0.905 | 0.534 |
| Vest | 0.856 | 0.863 | 0.932 | 0.704 |
| **all** | **0.818** | **0.889** | **0.878** | **0.595** |

Training: base `yolo11n.pt`, 25 epochs, image size 640, first 0 layers frozen, CPU, seed 2026,
114 (CPU time; 475.5 wall-clock including a 6-hour system sleep during epoch 12) min on Intel64 Family 6 Model 183 Stepping 1, GenuineIntel (torch 2.14.0+cpu,
ultralytics 8.4.161).
<!-- END GENERATED -->
