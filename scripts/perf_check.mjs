// Dashboard request timing (docs/PERFORMANCE.md).
//
//   node scripts/perf_check.mjs [legacy|aggregated] [cycles]      (default aggregated, 12 cycles)
//
// For each dashboard view it replays one polling cycle - the requests that screen makes on every
// refresh, fired together the way the browser does - `cycles` times, first with one client, then
// with a government and a mine-head client polling side by side. It prints the median and p95 of
// every request and of the whole cycle. `legacy` replays the requests the screens made before the
// aggregated view endpoints; `aggregated` the ones they make now.
//
// Needs the API on 8080 and a freshly seeded demo database. Read-only.
const API = process.env.API_URL ?? "http://127.0.0.1:8080/v1";
const MODE = process.argv[2] ?? "aggregated";
const CYCLES = Number(process.argv[3] ?? 12);
const WARMUP = 2;
const GOV = "gov@dgms.gov.in";
const CORP = "corporate.secl@coalmine.in";
const HEAD = "head.od-tlc-05@coalmine.in";   // Bhubaneswari, mine 5
const MINE = 5;

const VIEWS = {
  legacy: {
    "government overview": { who: GOV, paths: ["/dashboard", "/contractors/summary"] },
    "government mine detail": { who: GOV, paths: [
      `/mines/${MINE}`, `/sensors/${MINE}/trend?points=40`, `/violations?mine_id=${MINE}&per_page=50`,
      `/alerts?mine_id=${MINE}&per_page=30`, `/audit?mine_id=${MINE}&per_page=40`,
      `/corrective-actions?mine_id=${MINE}&per_page=50`, `/incidents?mine_id=${MINE}&per_page=50`] },
    "mine head dashboard": { who: HEAD, paths: [
      `/mines/${MINE}`, `/sensors/${MINE}/trend?points=40`, `/violations?mine_id=${MINE}&per_page=50`,
      `/alerts?mine_id=${MINE}&per_page=30`, `/audit?mine_id=${MINE}&per_page=40`,
      `/corrective-actions?mine_id=${MINE}&per_page=50`, `/incidents?mine_id=${MINE}&per_page=50`] },
  },
  aggregated: {
    "government overview": { who: GOV, paths: ["/views/overview"] },
    "government mine detail": { who: GOV, paths: [`/views/mine/${MINE}`] },
    "mine head dashboard": { who: HEAD, paths: [`/views/mine/${MINE}`] },
    "government production": { who: GOV, paths: ["/views/production-overview"] },
    "mine head production": { who: HEAD, paths: ["/views/production"] },
    "government grievances": { who: GOV, paths: ["/views/grievances"] },
    "mine head grievances": { who: HEAD, paths: ["/views/grievances"] },
    "government obligations": { who: GOV, paths: ["/views/obligations"] },
    "corporate obligations": { who: CORP, paths: ["/views/obligations"] },
    "mine head obligations": { who: HEAD, paths: ["/views/obligations"] },
    "government map": { who: GOV, paths: ["/views/map"] },
    "mine head map": { who: HEAD, paths: ["/views/map"] },
    // Phase 7: the Risk Ranking tab (mines by Governance Risk Index + patterns; endpoint /views/priority); the mine views above now carry `risk`
    "government priority": { who: GOV, paths: ["/views/priority"] },
    // The overview's sensor panel polls the fleet standing (it read every reading until 2026-09-30).
    "government sensor fleet": { who: GOV, paths: ["/sensors"] },
    "corporate priority": { who: CORP, paths: ["/views/priority"] },
    // Fetched once when the map opens (then revalidated by ETag), not polled.
    "map outlines, first load": { who: GOV, paths: ["/geo/states", "/geo/districts"] },
  },
};

async function login(email) {
  const r = await fetch(`${API}/auth/login`, {
    method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: "demo123" }),   // demo-only accounts
  });
  if (!r.ok) throw new Error(`login ${email}: ${r.status}`);
  return (await r.json()).access_token;
}

async function timed(path, token) {
  const t0 = performance.now();
  const r = await fetch(API + path, { headers: { Authorization: `Bearer ${token}` } });
  await r.arrayBuffer();
  if (!r.ok) throw new Error(`${path}: ${r.status}`);
  return performance.now() - t0;
}

/** One polling cycle: every request of the view at once; per-request and wall-clock times. */
async function cycle(view, token) {
  const t0 = performance.now();
  const times = await Promise.all(view.paths.map((p) => timed(p, token)));
  return { times, wall: performance.now() - t0 };
}

const pct = (xs, p) => { const s = [...xs].sort((a, b) => a - b); return s[Math.min(s.length - 1, Math.floor(p * s.length))]; };
const fmt = (x) => `${Math.round(x)}`.padStart(5);

function report(label, view, runs) {
  console.log(`\n${label}  (${view.paths.length} request(s) per cycle, ${runs.length} cycles)`);
  console.log("   median    p95  request");
  view.paths.forEach((p, i) => {
    const xs = runs.map((r) => r.times[i]);
    console.log(`  ${fmt(pct(xs, 0.5))}  ${fmt(pct(xs, 0.95))}  ${p}`);
  });
  const walls = runs.map((r) => r.wall);
  console.log(`  ${fmt(pct(walls, 0.5))}  ${fmt(pct(walls, 0.95))}  whole cycle (ms)`);
  return { median: pct(walls, 0.5), p95: pct(walls, 0.95) };
}

async function loop(view, token) {
  const runs = [];
  for (let i = 0; i < CYCLES + WARMUP; i++) {
    const r = await cycle(view, token);
    if (i >= WARMUP) runs.push(r);
  }
  return runs;
}

async function main() {
  const views = VIEWS[MODE];
  if (!views) throw new Error(`mode is legacy or aggregated, not ${MODE}`);
  const tokens = { [GOV]: await login(GOV), [HEAD]: await login(HEAD), [CORP]: await login(CORP) };
  console.log(`API ${API}, mode ${MODE}`);

  console.log("\n== one client ==");
  for (const [name, view] of Object.entries(views)) report(name, view, await loop(view, tokens[view.who]));

  console.log("\n== government overview and mine head dashboard polling side by side ==");
  const a = views["government overview"], b = views["mine head dashboard"];
  const [ra, rb] = await Promise.all([loop(a, tokens[a.who]), loop(b, tokens[b.who])]);
  report("government overview (with mine head open)", a, ra);
  report("mine head dashboard (with government open)", b, rb);
}

main().catch((e) => { console.error(e.message); process.exit(1); });
