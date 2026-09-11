"""Generates the 150-200 realistic gas/dust/temperature readings (PRD 4.2) into backend/data/seed/.

Also writes mines.json, users.json and violations.json so the seeded database produces a scored,
demo-ready multi-mine dashboard rather than five identical empty mines.

Determinism matters here: the RNG is seeded with a fixed value, so every team member and every
re-run gets byte-identical files. A demo that reshuffles itself the morning of judging is a demo
that cannot be rehearsed.

Usage:
    python scripts/generate_sensor_data.py [--seed N]
"""

from __future__ import annotations

import argparse
import csv
import json
import random
import sys
from datetime import UTC, datetime, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BACKEND = ROOT / "backend"
SEED_DIR = BACKEND / "data" / "seed"
sys.path.insert(0, str(BACKEND))

from app.services.compliance.scoring import compute_compliance_score  # noqa: E402
from app.services.compliance.weights import ScoringWeights  # noqa: E402
from app.services.iot.thresholds import SensorType  # noqa: E402

DEFAULT_SEED = 26024  # the problem statement number, so the value is memorable

# 12 readings per sensor keeps the simulator's full pass at 12 ticks, whatever the
# mine count. The PRD's 150-200 figure was written for the original five mines; the
# per-mine density is unchanged, there are simply more mines now.
READINGS_PER_SENSOR = 12
INTERVAL_HOURS = 6  # 12 readings x 6h = a 72-hour window per sensor

# Normal and breaching value ranges per sensor. Breach ranges start just above the configured
# threshold so the data stays plausible - a coal mine reading 400ppm methane is not a compliance
# finding, it is an evacuation.
RANGES = {
    SensorType.GAS: {"normal": (5.0, 45.0), "breach": (51.0, 88.0)},
    SensorType.DUST: {"normal": (1.5, 9.4), "breach": (10.4, 21.0)},
    SensorType.TEMPERATURE: {"normal": (22.0, 44.0), "breach": (45.6, 57.0)},
}

PPE_VIOLATION_TYPES = [
    "no_helmet",
    "no_safety_vest",
    "no_gloves",
    "no_safety_boots",
    "no_dust_mask",
]

# --- The five original mines -------------------------------------------------
#
# Kept by name, code and id so earlier screenshots, the demo script and the seeded
# Mine Head logins all still line up. Their breach and violation counts are tuned to
# spread them across all three risk bands at the default weights (PRD 8.1); the
# synthetic mines around them are generated, not hand-tuned.
NAMED_MINES = [
    {
        "id": 1, "code": "JH-DHN-01", "name": "Jharia Coalfield Block A",
        "district": "Dhanbad", "state": "Jharkhand", "region": "East",
        "operator": "Bharat Coking Coal Ltd", "breaches": 4, "violations": 0,
    },
    {
        "id": 2, "code": "MP-SGR-02", "name": "Singrauli Opencast Mine",
        "district": "Singrauli", "state": "Madhya Pradesh", "region": "Central",
        "operator": "Northern Coalfields Ltd", "breaches": 4, "violations": 1,
    },
    {
        "id": 3, "code": "CG-KRB-03", "name": "Korba Gevra Expansion",
        "district": "Korba", "state": "Chhattisgarh", "region": "Central",
        "operator": "South Eastern Coalfields Ltd", "breaches": 5, "violations": 3,
    },
    {
        "id": 4, "code": "WB-RNG-04", "name": "Raniganj Deep Shaft",
        "district": "Raniganj", "state": "West Bengal", "region": "East",
        "operator": "Eastern Coalfields Ltd", "breaches": 7, "violations": 4,
    },
    {
        "id": 5, "code": "OD-TLC-05", "name": "Talcher Underground Unit 2",
        "district": "Angul", "state": "Odisha", "region": "East",
        "operator": "Mahanadi Coalfields Ltd", "breaches": 8, "violations": 6,
    },
]

# --- Synthetic national spread -----------------------------------------------
#
# A believable national dataset without fabricating 1,200 records by hand. Real
# coal-bearing districts per state, so a viewer who knows the sector does not see
# obviously invented places.
COALFIELD_DISTRICTS = {
    "Jharkhand":      ("JH", "East",  ["Dhanbad", "Bokaro", "Ramgarh", "Chatra", "Latehar", "Godda", "Hazaribagh"]),
    "Odisha":         ("OD", "East",  ["Angul", "Jharsuguda", "Sundargarh", "Sambalpur", "Dhenkanal"]),
    "Chhattisgarh":   ("CG", "Central", ["Korba", "Raigarh", "Surguja", "Koriya", "Bilaspur"]),
    "West Bengal":    ("WB", "East",  ["Raniganj", "Asansol", "Purulia", "Bardhaman"]),
    "Madhya Pradesh": ("MP", "Central", ["Singrauli", "Shahdol", "Umaria", "Anuppur", "Betul"]),
    "Telangana":      ("TS", "South", ["Ramagundam", "Bhadradri", "Mancherial", "Peddapalli"]),
    "Maharashtra":    ("MH", "West",  ["Chandrapur", "Nagpur", "Yavatmal", "Wardha"]),
    "Assam":          ("AS", "North East", ["Tinsukia", "Dibrugarh"]),
    "Uttar Pradesh":  ("UP", "North", ["Sonbhadra"]),
    "Jammu and Kashmir": ("JK", "North", ["Udhampur"]),
}

OPERATORS = [
    "Coal India Ltd", "Bharat Coking Coal Ltd", "Central Coalfields Ltd",
    "Eastern Coalfields Ltd", "Western Coalfields Ltd", "Northern Coalfields Ltd",
    "South Eastern Coalfields Ltd", "Mahanadi Coalfields Ltd",
    "Singareni Collieries Company Ltd", "NLC India Ltd",
]

MINE_FORMS = ["Opencast Project", "Underground Mine", "Colliery", "Expansion Block",
              "Deep Shaft", "Coalfield Block", "Washery Block"]

# How many synthetic mines each state gets, roughly tracking real production weight.
STATE_WEIGHTS = {
    "Jharkhand": 12, "Odisha": 11, "Chhattisgarh": 11, "West Bengal": 8,
    "Madhya Pradesh": 8, "Telangana": 7, "Maharashtra": 6, "Assam": 3,
    "Uttar Pradesh": 2, "Jammu and Kashmir": 1,
}

TOTAL_SYNTHETIC = sum(STATE_WEIGHTS.values())

def build_mines(rng: random.Random) -> list[dict]:
    """The five named mines plus a synthetic national spread around them."""
    mines = [dict(m) for m in NAMED_MINES]
    next_id = len(mines) + 1

    for state, count in STATE_WEIGHTS.items():
        prefix, region, districts = COALFIELD_DISTRICTS[state]
        for n in range(count):
            district = districts[n % len(districts)]
            # Risk profile varies by mine so the national top-5 is a real ranking and
            # not an artefact of every synthetic mine looking the same.
            severity = rng.random()
            if severity < 0.12:          # a few genuinely bad sites
                breaches, violations = rng.randint(8, 14), rng.randint(5, 9)
            elif severity < 0.45:
                breaches, violations = rng.randint(4, 8), rng.randint(1, 4)
            else:
                breaches, violations = rng.randint(0, 4), rng.randint(0, 1)

            mines.append({
                "id": next_id,
                "code": f"{prefix}-{district[:3].upper()}-{next_id:02d}",
                "name": f"{district} {rng.choice(MINE_FORMS)}",
                "district": district,
                "state": state,
                "region": region,
                "operator": rng.choice(OPERATORS),
                "breaches": breaches,
                "violations": violations,
            })
            next_id += 1

    # `location` stays the human-readable label the UI already shows. It is derived from
    # the structured fields rather than typed separately, so the two can never disagree.
    for mine in mines:
        mine["location"] = f"{mine['district']}, {mine['state']}"

    return mines



def _weighted_sample(rng: random.Random, slots: list, weights: list[float], k: int) -> list:
    """Pick k distinct slots, favouring higher weights (Efraimidis-Spirakis sampling).

    Used to push breaches and violations toward recent timestamps, which gives the trend-based
    risk indicator (PRD should-have 7) something real to detect instead of flat noise.
    """
    keyed = [(rng.random() ** (1.0 / w), slot) for slot, w in zip(slots, weights, strict=True)]
    keyed.sort(key=lambda pair: pair[0], reverse=True)
    return [slot for _, slot in keyed[:k]]


def _recency_weight(index: int, total: int) -> float:
    """Later readings weigh more, so conditions look like they are deteriorating."""
    return 1.0 + 3.0 * (index / max(1, total - 1))


def generate_sensor_readings(mines: list[dict], rng: random.Random, now: datetime) -> list[dict]:
    """Build the full reading set: a 72-hour window per sensor per mine."""
    rows: list[dict] = []
    reading_id = 1

    for mine in mines:
        slots = [(s, i) for s in SensorType for i in range(READINGS_PER_SENSOR)]
        weights = [_recency_weight(i, READINGS_PER_SENSOR) for _, i in slots]
        breach_slots = set(_weighted_sample(rng, slots, weights, mine["breaches"]))

        for sensor_type, index in slots:
            is_breach = (sensor_type, index) in breach_slots
            low, high = RANGES[sensor_type]["breach" if is_breach else "normal"]
            value = round(rng.uniform(low, high), 1)

            # Oldest reading first so a chart plotted in file order reads left to right.
            hours_ago = (READINGS_PER_SENSOR - 1 - index) * INTERVAL_HOURS
            recorded_at = now - timedelta(hours=hours_ago, minutes=rng.randint(0, 45))

            rows.append(
                {
                    "reading_id": reading_id,
                    "mine_id": mine["id"],
                    "sensor_type": sensor_type.value,
                    "value": value,
                    "unit": sensor_type.unit,
                    "recorded_at": recorded_at.isoformat(),
                }
            )
            reading_id += 1

    rows.sort(key=lambda r: (r["mine_id"], r["sensor_type"], r["recorded_at"]))
    for new_id, row in enumerate(rows, start=1):
        row["reading_id"] = new_id
    return rows


def generate_violations(mines: list[dict], rng: random.Random, now: datetime) -> list[dict]:
    """Historical PPE violations so mines start with a spread of scores.

    Live detections from the CV module append to this same table during the demo, which is what
    makes the score visibly drop on stage.
    """
    violations: list[dict] = []
    for mine in mines:
        count = mine["violations"]
        if not count:
            continue
        slot_count = 24
        slots = list(range(slot_count))
        weights = [_recency_weight(i, slot_count) for i in slots]
        for slot in sorted(_weighted_sample(rng, slots, weights, count)):
            hours_ago = (slot_count - 1 - slot) * 3
            violations.append(
                {
                    "mine_id": mine["id"],
                    "violation_type": rng.choice(PPE_VIOLATION_TYPES),
                    "confidence": round(rng.uniform(0.52, 0.94), 2),
                    "source": "VISION",
                    "frame_ref": f"annotated/{mine['code'].lower()}_frame_{rng.randint(1, 999):04d}.jpg",
                    "detected_at": (
                        now - timedelta(hours=hours_ago, minutes=rng.randint(0, 55))
                    ).isoformat(),
                }
            )
    return violations


def generate_users(mines: list[dict]) -> list[dict]:
    """One Government account plus one Mine Head per mine (PRD Section 3).

    Passwords are plaintext here on purpose: this is a demo fixture, and seed_db.py hashes them on
    insert. Nothing in this file is a real credential.
    """
    users = [
        {
            "email": "gov@dgms.gov.in",
            "password": "demo123",
            "full_name": "DGMS Compliance Authority",
            "role": "GOVERNMENT",
            "mine_id": None,
        }
    ]
    for mine in mines:
        users.append(
            {
                "email": f"head.{mine['code'].lower()}@coalmine.in",
                "password": "demo123",
                "full_name": f"Mine Head - {mine['name']}",
                "role": "MINE_HEAD",
                "mine_id": mine["id"],
            }
        )
    return users


def _write_csv(rows: list[dict], path: Path) -> None:
    header = ["reading_id", "mine_id", "sensor_type", "value", "unit", "recorded_at"]
    with path.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=header)
        writer.writeheader()
        writer.writerows(rows)


def _write_json(payload, path: Path) -> None:
    path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")


def _score_mines(mines, readings, violations, weights):
    """Project every mine's score from the generated data, using the real engine."""
    breach_limits = {s.value: s.limit for s in SensorType}
    breach_by_mine, ppe_by_mine = {}, {}
    for r in readings:
        if r["value"] > breach_limits[r["sensor_type"]]:
            breach_by_mine[r["mine_id"]] = breach_by_mine.get(r["mine_id"], 0) + 1
    for v in violations:
        ppe_by_mine[v["mine_id"]] = ppe_by_mine.get(v["mine_id"], 0) + 1

    return {
        m["id"]: compute_compliance_score(
            ppe_by_mine.get(m["id"], 0), breach_by_mine.get(m["id"], 0), weights
        )
        for m in mines
    }


def _report(mines: list[dict], readings: list[dict], violations: list[dict]) -> None:
    """Summarise the generated dataset.

    Scored with the real engine, so an unusable data set - every mine green, or every
    mine red - is visible here instead of on the dashboard during judging. Summarised by
    state rather than listed per mine: at ~70 mines a full listing is unreadable.
    """
    weights = ScoringWeights.from_settings()
    scores = _score_mines(mines, readings, violations, weights)

    print()
    print(f"Mines   : {len(mines)}   Readings: {len(readings)}   Violations: {len(violations)}")
    print(f"Weights : weight_ppe={weights.weight_ppe}  weight_env={weights.weight_env}")
    print()

    by_state: dict[str, list] = {}
    for mine in mines:
        by_state.setdefault(mine["state"], []).append(scores[mine["id"]])

    print(f"{'STATE':<20}{'MINES':>6}{'AVG':>7}{'HIGH':>6}{'MED':>5}{'LOW':>5}")
    print("-" * 49)
    for state in sorted(by_state, key=lambda k: -len(by_state[k])):
        rows = by_state[state]
        bands = [r.risk_level.value for r in rows]
        avg = round(sum(r.score for r in rows) / len(rows), 1)
        print(f"{state:<20}{len(rows):>6}{avg:>7}{bands.count('HIGH'):>6}"
              f"{bands.count('MEDIUM'):>5}{bands.count('LOW'):>5}")

    worst = sorted(mines, key=lambda m: scores[m["id"]].score)[:5]
    print()
    print("National top 5 highest risk (the default Overview board):")
    for rank, mine in enumerate(worst, 1):
        result = scores[mine["id"]]
        print(f"  {rank}. {mine['name'][:34]:<34} {mine['district']}, {mine['state']:<16}"
              f" {result.score:>5} {result.risk_level.value}")

    bands = {r.risk_level for r in scores.values()}
    if len(bands) < 3:
        print(
            f"WARNING: only {len(bands)} risk band(s) present: {sorted(b.value for b in bands)}."
            " The cross-mine comparison needs green, yellow and red on screen at once."
            " Adjust the counts in NAMED_MINES or build_mines, or retune the weights."
        )


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate seed data for the demo database.")
    parser.add_argument("--seed", type=int, default=DEFAULT_SEED, help="RNG seed (default: 26024)")
    args = parser.parse_args()

    rng = random.Random(args.seed)
    # Anchor to a fixed instant so re-running does not churn every timestamp in git.
    now = datetime(2026, 9, 9, 9, 0, tzinfo=UTC)

    SEED_DIR.mkdir(parents=True, exist_ok=True)
    mines = build_mines(rng)
    readings = generate_sensor_readings(mines, rng, now)
    violations = generate_violations(mines, rng, now)

    # The PRD's 150-200 figure was written against the original five mines. What it was
    # really asking for is a realistic density per mine, which is unchanged - so the
    # guard now checks that rather than a total that no longer means anything.
    per_mine = len(readings) / max(1, len(mines))
    expected = READINGS_PER_SENSOR * 3
    if per_mine != expected:
        print(f"ERROR: {per_mine:.1f} readings per mine, expected {expected}.")
        return 1

    _write_csv(readings, SEED_DIR / "sensor_readings.csv")
    _write_json(
        [{k: v for k, v in m.items() if k not in ("breaches", "violations")} for m in mines],
        SEED_DIR / "mines.json",
    )
    _write_json(generate_users(mines), SEED_DIR / "users.json")
    _write_json(violations, SEED_DIR / "violations.json")

    _report(mines, readings, violations)
    print(f"\nWrote 4 files to {SEED_DIR}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
