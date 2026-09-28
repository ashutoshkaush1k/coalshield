"""The predictive model's features - defined once, computed from MSHA mine-years for training and
from our mines' last 90 days for prediction (the API sends those; api/services/RiskModelService.php
builds them the same way).

Every feature is a rate or a size, so a US mine-year and an Indian mine's annualised quarter are on
the same footing. The violation categories are MSHA's citations mapped to the Indian categories by
data/reference/crosswalk_msha_india.csv (the v_* columns of msha_rates.csv).
"""

from __future__ import annotations

import math

CATEGORIES = ["roof_strata", "ventilation_gas", "electrical", "machinery", "transport_haulage", "fire", "PPE"]

FEATURES = [
    "log_workers",                 # ln(1 + workers)
    "underground",                 # 1 for underground or mixed workings
    "violations_per_100",          # all violations per 100 workers per year
    *[f"{c.lower()}_per_100" for c in CATEGORIES],
    "accidents_per_100",           # reportable accidents per 100 workers per year
    "lost_time_per_100",           # lost-time and fatal accidents per 100 workers per year
    "had_lost_time",               # 1 if any lost-time or fatal accident this year
]

# Violation rates depend on how hard inspectors look: US inspectors write ~32 citations per 100
# workers a year, our mines' records ~6. These are transferred by rank (model.transfer); accident
# rates are comparable across countries and used as they are.
VIOLATION_FEATURES = ["violations_per_100", *[f"{c.lower()}_per_100" for c in CATEGORIES]]

# Plain-language names for the explanation (the UI translates them by feature key).
LABELS = {
    "log_workers": "size of the workforce",
    "underground": "underground workings",
    "violations_per_100": "violations per 100 workers",
    "accidents_per_100": "accidents per 100 workers",
    "lost_time_per_100": "lost-time accidents per 100 workers",
    "had_lost_time": "a lost-time accident this year",
    **{f"{c.lower()}_per_100": f"{c.replace('_', ' ').lower()} violations per 100 workers" for c in CATEGORIES},
}


def row_features(workers: float, underground: bool, violations: float, by_category: dict, accidents: float, lost_time: float) -> dict:
    """One mine-year's features. Counts are per year (annualise a shorter window before calling)."""
    w = max(float(workers), 1.0)
    per100 = lambda n: 100.0 * float(n) / w  # noqa: E731
    out = {
        "log_workers": math.log1p(w),
        "underground": 1.0 if underground else 0.0,
        "violations_per_100": per100(violations),
        "accidents_per_100": per100(accidents),
        "lost_time_per_100": per100(lost_time),
        "had_lost_time": 1.0 if lost_time > 0 else 0.0,
    }
    for c in CATEGORIES:
        out[f"{c.lower()}_per_100"] = per100(by_category.get(c, 0))
    return {k: out[k] for k in FEATURES}
