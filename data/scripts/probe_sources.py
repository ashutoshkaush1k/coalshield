"""Stage D1: verify every URL in data/sources.yaml and record what the server says about it.

For each non-manual file with a URL, asks the server (politely: robots.txt, 1 request/s per host)
for its status, final URL after redirects, size, type and last-modified date - without
downloading the body - and writes those fields back into sources.yaml. Also writes a dated JSON
log to data/raw/_probe/ (gitignored).

    data\\.venv\\Scripts\\python.exe data\\scripts\\probe_sources.py

Exit code 1 if any probed URL is not reachable, so a broken link is never silently recorded.
"""

from __future__ import annotations

import datetime as dt
import json
import sys
from pathlib import Path

import yaml

from polite_http import PoliteSession

DATA = Path(__file__).resolve().parents[1]
MANIFEST = DATA / "sources.yaml"
PROBE_FIELDS = ("http_status", "final_url", "size_bytes", "content_type", "last_modified",
                "probe_method", "accessed_at")


def header_of(text: str) -> str:
    """The leading comment block, kept verbatim when the manifest is rewritten."""
    lines = []
    for line in text.splitlines():
        if line.startswith("#") or not line.strip():
            lines.append(line)
        else:
            break
    return "\n".join(lines).rstrip() + "\n\n"


def main() -> int:
    raw = MANIFEST.read_text(encoding="utf-8")
    manifest = yaml.safe_load(raw)
    session = PoliteSession()
    today = dt.datetime.now(dt.timezone.utc).date().isoformat()
    log, failures = [], []

    for src in manifest["sources"]:
        for f in src.get("files") or []:
            url = f.get("url")
            if not url or f.get("manual") or f.get("kind") == "api":
                continue
            p = session.probe(url)
            ok = p.status is not None and 200 <= p.status < 400
            f.update({
                "http_status": p.status,
                "final_url": p.final_url,
                "size_bytes": p.size_bytes,
                "content_type": p.content_type,
                "last_modified": p.last_modified,
                "probe_method": p.method,
                "accessed_at": today,
            })
            f.setdefault("sha256", None)  # filled at download time (D2)
            if p.error:
                f["probe_error"] = p.error
            else:
                f.pop("probe_error", None)
            size = f"{p.size_bytes:,}" if p.size_bytes else "-"
            print(f"{src['id']}  {str(p.status or 'ERR'):>4}  {size:>13}  {f['name'][:62]}")
            log.append({"source": src["id"], "name": f["name"], "url": url, **{k: f[k] for k in PROBE_FIELDS},
                        "error": p.error})
            if not ok:
                failures.append((src["id"], f["name"], p.status, p.error))

    body = yaml.safe_dump(manifest, sort_keys=False, allow_unicode=True, width=100)
    MANIFEST.write_text(header_of(raw) + body, encoding="utf-8", newline="\n")

    out = DATA / "raw" / "_probe"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"probe_{today}.json").write_text(json.dumps(log, indent=2), encoding="utf-8")

    print(f"\n{len(log)} URLs probed, {len(failures)} not reachable.")
    for sid, name, status, err in failures:
        print(f"  FAILED {sid} {name}: {status} {err or ''}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
