# data/raw — downloaded source files

One subfolder per source ID from `data/sources.yaml` (for example `raw/datameet/`, `raw/msha/`).

- **Not committed.** Everything here except this file, `.gitkeep`, and each source folder's own
  `README.md` is gitignored. Some sources do not allow redistribution; their terms are recorded in
  `data/SOURCES.md`.
- **Rebuilt, not edited.** `data\run_data.bat download` fetches every non-manual source into its
  folder, skipping files already present with a matching SHA-256, and writes that folder's
  `README.md` (what, from where, when, licence, checksum).
- **Manual sources** (sign-up forms, API keys) are listed in `data/MANUAL_STEPS.md`, with the
  folder to drop each file into and the fallback used when it is absent.
- **Budget:** keep this folder under ~5 GB. Large optional sources are skipped when space is short.
