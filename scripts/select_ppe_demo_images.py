"""Pick the demo images for PPE vision from the HELD-OUT test split of S13 (docs/AI_EVALUATION.md).

The demo must show the real model on images it never saw in training or model selection. This
script runs the installed model (backend/ml/weights/ppe.pt) over every image of the test split
and applies the ai-service's own violation rule (backend/app/services/vision/ppe_rules.py:
a person without a contained hard hat or vest is a violation) to

  * the model's detections, and
  * the ground-truth labels of the same image (as detections with confidence 1),

then reports how often the two agree across the whole split - the end-to-end number, beyond mAP -
and copies a few images where they agree to backend/data/samples/heldout/: some clean frames and
some with violations, so the demo shows both outcomes. The selection is deliberate and says so in
the folder's README; the unselected rate is the honest measure.

Usage (repository root):
    backend\\.venv\\Scripts\\python.exe scripts\\select_ppe_demo_images.py [--clean 3] [--violations 3]
"""

from __future__ import annotations

import argparse
import json
import shutil
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "backend"))

from app.services.vision.detector import Detection, YoloDetector  # noqa: E402
from app.services.vision.ppe_rules import PpePolicy, normalise_label, violations_from_detections  # noqa: E402

TEST = ROOT / "data" / "raw" / "ppe" / "dataset" / "test"
WEIGHTS = ROOT / "backend" / "ml" / "weights" / "ppe.pt"
OUT = ROOT / "backend" / "data" / "samples" / "heldout"
NAMES = ["Gloves", "Hard_hat", "Mask", "Person", "Safety_boots", "Vest"]   # S13 data.yaml order


def ground_truth(image: Path) -> list[Detection]:
    from PIL import Image

    width, height = Image.open(image).size
    label = TEST / "labels" / (image.stem + ".txt")
    out = []
    for line in label.read_text(encoding="utf-8").splitlines() if label.exists() else []:
        parts = line.split()
        if len(parts) != 5:
            continue   # segmentation rows would have more; S13 test labels are boxes
        cls, cx, cy, w, h = int(parts[0]), *(float(p) for p in parts[1:])
        raw = NAMES[cls]
        out.append(Detection(label=normalise_label(raw), raw_label=raw, confidence=1.0,
                             bbox=((cx - w / 2) * width, (cy - h / 2) * height, (cx + w / 2) * width, (cy + h / 2) * height)))
    return out


def summary(detections, policy) -> dict:
    kept = [d for d in detections if d.confidence >= policy.min_confidence]
    violations = violations_from_detections(detections, policy=policy, infer=True)
    return {"people": sum(1 for d in kept if d.label == "person"),
            "violations": sorted(c.violation_type for c in violations)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--clean", type=int, default=3)
    parser.add_argument("--violations", type=int, default=3)
    parser.add_argument("--max-people", type=int, default=4, help="prefer readable frames")
    args = parser.parse_args()
    if not WEIGHTS.exists():
        sys.exit(f"no weights at {WEIGHTS} - run scripts/build_ppe_model.py")

    policy = PpePolicy.from_settings()
    model = YoloDetector(WEIGHTS)
    rows = []
    for image in sorted((TEST / "images").glob("*.jpg")):
        truth, predicted = summary(ground_truth(image), policy), summary(model.detect(image), policy)
        rows.append({"file": image.name, "truth": truth, "predicted": predicted,
                     "violations_agree": truth["violations"] == predicted["violations"],
                     "agree": truth == predicted})

    n = len(rows)
    stats = {
        "images": n,
        "violation_sets_agree": sum(r["violations_agree"] for r in rows),
        "violations_and_people_agree": sum(r["agree"] for r in rows),
        "images_with_people": sum(1 for r in rows if r["truth"]["people"]),
        "violation_sets_agree_with_people": sum(r["violations_agree"] for r in rows if r["truth"]["people"]),
        "truth_clean_with_people": sum(1 for r in rows if not r["truth"]["violations"] and r["truth"]["people"]),
        "truth_with_violations": sum(1 for r in rows if r["truth"]["violations"]),
        "policy": {"required_ppe": list(policy.required_ppe), "min_confidence": policy.min_confidence},
    }
    readable = [r for r in rows if r["agree"] and 1 <= r["truth"]["people"] <= args.max_people]
    clean = [r for r in readable if not r["truth"]["violations"]][: args.clean]
    # Violation frames: cover both types before repeating one.
    with_violations, seen = [], Counter()
    for r in sorted((r for r in readable if r["truth"]["violations"]), key=lambda r: (len(set(r["truth"]["violations"])) < 2, r["file"])):
        key = tuple(sorted(set(r["truth"]["violations"])))
        if len(with_violations) < args.violations and seen[key] < 2:
            with_violations.append(r)
            seen[key] += 1

    OUT.mkdir(parents=True, exist_ok=True)
    for old in OUT.glob("heldout_*.jpg"):
        old.unlink()
    chosen = []
    for i, r in enumerate(clean + with_violations, 1):
        kind = "clean" if not r["truth"]["violations"] else "violations"
        name = f"heldout_{i:02d}_{kind}.jpg"
        shutil.copy2(TEST / "images" / r["file"], OUT / name)
        chosen.append({"demo_file": name, **r})

    (OUT / "selection.json").write_text(json.dumps({"stats": stats, "chosen": chosen}, indent=2), encoding="utf-8")
    table = "\n".join(
        f"| `{c['demo_file']}` | `{c['file'][:40]}` | {c['truth']['people']} | "
        f"{', '.join(c['truth']['violations']) or 'none'} | {', '.join(c['predicted']['violations']) or 'none'} |"
        for c in chosen)
    (OUT / "README.md").write_text(f"""# Held-out test images for the PPE demo

**Every image here is from the TEST split of S13** (Roboflow "ppe-detection-ozhfb" v14,
CC BY 4.0 - see data/SOURCES.md, source S13): never used to train the model or to choose its checkpoint.
Chosen by `scripts/select_ppe_demo_images.py`, which runs the installed model over all
{n} test images and applies the ai-service's violation rule (a person without a hard hat or a vest
inside their box) to the model's detections and to the ground-truth labels alike.

Across the whole test split the model's violation set equals the ground truth's on
**{stats['violation_sets_agree']} of {n} images** ({100 * stats['violation_sets_agree'] / n:.0f} %) - and on
**{stats['violation_sets_agree_with_people']} of the {stats['images_with_people']} images that show people**
({100 * stats['violation_sets_agree_with_people'] / stats['images_with_people']:.0f} %; on an image with no
person in its labels, agreement only means the model also found no person). Violations and people count both agree on
{stats['violations_and_people_agree']} images. The images below are
**selected** from the agreeing ones - clean frames and frames with violations, at most
{args.max_people} people - so the demo shows both outcomes. They are not a random sample; the rate
above is the honest measure.

| Demo file | Original (S13 test) | People | Violations (ground truth) | Violations (model) |
|---|---|---|---|---|
{table}

Attribution: the images are from the PPE detection dataset by Roboflow Universe user "sdp-lfigk"
(project ppe-detection-ozhfb, version 14), as mirrored in the GitHub repository
vyasdeepti/PPE-Object-Detection-using-YOLO11 (commit 98085c8), licensed CC BY 4.0. Unmodified
copies, renamed; the original file names are in the table and in selection.json.
""", encoding="utf-8")
    print(json.dumps(stats, indent=2))
    for c in chosen:
        print(f"  {c['demo_file']}: {c['file']}  truth {c['truth']}  model {c['predicted']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
