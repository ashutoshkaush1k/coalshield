"""Source S09: daily PM10, PM2.5, SO2 and NO2 from OpenAQ v3 for stations near the coalfield clusters.

Runs only when OPENAQ_API_KEY is set in the repo-root .env. The key is sent in the X-API-Key
header and is never printed, logged or written to disk.

Endpoints and parameters are taken from OpenAQ's API reference (checked 2026-09-25):
  GET /v3/locations?iso=IN&limit=&page=        - locations list; each location embeds its sensors
  GET /v3/sensors/{id}/days?date_from=&date_to=&limit=&page=   - daily aggregates
  GET /v3/licenses                              - licence of each data provider
Stations are matched to clusters by name (the clusters named in the brief), not by coordinates
typed from memory; distances to our mines are computed in stage D3.

Rate limits are 60/minute and 2,000/hour for a free key. PoliteSession keeps under 60/minute
(1 request per second); REQUEST_BUDGET keeps a single run well under the hourly cap. Responses are
saved as JSON and skipped on re-run if already present, so a re-run does not re-download.
"""

from __future__ import annotations

import json
import os
import re
from pathlib import Path

from dotenv import load_dotenv

from common import DATA, REPO, date_window, utc_now
from polite_http import PoliteSession

API = "https://api.openaq.org/v3"
OUT = DATA / "raw" / "openaq"
# The clusters named in the brief (Angul/Talcher is one cluster with two names).
CLUSTERS = ["Dhanbad", "Asansol", "Korba", "Singrauli", "Angul", "Talcher", "Chandrapur",
            "Ramagundam", "Neyveli"]
WANTED = {"pm10", "pm25", "so2", "no2"}  # compared after lower-casing and removing . _ and spaces
REQUEST_BUDGET = 1500


class _Budget:
    def __init__(self, n: int):
        self.left = n

    def take(self) -> None:
        if self.left <= 0:
            raise RuntimeError(f"request budget of {REQUEST_BUDGET} reached - re-run later to continue")
        self.left -= 1


def _norm(name: str | None) -> str:
    return re.sub(r"[\s._]", "", (name or "").lower())


def _get_json(session: PoliteSession, budget: _Budget, key: str, path: str, params: dict) -> dict:
    budget.take()
    resp = session.get(f"{API}{path}", params=params, headers={"X-API-Key": key, "Accept": "application/json"})
    if resp.status_code == 401:
        raise RuntimeError("OpenAQ rejected the API key (401) - check OPENAQ_API_KEY in .env")
    resp.raise_for_status()
    return resp.json()


def _save(path: Path, data: dict) -> int:
    path.parent.mkdir(parents=True, exist_ok=True)
    text = json.dumps(data, indent=1, sort_keys=True, ensure_ascii=False)
    path.write_text(text, encoding="utf-8", newline="\n")
    return len(text.encode("utf-8"))


def run(config: dict) -> dict:
    """Returns a status record for the manifest. Never raises for a missing key."""
    load_dotenv(REPO / ".env")
    key = os.environ.get("OPENAQ_API_KEY", "").strip()
    if not key:
        return {"download_status": "skipped-manual", "message": "skipped - manual key missing"}

    session, budget = PoliteSession(timeout=60), _Budget(REQUEST_BUDGET)
    first, last = date_window(config)
    fetched, reused = 0, 0

    # 1. licences of the data providers
    lic_path = OUT / "licenses.json"
    if not lic_path.exists():
        _save(lic_path, _get_json(session, budget, key, "/licenses", {"limit": 1000}))
        fetched += 1
    else:
        reused += 1

    # 2. every location in India (paged), then the ones whose name or locality names a cluster
    locations, page = [], 1
    while True:
        p = OUT / "locations" / f"locations_IN_page{page:03d}.json"
        if p.exists():
            data = json.loads(p.read_text(encoding="utf-8")); reused += 1
        else:
            data = _get_json(session, budget, key, "/locations", {"iso": "IN", "limit": 1000, "page": page})
            _save(p, data); fetched += 1
        locations += data.get("results", [])
        if len(data.get("results", [])) < 1000:
            break
        page += 1

    matched = []
    for loc in locations:
        text = f"{loc.get('name') or ''} {loc.get('locality') or ''}"
        hits = [c for c in CLUSTERS if re.search(rf"\b{c}\b", text, re.I)]
        if hits:
            matched.append({"location_id": loc["id"], "name": loc.get("name"), "locality": loc.get("locality"),
                            "clusters": hits, "coordinates": loc.get("coordinates"),
                            "provider": (loc.get("provider") or {}).get("name"),
                            "licenses": loc.get("licenses"),
                            "sensors": [{"sensor_id": s["id"], "parameter": (s.get("parameter") or {}).get("name"),
                                         "units": (s.get("parameter") or {}).get("units")}
                                        for s in loc.get("sensors") or []]})
    _save(OUT / "matched_locations.json", {"window": [first.isoformat(), last.isoformat()],
                                           "clusters": CLUSTERS, "locations": matched})

    # 3. daily values for the wanted pollutants at matched stations, for the generated window
    sensors = [(m["location_id"], s) for m in matched for s in m["sensors"] if _norm(s["parameter"]) in WANTED]
    for loc_id, s in sensors:
        page = 1
        while True:
            p = OUT / "days" / f"sensor{s['sensor_id']}_{first}_{last}_p{page:02d}.json"
            if p.exists():
                data = json.loads(p.read_text(encoding="utf-8")); reused += 1
            else:
                data = _get_json(session, budget, key, f"/sensors/{s['sensor_id']}/days",
                                 {"date_from": first.isoformat(), "date_to": last.isoformat(),
                                  "limit": 100, "page": page})
                _save(p, data); fetched += 1
            if len(data.get("results", [])) < 100:
                break
            page += 1

    return {"download_status": "ok", "fetched_at": utc_now(), "window": [first.isoformat(), last.isoformat()],
            "locations_in_india": len(locations), "matched_locations": len(matched),
            "sensors_fetched": len(sensors), "requests_made": fetched, "files_reused": reused,
            "message": f"{len(matched)} stations matched to clusters; {len(sensors)} pollutant sensors"}
