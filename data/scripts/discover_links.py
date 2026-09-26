"""Stage D1 helper: politely fetch a landing page and list its links, optionally filtered.

    data\\.venv\\Scripts\\python.exe data\\scripts\\discover_links.py URL [REGEX] [--probe]

--probe also HEAD-checks every matching link (status, size, type). All traffic goes through
PoliteSession: robots.txt, 1 request/s per host, retries.
"""

from __future__ import annotations

import re
import sys

from polite_http import PoliteSession, RobotsDisallowed, extract_links


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    do_probe = "--probe" in sys.argv
    if not args:
        print(__doc__)
        return 2
    url, pattern = args[0], (args[1] if len(args) > 1 else None)
    s = PoliteSession()
    try:
        resp = s.get(url)
    except RobotsDisallowed as exc:
        print(f"ROBOTS: {exc}")
        return 1
    except Exception as exc:  # network / TLS failures are findings, not crashes
        print(f"FETCH FAILED: {type(exc).__name__}: {exc}"[:400])
        return 1
    print(f"GET {url}\n -> {resp.status_code} {resp.url}  ({len(resp.content):,} bytes, "
          f"{resp.headers.get('Content-Type')})")
    rx = re.compile(pattern, re.I) if pattern else None
    seen = set()
    for href, text in extract_links(resp.text, resp.url):
        if rx and not (rx.search(href) or rx.search(text)):
            continue
        if href in seen:
            continue
        seen.add(href)
        line = f"  {text[:70]!r:74} {href}"
        if do_probe:
            p = s.probe(href)
            line += f"\n      -> {p.status} {p.size_bytes} {p.content_type} {p.error or ''}"
        print(line)
    print(f"({len(seen)} links{' matching' if rx else ''})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
