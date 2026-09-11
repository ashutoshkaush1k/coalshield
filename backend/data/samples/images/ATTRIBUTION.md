# Sample image attribution

Test images for the CV module. Downloaded from Wikimedia Commons; each is reproduced under the
licence noted below. Replace these with real footage from a partner mine when available.

| File | Source | Licence |
|---|---|---|
| `metro_shaft_workers.jpg` | ["A group of men in a Metro shaft"](https://commons.wikimedia.org/wiki/File:A_group_of_men_in_a_Metro_shaft_(8691421231).jpg), Wikimedia Commons | No known copyright restrictions |
| `with_ppe.jpg` | Same photograph as `demolition_site_workers.jpg`, kept under a name that says what it is for: the compliant-crew fixture used to demonstrate score recovery. | CC BY 2.0 |
| `demolition_site_workers.jpg` | ["Grand Canyon NP: Demolition of Maswik South Lodging Complex"](https://commons.wikimedia.org/wiki/File:Grand_Canyon_NP-_Demolition_of_Maswik_South_Lodging_Complex_1165_-_47990656061.jpg), Wikimedia Commons | CC BY 2.0 |
| `derailment_inspector.jpg` | ["Bruce Landsberg at 2021 Montana derailment"](https://commons.wikimedia.org/wiki/File:Bruce_Landsberg_at_2021_Montana_derailment.jpg), Wikimedia Commons (NTSB) | Public domain |

`with_ppe.jpg` pairs with a sidecar holding the detections the real model actually produces for
it (two workers, two hardhats, two vests, zero violations), so the offline fixture backend and
the real model agree. Its sidecar deliberately does **not** contain zero detections: an empty
frame also has zero violations, and must not be able to clear a violation history.

`ppe_sample.jpg` is synthetic — drawn programmatically, not a photograph. It exists only as a
fixture for `FixtureDetector` (paired with `ppe_sample.jpg.detections.json`) so the pipeline can be
tested with no weights installed. A real model detects almost nothing in it, which is expected.
