"""Render data/SOURCES.md from data/sources.yaml, so the two can never disagree.

    data\\.venv\\Scripts\\python.exe data\\scripts\\render_sources_md.py
"""

from __future__ import annotations

from pathlib import Path

import yaml

DATA = Path(__file__).resolve().parents[1]

ACCESS_LABEL = {
    "repo": "In the repo",
    "open": "Automatic",
    "manual_form": "Manual - web form",
    "manual_key": "Manual - free API key",
    "manual_browser": "Manual - in a browser",
    "skipped": "Skipped",
}


def mb(n: int | None) -> str:
    if not n:
        return "-"
    return f"{n / 1_048_576:.1f} MB" if n >= 1_048_576 else f"{n / 1024:.0f} KB"


def cell(text) -> str:
    return str(text if text is not None else "-").replace("|", "\\|").replace("\n", " ")


def main() -> None:
    m = yaml.safe_load((DATA / "sources.yaml").read_text(encoding="utf-8"))
    out = [
        "# Sources",
        "",
        "Generated from `data/sources.yaml` by `data/scripts/render_sources_md.py` - edit the YAML,",
        "not this file. Every URL was found on the source's own page and checked by",
        "`data/scripts/probe_sources.py` (robots.txt respected, at most 1 request per second per host).",
        f"Discovered {m['discovered_on']}. SHA-256 checksums are recorded when files are downloaded (stage D2).",
        "",
        "## Summary",
        "",
        "| ID | Source | Access | Automatic files | Size known | Download (D2) | Licence |",
        "|---|---|---|---|---|---|---|",
    ]
    total = 0
    for s in m["sources"]:
        files = s.get("files") or []
        auto = [f for f in files if f.get("url") and not f.get("manual") and f.get("kind") != "api"]
        size = sum(f.get("size_bytes") or 0 for f in auto)
        total += size
        manual = sum(1 for f in files if f.get("manual"))
        access = ACCESS_LABEL.get(s["access"], s["access"])
        if s["access"] == "open" and manual:
            access += f" (+{manual} manual)"
        if s.get("optional"):
            access += ", optional"
        out.append(f"| {s['id']} | {cell(s['name'])} | {access} | {len(auto)} | {mb(size)} | "
                   f"{cell(s.get('d2_status', 'not run'))} | {cell(s['licence'])} |")
    out += ["", f"Automatic downloads total about **{mb(total)}** (pages served without a size are not counted).", ""]

    legal = next(s for s in m["sources"] if s["id"] == "S10")
    out += ["## Legal status (S10)", "",
            "Each status was read from the notification itself on 2026-09-25.", "",
            "| Instrument | Status | Basis |", "|---|---|---|"]
    for i in legal.get("instruments", []):
        out.append(f"| {cell(i['instrument'])} | {cell(i['status'])} | {cell(i['basis'])} |")
    out.append("")

    for s in m["sources"]:
        out += [f"## {s['id']} - {s['name']}", "",
                f"- **Publisher:** {s['publisher']}",
                f"- **Landing page:** {s['landing_page']}",
                f"- **Saved to:** `data/{s['target_path']}`",
                f"- **Purpose:** {s['purpose']}",
                f"- **Access:** {ACCESS_LABEL.get(s['access'], s['access'])}"
                + (" - see `MANUAL_STEPS.md`" if s.get("manual") else "")
                + (" (optional)" if s.get("optional") else ""),
                f"- **Licence:** {s['licence']}" + (f" ({s['licence_source']})" if s.get("licence_source") else ""),
                f"- **Redistribution:** {s['redistribution']}"]
        if s.get("pinned_commit"):
            out.append(f"- **Pinned commit:** `{s['pinned_commit']}`")
        out += ["", s.get("notes", "").strip(), ""]
        files = s.get("files") or []
        if files:
            out += ["| File | Status | Size | Accessed | SHA-256 | URL |", "|---|---|---|---|---|---|"]
            for f in files:
                for x in f.get("found_files") or []:      # manual files the user has added
                    out.append(f"| `{x['path']}` | present (manual) | {mb(x['size_bytes'])} | "
                               f"{x['recorded_at'][:10]} | `{x['sha256'][:16]}…` | added by hand |")
                if f.get("found_files"):
                    continue
                if f.get("manual") or not f.get("url"):
                    if s["access"] == "repo":
                        status = "repo"
                    elif f.get("manual") or s.get("manual"):
                        status = "manual"
                    else:
                        status = "not located"
                    url = f.get("manual_hint") or f.get("query") or f.get("target")
                elif f.get("kind") == "api":
                    status, url = "needs key", f["url"]
                else:
                    status = f.get("download_status") or f"probed {f.get('http_status')}"
                    url = f.get("final_url") or f["url"]
                if f.get("download_status") in ("pending-manual", "skipped-manual", "failed"):
                    status = f["download_status"]
                sha = f"`{f['sha256'][:16]}…`" if f.get("sha256") else "-"
                when = (f.get("downloaded_at") or f.get("accessed_at") or "-")[:10]
                out.append(f"| {cell(f['name'])} | {status} | {mb(f.get('size_bytes'))} | "
                           f"{when} | {sha} | {cell(url)} |")
            out.append("")
    (DATA / "SOURCES.md").write_text("\n".join(out), encoding="utf-8", newline="\n")
    print(f"Wrote data/SOURCES.md ({len(m['sources'])} sources, automatic total {mb(total)})")


if __name__ == "__main__":
    main()
