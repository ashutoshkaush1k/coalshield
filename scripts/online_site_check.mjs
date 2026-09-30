// End-to-end check of the ONLINE site (docs/DEPLOYMENT.md) in Edge: page load times, errors,
// sideways scroll at phone width, screenshots in docs/screenshots/online/.
//
//   node scripts/online_site_check.mjs https://<vercel-address>            public pages (headless)
//   node scripts/online_site_check.mjs https://<vercel-address> --signed-in
//        opens a visible Edge window at the login page. YOU sign in there (the script never types
//        or reads a password); it then visits the dashboards and a mine page, then opens /field,
//        where you sign in to the field app, and it checks that too.
import { execFileSync, spawn } from "node:child_process";
import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const SITE = (process.argv[2] ?? "").replace(/\/$/, "");
const SIGNED_IN = process.argv.includes("--signed-in");
if (!SITE) { console.error("usage: node scripts/online_site_check.mjs <site-url> [--signed-in]"); process.exit(2); }
const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..");
const OUT = join(ROOT, "docs", "screenshots", "online");
const PORT = 9243;
const EDGE = "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

mkdirSync(OUT, { recursive: true });
const dir = mkdtempSync(join(tmpdir(), "cs-online-"));
const args = [`--remote-debugging-port=${PORT}`, `--user-data-dir=${dir}`, "--no-first-run", "--window-size=1280,860", `${SITE}/login`];
const browser = spawn(EDGE, SIGNED_IN ? args : ["--headless=new", "--hide-scrollbars", ...args], { stdio: "ignore" });

function stop() {
  try { execFileSync("taskkill", ["/pid", String(browser.pid), "/T", "/F"], { stdio: "ignore" }); } catch { /* gone */ }
  const filter = `Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like '*${dir}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`;
  try { execFileSync("powershell", ["-NoProfile", "-Command", filter], { stdio: "ignore" }); } catch { /* none left */ }
}

const failures = [];
const expect = (ok, what) => { console.log(`  ${ok ? "ok  " : "FAIL"} ${what}`); if (!ok) failures.push(what); };

async function connect() {
  let target;
  for (let i = 0; i < 60 && !target; i++) { await sleep(250); target = await fetch(`http://127.0.0.1:${PORT}/json/list`).then((r) => r.json()).then((l) => l.find((t) => t.type === "page")).catch(() => null); }
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener("open", r, { once: true }));
  let id = 0; const pending = new Map(); const errors = []; const failed = [];
  ws.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    if (m.method === "Runtime.exceptionThrown") errors.push(m.params.exceptionDetails.exception?.description ?? m.params.exceptionDetails.text);
    if (m.method === "Network.responseReceived" && m.params.response.status >= 500) failed.push(`${m.params.response.status} ${m.params.response.url}`);
  });
  const send = (method, params = {}) => new Promise((res) => { const i = ++id; pending.set(i, res); ws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async (expression) => (await send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true })).result?.result?.value;
  await send("Page.enable"); await send("Runtime.enable"); await send("Network.enable");
  return { ws, send, ev, errors, failed };
}

/**
 * Navigate and time until the page is usable: its content is drawn, no loading indicator is left,
 * and the API calls it started while loading have answered (the dashboards keep polling after
 * that, so "the network is quiet" never happens). Measured in the page: navigation start -> ready.
 */
async function load(p, path, ready = "main, .field-app, form", needsApi = SIGNED_IN_PAGE) {
  await p.send("Page.navigate", { url: `${SITE}${path}` });
  await sleep(300);
  // Retried: an evaluation that starts while the old page is being replaced returns nothing.
  const readyScript = `new Promise((done) => {
    const start = performance.now();
    const tick = () => {
      const answered = performance.getEntriesByType("resource").some((r) => r.name.includes("/v1/") && !r.name.includes("/health"));
      const drawn = !!document.querySelector(${JSON.stringify(ready)}) && !document.querySelector(".loader, .skeleton, [aria-busy=true], .spinner");
      const text = (document.querySelector("main")?.innerText ?? document.body.innerText).trim().length > 40;
      if ((drawn && text && (!${needsApi} || answered)) || performance.now() - start > 90000) return done(Math.round(performance.now()));
      setTimeout(tick, 50);
    };
    tick();
  })`;
  let ms;
  for (let attempt = 0; attempt < 5 && typeof ms !== "number"; attempt++) {
    ms = await p.ev(readyScript).catch(() => undefined);
    if (typeof ms !== "number") await sleep(500);
  }
  const api = await p.ev(`performance.getEntriesByType("resource").filter((r) => r.name.includes("/v1/")).map((r) => Math.round(r.duration))`);
  return { ms, api: api ?? [] };
}

async function shot(p, name) {
  const { result } = await p.send("Page.captureScreenshot", { format: "png" });
  writeFileSync(join(OUT, `${name}.png`), Buffer.from(result.data, "base64"));
}

let SIGNED_IN_PAGE = false;   // set once signed in: a page is ready only when its data has arrived

const sideways = (p) => p.ev("document.documentElement.scrollWidth > document.documentElement.clientWidth + 1");

async function phone(p, on) {
  await p.send("Emulation.setDeviceMetricsOverride", on ? { width: 390, height: 844, deviceScaleFactor: 2, mobile: true } : { width: 1280, height: 860, deviceScaleFactor: 1, mobile: false });
}

async function publicPages(p) {
  console.log("public pages");
  for (const [name, path, ready] of [["login", "/login", "form"], ["grievance", "/grievance", "main, form"], ["field-login", "/field", "form, .field-app"]]) {
    for (const mobile of [false, true]) {
      await phone(p, mobile);
      const r = await load(p, path, ready);
      const label = `${name} ${mobile ? "390 px" : "1280 px"}`;
      console.log(`  ${label}: ${(r.ms / 1000).toFixed(1)} s`);
      expect(!(await sideways(p)), `${label}: no sideways scroll`);
      await shot(p, `${name}-${mobile ? "phone" : "desktop"}`);
    }
  }
  await phone(p, false);
  await load(p, "/login", "form");
  expect((await p.ev("document.querySelectorAll('.demo-accounts, [data-demo]').length")) === 0, "login: no demo buttons");
  expect((await p.ev("document.getElementById('password').value")) === "", "login: no password filled in");
}

async function waitForSignIn(p, where) {
  console.log(`\n>>> Sign in ${where} in the Edge window. Waiting (up to 15 minutes)...`);
  for (let i = 0; i < 1800; i++) {
    const path = await p.ev("location.pathname").catch(() => "");
    const signedIn = where.includes("field") ? await p.ev("!!document.querySelector('.field-app .field-header') && !document.querySelector('#field-password')") : path && path !== "/login";
    if (signedIn) return true;
    await sleep(500);
  }
  return false;
}

async function signedInPages(p) {
  await p.send("Page.navigate", { url: `${SITE}/login` });
  if (!(await waitForSignIn(p, "to the dashboard"))) { expect(false, "signed in within 15 minutes"); return; }
  await sleep(1500);
  SIGNED_IN_PAGE = true;
  const home = await p.ev("location.pathname");
  const role = await p.ev("JSON.parse(sessionStorage.getItem('smg.user') || localStorage.getItem('smg.user') || 'null')?.role ?? document.body.dataset.role ?? ''");
  console.log(`signed in; home ${home}${role ? ` (${role})` : ""}`);
    // The tabs (top bar and drawer) are buttons that open <home>?tab=<id>.
  const tabs = await p.ev(`[...new Set([...document.querySelectorAll('[data-tab]')].map((b) => b.dataset.tab))].filter((id) => id !== 'profile' && id !== 'overview')`);
  const pages = [home, ...(tabs ?? []).map((id) => `${home}?tab=${id}`), "/profile"];
  for (const path of pages) {
    for (const mobile of [false, true]) {
      await phone(p, mobile);
      const r = await load(p, path);
      const label = `${path} ${mobile ? "390 px" : "1280 px"}`;
      if (typeof r.ms !== "number" || r.ms >= 90000) expect(false, `${label}: ready within 90 s`);
      const api = r.api.length ? `; API calls median ${r.api.sort((a, b) => a - b)[Math.floor(r.api.length / 2)]} ms, slowest ${Math.max(...r.api)} ms` : "";
      console.log(`  ${label}: ${(r.ms / 1000).toFixed(1)} s${api}`);
      expect(!(await sideways(p)), `${label}: no sideways scroll`);
      expect(!(await p.ev("!!document.querySelector('.notice.error')")), `${label}: no error notice`);
      await shot(p, `signed-in${path.replace(/\//g, "-")}-${mobile ? "phone" : "desktop"}`);
    }
  }
  // A mine detail page: the first mine link on the home page.
  await phone(p, false);
  await load(p, home);
  // Bhubaneswari (mine 5, score 45) - the government mine page; a mine head's home is its own mine.
  const mine = home.startsWith("/gov") ? "/gov/mines/5" : null;
  if (mine) {
    for (const mobile of [false, true]) {
      await phone(p, mobile);
      const r = await load(p, mine);
      console.log(`  mine detail ${mine} ${mobile ? "390 px" : "1280 px"}: ${(r.ms / 1000).toFixed(1)} s`);
      expect(!(await p.ev("!!document.querySelector('.notice.error')")), `mine detail ${mine}: no error notice`);
      expect(!(await sideways(p)), `mine detail ${mine} ${mobile ? "phone" : "desktop"}: no sideways scroll`);
      await shot(p, `mine-detail-${mobile ? "phone" : "desktop"}`);
    }
  } else {
    console.log("  (no mine link on this account's home page - a mine head's home is its mine)");
  }
  // Field app
  await phone(p, true);
  await p.send("Page.navigate", { url: `${SITE}/field` });
  await sleep(2500);
  for (let i = 0; i < 20 && !(await p.ev("!!document.querySelector('#field-password, .field-header')")); i++) await sleep(500);
  if (await p.ev("!!document.querySelector('#field-password')")) {
    if (!(await waitForSignIn(p, "to the field app (an Inspector or Mine Head account)"))) { expect(false, "field app sign-in within 15 minutes"); return; }
  }
  await sleep(3000);
  const field = await p.ev(`(async () => ({ sw: (await navigator.serviceWorker.getRegistrations()).map((r) => r.scope), text: document.querySelector('.field-app')?.innerText.slice(0, 200) }))()`);
  console.log(`  field app: service worker ${JSON.stringify(field.sw)}`);
  expect(field.sw.some((s) => s.endsWith("/field")), "field app: service worker scoped to /field");
  expect(!(await sideways(p)), "field app 390 px: no sideways scroll");
  await shot(p, "field-signed-in-phone");
}

try {
  const p = await connect();
  await publicPages(p);
  if (SIGNED_IN) await signedInPages(p);
  console.log(`\n${failures.length} failed check(s), ${p.errors.length} page error(s), ${p.failed.length} server error(s)`);
  for (const e of [...p.errors, ...p.failed]) console.log(`  ${e.split("\n")[0]}`);
  p.ws.close();
  if (failures.length || p.errors.length || p.failed.length) process.exitCode = 1;
} finally { stop(); }
