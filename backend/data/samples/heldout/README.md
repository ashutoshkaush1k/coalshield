# Held-out test images for the PPE demo

**Every image here is from the TEST split of S13** (Roboflow "ppe-detection-ozhfb" v14,
CC BY 4.0 - see data/SOURCES.md, source S13): never used to train the model or to choose its checkpoint.
Chosen by `scripts/select_ppe_demo_images.py`, which runs the installed model over all
213 test images and applies the ai-service's violation rule (a person without a hard hat or a vest
inside their box) to the model's detections and to the ground-truth labels alike.

Across the whole test split the model's violation set equals the ground truth's on
**172 of 213 images** (81 %) - and on
**92 of the 126 images that show people**
(73 %; on an image with no
person in its labels, agreement only means the model also found no person). Violations and people count both agree on
172 images. The images below are
**selected** from the agreeing ones - clean frames and frames with violations, at most
4 people - so the demo shows both outcomes. They are not a random sample; the rate
above is the honest measure.

| Demo file | Original (S13 test) | People | Violations (ground truth) | Violations (model) |
|---|---|---|---|---|
| `heldout_01_clean.jpg` | `00000383_jpg.rf.f19d5665fd1ea7a703d699ee` | 2 | none | none |
| `heldout_02_clean.jpg` | `Aitin0586_jpg.rf.e285b7b5848abec78c0f0f9` | 1 | none | none |
| `heldout_03_clean.jpg` | `Aitin2742_jpg.rf.f99afc520994a85e8a02f05` | 1 | none | none |
| `heldout_04_violations.jpg` | `AightOne0585_jpg.rf.930afe066dde3ff8169b` | 2 | no_helmet, no_helmet, no_safety_vest | no_helmet, no_helmet, no_safety_vest |
| `heldout_05_violations.jpg` | `AightOne0869_jpg.rf.0df88b0b49e4373b7ab5` | 2 | no_helmet, no_helmet, no_safety_vest | no_helmet, no_helmet, no_safety_vest |
| `heldout_06_violations.jpg` | `00020_jpg.rf.abe4d0d5d5829754ec268692af2` | 3 | no_helmet, no_helmet, no_helmet | no_helmet, no_helmet, no_helmet |

Attribution: the images are from the PPE detection dataset by Roboflow Universe user "sdp-lfigk"
(project ppe-detection-ozhfb, version 14), as mirrored in the GitHub repository
vyasdeepti/PPE-Object-Detection-using-YOLO11 (commit 98085c8), licensed CC BY 4.0. Unmodified
copies, renamed; the original file names are in the table and in selection.json.
