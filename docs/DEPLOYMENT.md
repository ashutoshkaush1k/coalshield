# The online version

A free online copy of the project, for people who cannot see the laptop. **The laptop stays the main
demo and works exactly as before**: everything online is switched on by settings that only the
online platforms have. With none of them set (the laptop), nothing changes.

| Part | Where | Free plan | Deployed from |
|---|---|---|---|
| Dashboard and field app (`/field`) | Vercel (Hobby) | yes | `live-sprint`, on every push |
| API (Yii2, Docker) | Render (free web service), Singapore | yes | `live-sprint`, on every push |
| Database (PostgreSQL + PostGIS) and file storage | Supabase (free), Singapore | yes | loaded from the laptop |
| Scheduled jobs, hourly | GitHub Actions (`.github/workflows/online-jobs.yml`) | yes | runs from `live-sprint` |

Links (fill in once deployed): dashboard `https://<vercel-address>`, field app
`https://<vercel-address>/field`, API `https://<render-service>.onrender.com/v1/health`.

## Online vs laptop

- **Same:** every page, role, scenario and score (100 / 80 / 70 / 60 / 45, 6 / 21 / 47, 83.2), the
  audit chain, the field app (installable, camera, GPS, offline capture and sync), all 6 languages.
- **Data:** the `online` preset - the demo data with 52 days of readings instead of 90, so it fits
  the free database (247 MB). Same mines, scores and scenarios.
- **PPE photo analysis:** laptop only (it needs the AI service). Online, the upload says it is
  "available in the full version". The anomaly detectors and the risk model run in PHP (the same
  algorithms the laptop falls back to).
- **Sign-ins:** online accounts have strong passwords (below); the laptop keeps `demo123` and the
  quick-login buttons. The online login page has no demo buttons and no demo password.
- **Speed:** the free API server sleeps after about 15 minutes without visitors. The first page after
  that shows "Starting server, about 1 minute" and then carries on by itself.

## Which setting goes where

Nothing secret is ever in the repository (it is public). Secrets live only in the platform's settings
and, on the laptop, in two git-ignored files under `deploy\`.

| Setting | Render (API) | Vercel (frontend) | GitHub | Laptop `deploy\online.local.env` |
|---|---|---|---|---|
| Database host, user, password (Supabase **Session pooler**) | `DB_HOST`, `DB_USER`, `DB_PASSWORD` | - | - | same three |
| Supabase project URL, secret (service_role) key | `SUPABASE_URL`, `SUPABASE_SERVICE_KEY` | - | - | same two |
| The dashboard's address | `CORS_ORIGINS` | - | - | `ONLINE_SITE_URL` |
| The API's address + `/v1` | - | `VITE_API_URL` | variable `ONLINE_API_URL` | - |
| Jobs token (a long random secret) | `JOBS_TOKEN` | - | secret `JOBS_TOKEN` | `JOBS_TOKEN` |
| Sign-in signing key | `JWT_SECRET` (Render makes it) | - | - | - |

Fixed, non-secret settings are in the files: `render.yaml`, `deploy/api/Dockerfile` (e.g.
`AI_ENGINE=php`, `VISION_AVAILABLE=0`, `DB_SSLMODE=require`, `DB_PERSISTENT=0`,
`STORAGE_DRIVER=supabase`, `LOG_STDERR=1`) and `frontend/.env.online` (`VITE_WAKE_NOTICE`,
`VITE_SW_SCOPE=/field`). What each switch does: [API_CHANGES.md](API_CHANGES.md), "Online version".

## First-time setup

### 1. Supabase (database and files)

1. Sign in at supabase.com, **New project**, region **Southeast Asia (Singapore)**. Keep the database
   password somewhere safe.
2. **Database > Extensions**: turn on `postgis`, `pg_trgm` and `pgcrypto`.
3. **Storage > New bucket**: name `files`, **private** (public off).
4. **Connect** (top of the project page) > **Session pooler**: note the host, port 5432, user
   (`postgres.<project-ref>`) and database `postgres`. Not "Direct connection" (IPv6 only, Render
   cannot reach it) and not "Transaction pooler".
5. **Project Settings > API**: note the project URL and the secret / `service_role` key.
6. On the laptop, copy `deploy\online.env.example` to `deploy\online.local.env` and fill it in.

### 2. Load the data (from the laptop)

```bat
data\run_data.bat generate online
data\run_data.bat validate online
scripts\online.bat migrate --interactive=0
scripts\online.bat rbac/init
scripts\online.bat online/reset-data
scripts\online.bat audit/verify
```

`online/reset-data` seeds the `online` preset, runs every scheduled job once, then sets the
sign-ins (below). `scripts\online.bat` runs `api\yii` with the settings from
`deploy\online.local.env`; it refuses to run against the laptop's own database.

### 3. Render (API)

1. Sign in at render.com with GitHub. **New > Blueprint**, pick this repository and branch
   `live-sprint`; Render reads `render.yaml` (one free Docker web service in Singapore).
2. It asks for the values marked secret: the database and Supabase values from step 1,
   `CORS_ORIGINS` (the Vercel address, after step 4 - put a placeholder first), `JOBS_TOKEN`.
3. After the first deploy, `https://<service>.onrender.com/v1/health` answers
   `{"status":"ok","database":"ok"}`.

### 4. Vercel (dashboard and field app)

1. Sign in at vercel.com with GitHub. **Add New > Project**, import this repository.
2. **Root Directory**: `frontend`. Vercel reads `frontend/vercel.json` (build `npm run build:online`,
   single-page app routing).
3. **Environment Variables**: `VITE_API_URL` = `https://<service>.onrender.com/v1`. Nothing else;
   in particular no `VITE_DEMO_MODE`.
4. **Settings > Git > Production Branch**: `live-sprint`.
5. Put the Vercel address (e.g. `https://coalshield.vercel.app`, no trailing slash) into Render's
   `CORS_ORIGINS` and into `ONLINE_SITE_URL` in `deploy\online.local.env`.

### 5. GitHub (hourly jobs)

Repository **Settings > Secrets and variables > Actions**: secret `JOBS_TOKEN` (the same value as
in Render) and variable `ONLINE_API_URL` (`https://<service>.onrender.com/v1`). Then **Actions >
Online scheduled jobs > Run workflow** once; it should end green with `"failed":0`.

## Everyday tasks

- **Redeploy:** push to `live-sprint` - Render and Vercel both rebuild. By hand: Render > service >
  **Manual Deploy**; Vercel > project > Deployments > **Redeploy**.
- **Reset the online data** (after a demo, or if someone changed things): `scripts\online.bat
  online/reset-data`. The judges keep their passwords.
- **Supabase paused:** free projects pause after a long quiet spell (the hourly jobs normally
  prevent that). Supabase dashboard > the project > **Restore project**, wait for it, then open the
  site once.
- **Server logs:** Render > service > **Logs**. Scheduled jobs: GitHub > **Actions**.
- **Load times:** the first request after the server slept takes about a minute; after that pages
  answer in well under a second (measured figures: [PERFORMANCE.md](PERFORMANCE.md)).

## Sign-ins

- **Where they are:** `deploy\credentials.local.md` on the laptop, git-ignored. It lists the four
  accounts for judges - Government (DGMS), Corporate (SECL), Mine Head (Bhubaneswari), Inspector -
  and their passwords. Every other online account has a random password nobody knows.
- **Sharing safely:** never put a password in the repository, an issue, a pull request, a commit
  message or a public chat. Send each person only the account they need, in a private message.
  After the event, give the accounts new passwords (below).
- **Change a password yourself:** sign in, **Profile > Change password** (at least 12 characters).
- **Reset one account** (forgotten, or shared too widely): `scripts\online.bat online/reset-password
  <email>`. The new password is written to `deploy\credentials.local.md`.
- **New passwords for all four judge accounts:** `scripts\online.bat online/credentials --new=1`.
- **Sign everyone out:** Render > service > Environment > `JWT_SECRET` > generate a new value, save.
- Failed sign-ins are limited per account and per address, online as on the laptop.

## "Continue as admin (demo)"

The login page offers a passwordless demo sign-in (the Demo Admin (DGMS) account, the regulator's view
of all 74 mines, 30-minute sessions) only while the API has `DEMO_LOGIN_ENABLED=true`: Render > the
service > **Environment**, and `api\.env` on the laptop. To remove it, set it to `false` (or delete it)
and save - Render restarts the API and the button disappears; nothing needs rebuilding. The demo
account can change data like any government account; `scripts\online.bat online/reset-data` puts
the online data back. Sign-ins are limited to 20 per address per hour and each is in the audit trail
("demo login").

## Going back to "pre-deploy"

The git tag `pre-deploy` marks the code before any of this work. The laptop never depends on the
online services, so turning them off needs no code change: suspend the Render service, delete the
Vercel project and pause the Supabase project. To run the laptop on the exact pre-deploy code:

```bat
git switch -c from-pre-deploy pre-deploy
scripts\demo_reset.bat
```

To undo the online work on `live-sprint` itself, revert its commits (`git revert`) rather than
rewriting the branch, so everyone's copies stay consistent.
