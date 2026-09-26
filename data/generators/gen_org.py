"""subsidiary, area, mine - from the reference data (no randomness)."""

from __future__ import annotations

import pandas as pd

from common import REF, Ctx

ORDER = ["CIL", "ECL", "BCCL", "CCL", "NCL", "WCL", "SECL", "MCL", "SCCL", "NLC"]


def run(ctx: Ctx) -> None:
    comp = pd.read_csv(REF / "companies.csv", dtype=str)
    comp["rank"] = comp["company_id"].map({c: i for i, c in enumerate(ORDER)}).fillna(99)
    comp = comp.sort_values(["rank", "company_id"]).reset_index(drop=True)
    comp["id"] = range(1, len(comp) + 1)
    sub_id = dict(zip(comp["company_id"], comp["id"]))
    subsidiary = pd.DataFrame({
        "id": comp["id"], "code": comp["company_id"], "name": comp["name"],
        "type": comp["type"].where(comp["type"].isin(["holding", "subsidiary", "psu", "state_jv", "private"]), "psu"),
        "parent_id": comp["parent_id"].map(sub_id)})
    ctx.emit("subsidiary", subsidiary)

    areas = pd.read_csv(REF / "areas.csv", dtype=str).sort_values(["company_id", "area_id"]).reset_index(drop=True)
    areas["id"] = range(1, len(areas) + 1)
    area_id = dict(zip(areas["area_id"], areas["id"]))
    ctx.emit("area", pd.DataFrame({
        "id": areas["id"], "subsidiary_id": areas["company_id"].map(sub_id), "code": areas["area_id"],
        "name": areas["area_name"], "district": areas["district"], "state": areas["state"]}))

    m = ctx.mines
    mine = pd.DataFrame({
        "id": m["id"], "code": m["code"], "name": m["name"], "type": m["type"],
        "subsidiary_id": m["company_id"].map(sub_id), "area_id": m["area_id"].map(area_id),
        "location": [f"POINT({lon} {lat})" if pd.notna(lat) else None for lat, lon in zip(m["lat"], m["lon"])],
        "boundary": None, "status": "active", "district": m["district"], "state": m["state"],
        "region": m["region"], "location_quality": m["location_quality"],
        # Only published capacities; the seed roster's imputed values stay internal.
        "capacity_mtpa": m["capacity_mtpa"] if ctx.roster_name == "real" else None,
        "gem_id": m["gem_id"]})
    if mine["subsidiary_id"].isna().any():
        raise ValueError("a mine's company is not in companies.csv")
    ctx.emit("mine", mine)
    ctx.notes["subsidiary_id"] = sub_id
    ctx.notes["area_id"] = area_id
