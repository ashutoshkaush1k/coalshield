"""Shared machinery for the D4 generators.

- Ctx: preset, date window, the selected mine roster, the tables built so far, seeded RNGs.
- Schemas (data/schema/*.yaml) are loaded once; `ctx.emit(table, df)` checks the frame against its
  schema (exact column set, enums, nullability) and formats it for CSV. Nothing reaches disk
  unless it matches.
- Rules (data/schema/rules.yaml): every legal value cites an obligation code, and the code must be
  a verified row of reference/obligations.csv, else the run stops (brief rule 7).
- Determinism: one global seed (config.yaml); each generator gets its own stream from
  (seed, generator name), so adding a generator never shifts another's numbers.
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

import numpy as np
import pandas as pd
import yaml

DATA = Path(__file__).resolve().parents[1]


def load_config() -> dict:
    return yaml.safe_load((DATA / "config.yaml").read_text(encoding="utf-8"))


SCHEMA = DATA / "schema"
REF = DATA / "reference"
NAMED = ["JH-DHN-01", "MP-SGR-02", "CG-KRB-03", "WB-RNG-04", "OD-TLC-05"]
UTC = timezone.utc
# State -> language for users and grievances (the six UI languages; en where none fits).
STATE_LANGUAGE = {"Jharkhand": "hi", "Madhya Pradesh": "hi", "Chhattisgarh": "hi", "Uttar Pradesh": "hi",
                  "Jammu and Kashmir": "hi", "Odisha": "or", "West Bengal": "bn", "Telangana": "te",
                  "Maharashtra": "mr", "Assam": "en"}


class GenerationError(RuntimeError):
    pass


def stable_int(*parts) -> int:
    return int.from_bytes(hashlib.sha256("|".join(map(str, parts)).encode()).digest()[:8], "big")


# ---------------------------------------------------------------------------------------------
# Schemas
# ---------------------------------------------------------------------------------------------
def load_schemas() -> dict[str, dict]:
    cats = yaml.safe_load((SCHEMA / "violation_categories.yaml").read_text(encoding="utf-8"))
    cat_keys = [c["key"] for c in cats["categories"]]
    out = {}
    for f in sorted(SCHEMA.glob("*.yaml")):
        s = yaml.safe_load(f.read_text(encoding="utf-8"))
        if not isinstance(s, dict) or "table" not in s:
            continue
        for c in s["columns"]:
            if c.get("values_from") == "violation_categories.yaml":
                c["values"] = cat_keys
        out[s["table"]] = s
    return out


def categories() -> dict:
    return yaml.safe_load((SCHEMA / "violation_categories.yaml").read_text(encoding="utf-8"))


def fmt_datetime(s: pd.Series) -> pd.Series:
    t = pd.to_datetime(s, utc=True)
    return t.dt.strftime("%Y-%m-%dT%H:%M:%SZ").where(t.notna(), "")


def format_frame(df: pd.DataFrame, schema: dict) -> pd.DataFrame:
    """Check a frame against its schema and return it as strings, ready for CSV."""
    table = schema["table"]
    cols = [c["name"] for c in schema["columns"]]
    missing, extra = set(cols) - set(df.columns), set(df.columns) - set(cols)
    if missing or extra:
        raise GenerationError(f"{table}: columns do not match schema (missing {sorted(missing)}, extra {sorted(extra)})")
    out = pd.DataFrame(index=df.index)
    for c in schema["columns"]:
        name, typ, s = c["name"], c["type"], df[c["name"]]
        null = s.isna() | (s.astype(str) == "")
        if null.any() and not c.get("nullable"):
            raise GenerationError(f"{table}.{name}: {int(null.sum())} empty values in a non-nullable column")
        if typ == "enum":
            bad = set(s[~null].astype(str)) - set(map(str, c["values"]))
            if bad:
                raise GenerationError(f"{table}.{name}: values {sorted(bad)[:5]} not in schema enum")
            out[name] = s.astype(str).where(~null, "")
        elif typ == "boolean":
            out[name] = s.map({True: "true", False: "false"}).where(~null, "")
            if out[name][~null].isna().any():
                raise GenerationError(f"{table}.{name}: non-boolean values")
        elif typ == "datetime":
            out[name] = fmt_datetime(s.where(~null))
        elif typ == "date":
            out[name] = pd.to_datetime(s.where(~null)).dt.strftime("%Y-%m-%d").where(~null, "")
        elif typ == "integer":
            out[name] = s.where(~null).map(lambda v: str(int(v)) if pd.notna(v) else "")
        elif typ == "number":
            dec = c.get("decimals", 3)
            out[name] = s.where(~null).map(lambda v: f"{float(v):.{dec}f}".rstrip("0").rstrip(".") if pd.notna(v) else "")
        elif typ == "json":
            out[name] = s.map(lambda v: json.dumps(v, sort_keys=True, ensure_ascii=False, separators=(",", ":")))
        else:
            out[name] = s.astype(str).where(~null, "")
    pk = schema.get("primary_key")
    if pk and out[pk].duplicated().any():
        raise GenerationError(f"{table}: duplicate primary key values")
    for uniq in schema.get("unique", []):
        if df.duplicated(subset=uniq).any():
            raise GenerationError(f"{table}: duplicate values for unique {uniq}")
    return out[cols]


# ---------------------------------------------------------------------------------------------
# Rules (legal values with obligation citations)
# ---------------------------------------------------------------------------------------------
def load_rules() -> dict:
    rules = yaml.safe_load((SCHEMA / "rules.yaml").read_text(encoding="utf-8"))
    ob = pd.read_csv(REF / "obligations.csv", dtype=str).set_index("obligation_code")
    cited = [(k, v["obligation"]) for k, v in rules["legal"].items()]
    cited += [(k, v["obligation"]) for k, v in rules["sensors"].items() if v.get("obligation")]
    cited += [(k, v["obligation"]) for k, v in rules["environment"].items()]
    for key, code in cited:
        if code not in ob.index:
            raise GenerationError(f"rules.yaml {key}: obligation {code} not in reference/obligations.csv")
        if ob.at[code, "verified"] != "yes":
            raise GenerationError(f"rules.yaml {key}: obligation {code} is not verified")
    return rules


# ---------------------------------------------------------------------------------------------
# Roster
# ---------------------------------------------------------------------------------------------
def load_roster(which: str) -> pd.DataFrame:
    """Columns every generator may use, for either roster."""
    real = pd.read_csv(REF / "mines_real.csv", dtype={"gem_id": str})
    if which == "real":
        r = real.copy()
        r["capacity_imputed"] = r["capacity_mtpa"].isna()
        r["workforce_source"] = "GEM (" + r["workforce_accuracy"].fillna("unknown") + ")"
    elif which == "seed":
        seed = pd.read_csv(REF / "mines.csv", dtype={"gem_id": str})
        base = pd.read_csv(REF / "mines_base.csv")[["code", "region", "demo_score", "demo_risk_level",
                                                    "seed_violations", "demo_named", "head_email"]]
        r = seed.merge(base, on="code", how="left")
        # The seed has no capacity or workforce: impute from the real roster, same company (else all).
        cap = real.groupby("company_id")["capacity_mtpa"].median()
        r["capacity_imputed"] = r["capacity_mtpa"].isna()
        r["capacity_mtpa"] = r["capacity_mtpa"].fillna(r["company_id"].map(cap)).fillna(real["capacity_mtpa"].median())
        r["type"] = r["type"].fillna("opencast").replace("", "opencast")
        ratio = (real["workforce"] / real["capacity_mtpa"]).groupby(real["type"]).median()
        r["workforce"] = (r["capacity_mtpa"] * r["type"].map(ratio).fillna(ratio.median())).round()
        r["workforce_source"] = "imputed from company capacity (seed roster)"
    else:
        raise GenerationError(f"config.yaml mine_roster must be real or seed, not {which!r}")
    r["type"] = r["type"].replace("", "opencast").fillna("opencast")
    med_cap = r.groupby("company_id")["capacity_mtpa"].transform("median")
    r["capacity_mtpa"] = r["capacity_mtpa"].fillna(med_cap).fillna(1.0)
    r["demo_named"] = r["demo_named"].astype(str).str.lower().eq("true")
    r["seed_violations"] = r["seed_violations"].astype(int)
    r["language"] = r["state"].map(STATE_LANGUAGE).fillna("en")
    return r.sort_values("id").reset_index(drop=True)


# ---------------------------------------------------------------------------------------------
# Context
# ---------------------------------------------------------------------------------------------
@dataclass
class Ctx:
    preset: str
    config: dict
    days: int
    start: date
    end: date
    roster_name: str
    mines: pd.DataFrame
    schemas: dict
    rules: dict
    out_dir: Path
    tables: dict = field(default_factory=dict)
    notes: dict = field(default_factory=dict)

    @property
    def seed(self) -> int:
        return int(self.config["seed"])

    @property
    def as_of(self) -> pd.Timestamp:
        """The generation reference time: the end of the window. Nothing is dated after it."""
        return pd.Timestamp(self.end, tz=UTC) + pd.Timedelta(hours=23, minutes=59, seconds=59)

    @property
    def scale(self) -> dict:
        return self.config["scales"][self.preset]

    @property
    def gen(self) -> dict:
        return self.config["generation"]

    def rng(self, name: str) -> np.random.Generator:
        return np.random.default_rng([self.seed, stable_int(name) % (2**32)])

    def emit(self, table: str, df: pd.DataFrame) -> None:
        if table not in self.schemas:
            raise GenerationError(f"no schema for table {table}")
        df = df.reset_index(drop=True)
        format_frame(df, self.schemas[table])   # validate now; format again at write time
        self.tables[table] = df

    def day_range(self) -> pd.DatetimeIndex:
        return pd.date_range(self.start, self.end, freq="D")


def make_ctx(preset: str | None, out_root: Path | None = None, roster: str | None = None) -> Ctx:
    config = load_config()
    if roster:
        config["mine_roster"] = roster
    preset = preset or config.get("scale", "demo")
    if preset not in config["scales"]:
        raise GenerationError(f"unknown preset {preset!r}; config.yaml scales has {list(config['scales'])}")
    sc = config["scales"][preset]
    days = int(sc.get("days", config["date_range"]["days"]))
    end = date.fromisoformat(config["date_range"]["end_date"])
    start = end - timedelta(days=days - 1)
    roster = config.get("mine_roster", "real")
    mines = load_roster(roster)
    if sc.get("mines") == "named":
        mines = mines[mines["code"].isin(NAMED)].reset_index(drop=True)
    out_dir = (out_root or DATA / "out") / preset
    return Ctx(preset=preset, config=config, days=days, start=start, end=end, roster_name=roster, mines=mines,
               schemas=load_schemas(), rules=load_rules(), out_dir=out_dir)


def write_all(ctx: Ctx) -> dict[str, dict]:
    ctx.out_dir.mkdir(parents=True, exist_ok=True)
    for old in ctx.out_dir.glob("*.csv"):
        old.unlink()
    info = {}
    for table in sorted(ctx.tables):
        df = format_frame(ctx.tables[table].reset_index(drop=True), ctx.schemas[table])
        path = ctx.out_dir / f"{table}.csv"
        df.to_csv(path, index=False, lineterminator="\n", encoding="utf-8")
        info[table] = {"rows": len(df), "bytes": path.stat().st_size,
                       "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
    return info
