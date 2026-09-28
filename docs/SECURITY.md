# Security review (Phase 8, 2026-09-29)

What was checked, what was found, and what changed. Scope: the demo deployment - one Windows laptop,
the dashboards on that laptop, phones on the same Wi-Fi through the field server. Re-run the checks
with the commands in each section.

## Summary

| Area | Result | Change |
|---|---|---|
| PHP dependencies (`composer audit`) | no advisories | - |
| JavaScript dependencies (`npm audit`) | **1 high** (Vite dev server: path traversal, `fs.deny` bypass on Windows), 3 moderate | **Vite 5.4 -> 7.3.6** (fixes the high and the esbuild moderate); 2 moderate left (React Router), assessed below |
| Python dependencies (`pip-audit`, ai-service and data environments) | no known vulnerabilities | - |
| Exception details in error answers | with `YII_DEBUG=1` the class and message were sent (a database error's message can quote SQL) | **off unless `API_DEBUG_ERRORS=1`**; never a stack trace |
| Security headers | none on API answers; `X-Powered-By: PHP/8.2.12` sent | **nosniff, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, CSP and `no-store` on JSON; PHP version removed**; the field server sends the same plus a CSP for the app |
| Sign-in brute force | no limit | **10 failures per account and 50 per address per 15 min -> 429** (with `Retry-After`) |
| Client address behind the field server | every phone looked like 127.0.0.1 to the rate limits | `X-Forwarded-For` trusted **only** from a loopback peer (the field server) |
| Upload limits | PHP accepted 40 MB | **12 MB** in the API's own `php.ini` (API limit 10 MB; photos 5 MB; grievance files 5 MB) |
| CORS | explicit list of origins, no credentials | - (verified) |
| JWT secret | 64 hex characters (256 bits), HS256 | setup now generates it with a cryptographic generator (was not automated before) |
| Secrets and keys in git | none tracked, none in history | `certs/` added to `.gitignore` in Phase 7B (verified again before push) |

## Dependencies

```bat
cd api && D:\tools\composer\composer.bat audit
cd frontend && npm audit
```

pip-audit ran from a throwaway environment against each environment's frozen package list, so the
project environments were not changed:

```bat
ai-service\.venv\Scripts\python.exe -m pip freeze > ai.txt
<pip-audit> --no-deps --disable-pip -r ai.txt
```

**Remaining moderate: React Router 6 (GHSA-wrjc-x8rr-h8h6, GHSA-337j-9hxr-rhxg).** The first is an open
redirect when an attacker-controlled path reaches `<Link>` or `navigate()`; every target in this app
is a constant or built from ids the API returned (checked: `grep` of `navigate(` and `<Link`). The
second concerns server-side rendering hydration, which this app does not use. The fix is React
Router 7, a breaking upgrade; planned, not done in this phase.

## The API

- **Authentication**: JWT (HS256, `bizley/jwt`, 12 h), secret only in `api\.env`; the library refuses
  keys under 256 bits. Passwords: bcrypt (cost 10).
- **Authorisation**: RBAC permission per action, scoping in one query class, 404 for another mine's
  records. `docs/API.md` lists every endpoint with its check; `ApiDocTest` fails if an endpoint that
  needs a token checks nothing (only `/v1/system/status` is open to every signed-in account).
- **Rate limits** (`components/RateLimiter.php`, a table, so they survive restarts): sign-in failures
  (above), public grievance submission (5 per hour per address), tracking (20 per minute), the public
  mine list (60 per minute).
- **Uploads** (`components/FileStorage.php`): type decided from the content (`finfo`), not the name;
  PDF, JPEG, PNG and WebP only; stored outside the web root under a SHA-256 name; served only through
  signed, expiring links (HMAC with a key derived from `JWT_SECRET`).
- **Errors**: one JSON shape, codes only; no stack trace ever. Exception class and message only with
  `YII_DEBUG=1` **and** `API_DEBUG_ERRORS=1` - keep the latter off on any network.
- **Headers** (`components/SecurityHeaders.php`): see the summary. Stored files keep no CSP so a PDF
  or photo still opens in the browser.
- **Network exposure**: Apache listens on 127.0.0.1 only. Phones reach the API only through the field
  server, which serves static files and forwards `/v1`.
- **SQL**: parameterised everywhere (Yii query builder and bound parameters); no string-built SQL from
  request input.

## The field server and the phones

- HTTPS with a local CA made on the laptop (`scripts/make_cert.mjs`); the CA key stays in `certs\`
  (git-ignored) and signs only this server's certificate. Remove the CA from a phone after the demo
  (docs/FIELD_APP_SETUP.md).
- Static files only from `frontend/dist` (path traversal rejected), `/v1` forwarded as is.
- Headers: nosniff, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` and a CSP: scripts and
  connections to this origin only, images also from the OpenStreetMap tile servers, inline style
  attributes allowed (React and Leaflet set them).
- On the phone: the token is kept encrypted at rest (AES-GCM with a non-extractable key); that
  protects a copied storage folder, not an unlocked phone in someone else's hands.
- No HSTS: the certificate is for a LAN address that changes between networks.

## Not done (known, accepted for the demo)

- React Router 7 upgrade (the two moderate advisories above).
- The Vite dev server (port 5173) is a development server; for anything beyond the demo, serve the
  built app (as the field server does) instead.
- `YII_DEBUG=1` stays in the development `api\.env`; it no longer exposes details by itself.
- No account lockout notification or MFA; demo accounts share one demo password by design (demo-only).

## Before pushing

```bat
git ls-files | findstr /r /i "\.env$ \.key$ \.pem$ \.crt$ \.pt$ \.joblib$ secret"
git check-ignore -v api/.env frontend/.env scripts/.simulator.key certs/ca.key
```

The first must print nothing (tracked `.env.example` and `.env.field` files hold no secret); the
second must show every file ignored.
