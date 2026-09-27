"""Rebuild the PPE detection weights used by ai-service (backend/ml/weights/ppe.pt).

No openly licensed pretrained PPE model with a traceable training-data licence was found
(docs/AI_EVALUATION.md, "Model choice"), so the model is fine-tuned here:

  base     Ultralytics YOLO11n, COCO-pretrained (AGPL-3.0; downloaded by Ultralytics from its
           GitHub release assets on first use, no login)
  data     S13 - Roboflow "ppe-detection-ozhfb" v14, CC BY 4.0 (data/raw/ppe/dataset, fetched by
           `data\\run_data.bat download`): 1,101 train / 305 valid / 213 test images, 6 classes
  device   CPU (the reference machine has no GPU)

The test split is never used for training or model selection; it is only evaluated at the end,
and per-class precision, recall, mAP50 and mAP50-95 are written to docs/AI_EVALUATION.md.

Usage (from the repository root, with backend\\.venv, which has ultralytics and torch):
    backend\\.venv\\Scripts\\python.exe scripts\\build_ppe_model.py --probe       # time one epoch
    backend\\.venv\\Scripts\\python.exe scripts\\build_ppe_model.py --epochs 12   # train, evaluate, install
    backend\\.venv\\Scripts\\python.exe scripts\\build_ppe_model.py --evaluate-only
"""

from __future__ import annotations

import argparse
import json
import os
import platform
import shutil
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATASET = ROOT / "data" / "raw" / "ppe" / "dataset"
WORK = ROOT / "backend" / "ml" / "runs"                 # gitignored with the weights
WEIGHTS = ROOT / "backend" / "ml" / "weights" / "ppe.pt"
REPORT = ROOT / "docs" / "AI_EVALUATION.md"
BASE_MODEL = "yolo11n.pt"
SEED = 2026


def dataset_yaml() -> Path:
    """data.yaml with absolute paths (the export's relative ones depend on the working directory)."""
    if not (DATASET / "train" / "images").is_dir():
        sys.exit(f"S13 dataset missing at {DATASET} - run: data\\run_data.bat download")
    WORK.mkdir(parents=True, exist_ok=True)
    path = WORK / "s13.yaml"
    path.write_text(
        f"path: {DATASET.as_posix()}\n"
        "train: train/images\nval: valid/images\ntest: test/images\n"
        "nc: 6\nnames: ['Gloves', 'Hard_hat', 'Mask', 'Person', 'Safety_boots', 'Vest']\n",
        encoding="utf-8",
    )
    return path


def train(epochs: int, imgsz: int, batch: int, name: str, freeze: int = 0, warmup: float = 3.0) -> Path:
    from ultralytics import YOLO

    model = YOLO(BASE_MODEL)
    started = time.time()
    model.train(
        data=str(dataset_yaml()), epochs=epochs, imgsz=imgsz, batch=batch, device="cpu",
        workers=4, seed=SEED, deterministic=True, project=str(WORK), name=name, exist_ok=True,
        patience=0, plots=False, verbose=False, freeze=freeze or None, warmup_epochs=warmup,
    )
    minutes = (time.time() - started) / 60
    print(f"training took {minutes:.1f} min for {epochs} epoch(s)")
    (WORK / name / "train_minutes.txt").write_text(f"{minutes:.1f}\n", encoding="utf-8")
    return WORK / name / "weights" / "best.pt"


def evaluate(weights: Path, imgsz: int) -> dict:
    """Metrics on the held-out TEST split (never seen in training or model selection)."""
    from ultralytics import YOLO

    model = YOLO(str(weights))
    metrics = model.val(data=str(dataset_yaml()), split="test", imgsz=imgsz, batch=16, device="cpu",
                        plots=False, verbose=False, project=str(WORK), name="eval", exist_ok=True)
    names = model.names
    box = metrics.box
    rows = []
    for i, cls in enumerate(box.ap_class_index):
        rows.append({
            "class": names[int(cls)],
            "precision": float(box.p[i]), "recall": float(box.r[i]),
            "map50": float(box.ap50[i]), "map50_95": float(box.ap[i]),
        })
    return {
        "per_class": rows,
        "all": {"precision": float(box.mp), "recall": float(box.mr), "map50": float(box.map50),
                "map50_95": float(box.map)},
        "images": int(metrics.seen) if hasattr(metrics, "seen") else None,
    }


def write_report(result: dict, epochs: int, imgsz: int, minutes: str, freeze: int = 0) -> None:
    import torch
    import ultralytics

    lines = [f"| {r['class']} | {r['precision']:.3f} | {r['recall']:.3f} | {r['map50']:.3f} | {r['map50_95']:.3f} |"
             for r in result["per_class"]]
    a = result["all"]
    table = "\n".join(lines)
    generated = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    section = f"""<!-- BEGIN GENERATED: scripts/build_ppe_model.py -->
## Results on the held-out test split

Generated {generated} by `scripts/build_ppe_model.py`. Test split: 213 images of S13, never used
for training or for choosing the checkpoint (the checkpoint with the best validation mAP is kept).

| Class | Precision | Recall | mAP50 | mAP50-95 |
|---|---|---|---|---|
{table}
| **all** | **{a['precision']:.3f}** | **{a['recall']:.3f}** | **{a['map50']:.3f}** | **{a['map50_95']:.3f}** |

Training: base `{BASE_MODEL}`, {epochs} epochs, image size {imgsz}, first {freeze} layers frozen, CPU, seed {SEED},
{minutes} min on {platform.processor() or platform.machine()} (torch {torch.__version__},
ultralytics {ultralytics.__version__}).
<!-- END GENERATED -->"""
    text = REPORT.read_text(encoding="utf-8") if REPORT.exists() else ""
    start, end = "<!-- BEGIN GENERATED: scripts/build_ppe_model.py -->", "<!-- END GENERATED -->"
    if start in text and end in text:
        text = text[: text.index(start)] + section + text[text.index(end) + len(end):]
    else:
        text = text.rstrip() + "\n\n" + section + "\n"
    REPORT.write_text(text, encoding="utf-8")
    (WORK / "test_metrics.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
    print(f"wrote {REPORT}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--epochs", type=int, default=10)
    parser.add_argument("--imgsz", type=int, default=512)
    parser.add_argument("--batch", type=int, default=16)
    parser.add_argument("--freeze", type=int, default=10, help="freeze the first N layers (backbone)")
    parser.add_argument("--warmup", type=float, default=1.0, help="warm-up epochs")
    parser.add_argument("--probe", action="store_true", help="train one epoch only, to time it")
    parser.add_argument("--evaluate-only", action="store_true", help="evaluate the installed weights")
    args = parser.parse_args()
    WORK.mkdir(parents=True, exist_ok=True)
    os.chdir(WORK)   # Ultralytics downloads the base weights into the working directory

    if args.evaluate_only:
        if not WEIGHTS.exists():
            sys.exit(f"no weights at {WEIGHTS}")
        result = evaluate(WEIGHTS, args.imgsz)
        write_report(result, args.epochs, args.imgsz, "n/a")
        return 0
    if args.probe:
        train(1, args.imgsz, args.batch, "probe", args.freeze, args.warmup)
        return 0

    best = train(args.epochs, args.imgsz, args.batch, "ppe", args.freeze, args.warmup)
    WEIGHTS.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(best, WEIGHTS)
    print(f"installed {WEIGHTS}")
    minutes = (WORK / "ppe" / "train_minutes.txt").read_text(encoding="utf-8").strip()
    write_report(evaluate(WEIGHTS, args.imgsz), args.epochs, args.imgsz, minutes, args.freeze)
    return 0


if __name__ == "__main__":
    sys.exit(main())
