// Measures the online version's response times (docs/DEPLOYMENT.md, docs/PERFORMANCE.md): the
// API's wake-up after sleeping, then each main dashboard request (5 runs each, median and worst),
// signed in as the Government judge account from deploy\credentials.local.md. Prints no password.
//
//   node scripts/online_timing.mjs https://<service>.onrender.com/v1 [https://<vercel-address>]
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const API = (process.argv[2] ?? "").replace(/\/$/, "");
const SITE = (process.argv[3] ?? "").replace(/\/$/, "");
if (!API) { console.error("usage: node scripts/online_timing.mjs <api-url-with-/v1> [site-url]"); process.exit(2); }
const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..");
const EMAIL = "gov@dgms.gov.in";

function password() {
  const md = readFileSync(resolve(ROOT, "deploy", "credentials.local.md"), "utf8");
  const row = md.split(/\r?\n/).map((l) => l.split("|").map((c) => c.trim())).find((c) => c[2] === EMAIL);
  if (!row) throw new Error(`${EMAIL} not in deploy/credentials.local.md`);
  return row[3].replace(/`/g, "");
}

async function timed(url, init = {}) {
  const t = performance.now();
  const r = await fetch(url, init);
  const body = await r.arrayBuffer();
  return { ms: performance.now() - t, status: r.status, bytes: body.byteLength, body };
}

const median = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length / 2)];

const wake = await timed(`${API}/health`);
console.log(`first /health (includes any wake-up): ${(wake.ms / 1000).toFixed(1)} s, HTTP ${wake.status}`);
const warmHealth = [];
for (let i = 0; i < 5; i++) warmHealth.push((await timed(`${API}/health`)).ms);
console.log(`/health warm: median ${median(warmHealth).toFixed(0)} ms, worst ${Math.max(...warmHealth).toFixed(0)} ms`);

const login = await timed(`${API}/auth/login`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ email: EMAIL, password: password() }) });
if (login.status !== 200) throw new Error(`sign-in failed: HTTP ${login.status}`);
const token = JSON.parse(Buffer.from(login.body).toString()).access_token;
console.log(`sign-in: ${login.ms.toFixed(0)} ms`);

const auth = { headers: { Authorization: `Bearer ${token}` } };
const paths = ["/users/me", "/dashboard", "/mines?per_page=100", "/inspections/priority", "/sensors", "/views/overview", "/obligations/summary", "/alerts?per_page=20", "/grievances?per_page=20", "/obligation-tasks?per_page=20", "/system/status"];
const rows = [];
for (const p of paths) {
  const runs = [];
  let status = 0; let bytes = 0;
  for (let i = 0; i < 5; i++) { const r = await timed(`${API}${p}`, auth); runs.push(r.ms); status = r.status; bytes = r.bytes; }
  rows.push({ path: p, status, kb: (bytes / 1024).toFixed(0), first: runs[0].toFixed(0), median: median(runs).toFixed(0), worst: Math.max(...runs).toFixed(0) });
}
console.table(rows);

if (SITE) {
  for (const p of ["/", "/field", "/sw.js", "/manifest.webmanifest"]) {
    const r = await timed(`${SITE}${p}`);
    console.log(`site ${p}: HTTP ${r.status}, ${(r.bytes / 1024).toFixed(0)} KB, ${r.ms.toFixed(0)} ms`);
  }
}
