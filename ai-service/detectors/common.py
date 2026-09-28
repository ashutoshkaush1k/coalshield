"""Shared pieces of the detectors: tail probabilities, formatting, rounding.

Every function here has a line-for-line twin in api/services/detectors/Stats.php, so the PHP
fallback returns the same flags (tests/test_detectors.py and api/tests/unit/DetectorParityTest.php
check both against the same fixtures).
"""

from __future__ import annotations

import math
from datetime import datetime, timezone


def log_factorial(n: int) -> float:
    total = 0.0
    for j in range(2, n + 1):
        total += math.log(j)
    return total


def poisson_sf(k: int, lam: float) -> float:
    """P(X >= k) for X ~ Poisson(lam), summed upward from k in log space (exact for tiny tails)."""
    if k <= 0:
        return 1.0
    if lam <= 0:
        return 0.0
    total = 0.0
    lf = log_factorial(k)
    i = k
    while i < k + 1000:
        term = math.exp(-lam + i * math.log(lam) - lf)
        total += term
        if term < 1e-300 or (i > lam and term < total * 1e-17):
            break
        i += 1
        lf += math.log(i)
    return min(1.0, total)


def binom_sf(k: int, n: int, p: float) -> float:
    """P(X >= k) for X ~ Binomial(n, p)."""
    if k <= 0:
        return 1.0
    if k > n:
        return 0.0
    if p <= 0:
        return 0.0
    if p >= 1:
        return 1.0
    lfn = log_factorial(n)
    total = 0.0
    for i in range(k, n + 1):
        total += math.exp(lfn - log_factorial(i) - log_factorial(n - i) + i * math.log(p) + (n - i) * math.log(1 - p))
    return min(1.0, total)


def rnd(x: float, digits: int = 0) -> float:
    """Round half away from zero (PHP's round(); Python's round() rounds half to even)."""
    f = 10 ** digits
    return math.floor(abs(x) * f + 0.5) / f * (1 if x >= 0 else -1)


def sig(x: float) -> float:
    """3 significant digits - enough to read a p-value, and the same in PHP."""
    if x == 0 or not math.isfinite(x):
        return 0.0 if x == 0 else x
    return float(f"{x:.3g}")


def iso(ts: float) -> str:
    return datetime.fromtimestamp(int(ts), tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def day(ts: float) -> str:
    return datetime.fromtimestamp(int(ts), tz=timezone.utc).strftime("%Y-%m-%d")


def finite(x: float, digits: int):
    """round(x, digits), or None where the value is not finite (JSON has no infinity)."""
    return rnd(x, digits) if math.isfinite(x) else None


def flag(detector: str, mine_id: int, subject: str, start: str, end: str, score: float, reasons: list, entities: dict) -> dict:
    return {"detector": detector, "mine_id": int(mine_id), "subject": subject, "from": start, "to": end,
            "score": score, "reasons": reasons, "entities": entities}


def ordered(flags: list[dict]) -> list[dict]:
    return sorted(flags, key=lambda f: (f["mine_id"], f["subject"]))
