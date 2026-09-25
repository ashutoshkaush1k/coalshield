"""Source S08, step 2: cut the MSHA zips down to coal mines and the last 10 years, as parquet.

Later stages read only raw/msha/filtered/*.parquet and never load the full files (Violations alone
is ~1.4 GB uncompressed). The zips are kept, unmodified, next to them.

Column names and formats come from MSHA's own definition files and were checked against the
data on 2026-09-25:
  COAL_METAL_IND  "C" = coal, "M" = metal/non-metal         (all four datasets)
  dates           mm/dd/yyyy - INSPECTION_BEGIN_DT, VIOLATION_ISSUE_DT, ACCIDENT_DT
Fields are pipe-delimited and double-quoted, UTF-8, header row first.

"Last 10 years" counts back from config.yaml's pinned end_date, not from today, so the output is
the same on every run. Mines has no event date: every coal mine is kept (it is the reference table
the other three join to). Every column is kept as text, exactly as published; stage D3 types them.
Re-running is cheap: a stamp records the zip checksum each parquet came from.
"""

from __future__ import annotations

import datetime as dt
import json
import zipfile
from pathlib import Path

import pandas as pd
import pyarrow as pa
import pyarrow.parquet as pq

from common import DATA, date_window, sha256_file

MSHA = DATA / "raw" / "msha"
OUT = MSHA / "filtered"
DATASETS = {  # zip -> (output name, date column or None)
    "Mines.zip": ("mines", None),
    "Inspections.zip": ("inspections", "INSPECTION_BEGIN_DT"),
    "Violations.zip": ("violations", "VIOLATION_ISSUE_DT"),
    "Accidents.zip": ("accidents", "ACCIDENT_DT"),
}
CHUNK = 200_000


def _cutoff(config: dict) -> tuple[dt.date, dt.date]:
    _, end = date_window(config)
    try:
        start = end.replace(year=end.year - 10)
    except ValueError:  # 29 February
        start = end.replace(year=end.year - 10, day=28)
    return start, end


def _filter_one(zip_path: Path, out_name: str, date_col: str | None, start: dt.date, end: dt.date) -> dict:
    out_path = OUT / f"{out_name}.parquet"
    read = kept = 0
    writer = None
    with zipfile.ZipFile(zip_path) as zf:
        member = zf.namelist()[0]
        with zf.open(member) as fh:
            reader = pd.read_csv(fh, sep="|", quotechar='"', dtype=str, keep_default_na=False,
                                 encoding="utf-8", encoding_errors="replace", chunksize=CHUNK)
            for chunk in reader:
                read += len(chunk)
                mask = chunk["COAL_METAL_IND"].str.strip() == "C"
                if date_col:
                    d = pd.to_datetime(chunk[date_col], format="%m/%d/%Y", errors="coerce")
                    mask &= (d >= pd.Timestamp(start)) & (d <= pd.Timestamp(end))
                part = chunk[mask]
                kept += len(part)
                table = pa.Table.from_pandas(part, preserve_index=False)
                if writer is None:
                    tmp = out_path.with_suffix(".parquet.part")
                    writer = pq.ParquetWriter(tmp, table.schema)
                writer.write_table(table)
    writer.close()
    out_path.with_suffix(".parquet.part").replace(out_path)
    return {"source_member": member, "rows_read": read, "rows_kept": kept, "output": out_path.relative_to(DATA).as_posix()}


def run(src: dict, config: dict) -> dict:
    OUT.mkdir(parents=True, exist_ok=True)
    start, end = _cutoff(config)
    stamp_path = OUT / "_stamp.json"
    stamp = json.loads(stamp_path.read_text(encoding="utf-8")) if stamp_path.exists() else {}
    results = {"rule": f"COAL_METAL_IND == 'C'; event date from {start} to {end} inclusive (mines: all coal mines)"}
    for zip_name, (out_name, date_col) in DATASETS.items():
        z = MSHA / zip_name
        sha = sha256_file(z)
        prev = stamp.get(out_name, {})
        if prev.get("zip_sha256") == sha and prev.get("window") == [str(start), str(end)] and (OUT / f"{out_name}.parquet").exists():
            results[out_name] = {**prev["result"], "status": "unchanged"}
            continue
        print(f"  S08  filtering  {zip_name} -> filtered/{out_name}.parquet", flush=True)
        res = _filter_one(z, out_name, date_col, start, end)
        stamp[out_name] = {"zip_sha256": sha, "window": [str(start), str(end)], "result": res}
        results[out_name] = {**res, "status": "written"}
        print(f"  S08  kept {res['rows_kept']:,} of {res['rows_read']:,} rows", flush=True)
    stamp_path.write_text(json.dumps(stamp, indent=1, sort_keys=True), encoding="utf-8", newline="\n")
    return results
