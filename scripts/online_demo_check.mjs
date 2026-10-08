// The online "Continue as admin (demo)" flow, end to end in headless Edge (no password involved):
// login page -> button -> "Demo access" popup -> Continue to dashboard -> government overview with
// the "Demo access" badge, at 1280 px and 390 px. Times each step (the first includes waking the
// free API server). Screenshots in docs/screenshots/online/demo-*.png.
//
//   node scripts/online_demo_check.mjs https://coalshield.vercel.app
//   node scripts/online_demo_check.mjs https://coalshield.vercel.app --idle=17
//        desktop only: opens the login page, then waits that many minutes with no requests (the
//        free server falls asleep after 15) before Continue - the popup must say the server is
//        starting and still open the dashboard
import { execFileSync, spawn } from "node:child_process";
import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const SITE = (process.argv[2] ?? "https://coalshield.vercel.app").replace(/\/$/, "");
const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..");
const OUT = join(ROOT, "docs", "screenshots", "online");
const PORT = 9246;
const IDLE_MIN = Number((process.argv.find((a) => a.startsWith("--idle=")) ?? "--idle=0").slice(7));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
mkdirSync(OUT, { recursive: true });

const dir = mkdtempSync(join(tmpdir(), "cs-demo-"));
const browser = spawn("C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
  ["--headless=new", "--hide-scrollbars", `--remote-debugging-port=${PORT}`, `--user-data-dir=${dir}`, "about:blank"], { stdio: "ignore" });
// Edge's launcher hands off to another process and exits: end every process using this run's profile.
const stop = () => {
  try { execFileSync("taskkill", ["/pid", String(browser.pid), "/T", "/F"], { stdio: "ignore" }); } catch { /* gone */ }
  const filter = `Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like '*${dir}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`;
  try { execFileSync("powershell", ["-NoProfile", "-Command", filter], { stdio: "ignore" }); } catch { /* none left */ }
};

const failures = [];
const expect = (ok, what) => { console.log(`  ${ok ? "ok  " : "FAIL"} ${what}`); if (!ok) failures.push(what); };

try {
  let target;
  for (let i = 0; i < 40 && !target; i++) { await sleep(250); target = await fetch(`http://127.0.0.1:${PORT}/json/list`).then((r) => r.json()).then((l) => l.find((t) => t.type === "page")).catch(() => null); }
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener("open", r, { once: true }));
  let id = 0; const pending = new Map(); const errors = [];
  ws.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    if (m.method === "Runtime.exceptionThrown") errors.push(m.params.exceptionDetails.exception?.description ?? m.params.exceptionDetails.text);
  });
  const send = (method, params = {}) => new Promise((res) => { const i = ++id; pending.set(i, (m) => res(m.result)); ws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async (expression) => (await send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true }))?.result?.value;
  const until = async (expr, seconds) => { const t0 = Date.now(); while (Date.now() - t0 < seconds * 1000) { if (await ev(expr)) return (Date.now() - t0) / 1000; await sleep(200); } return null; };
  const shot = async (name) => { const r = await send("Page.captureScreenshot", { format: "png" }); writeFileSync(join(OUT, `${name}.png`), Buffer.from(r.data, "base64")); };
  await send("Page.enable"); await send("Runtime.enable");

  const sizes = [["1280 px", 1280, 860, false], ["390 px", 390, 844, true]];
  for (const [label, width, height, mobile] of IDLE_MIN ? sizes.slice(0, 1) : sizes) {
    console.log(`\n${label}`);
    await send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: mobile ? 2 : 1, mobile });
    await send("Page.navigate", { url: `${SITE}/login` });
    await sleep(500);
    await ev("sessionStorage.clear(); localStorage.setItem('smg.lang', 'en'); true");
    await send("Page.navigate", { url: `${SITE}/login` });
    const tButton = await until("!!document.getElementById('demo-login')", 120);
    expect(tButton !== null, `"Continue as admin (demo)" shown (after ${tButton?.toFixed(1)} s; includes waking the API)`);
    if (tButton === null) continue;
    if (IDLE_MIN) {
      console.log(`  waiting ${IDLE_MIN} minutes with no requests, so the free server falls asleep...`);
      await sleep(IDLE_MIN * 60000);
    }
    await ev("document.getElementById('demo-login').scrollIntoView({ block: 'center' }); true");
    await shot(`demo-1-button-${mobile ? "phone" : "desktop"}${IDLE_MIN ? "-after-sleep" : ""}`);
    await ev("document.getElementById('demo-login').click(); true");
    await sleep(700);
    const popup = await ev("({ title: document.querySelector('.overlay-head h2')?.textContent, text: document.querySelector('.demo-text')?.textContent, buttons: [...document.querySelectorAll('.overlay-foot button')].map((b) => b.textContent) })");
    expect(popup?.title === "Demo access", `popup title "${popup?.title}"`);
    expect(popup?.text?.startsWith("This admin login is only for demonstration purposes"), "popup text");
    expect(JSON.stringify(popup?.buttons) === JSON.stringify(["Cancel", "Continue to dashboard"]), `popup buttons ${JSON.stringify(popup?.buttons)}`);
    await shot(`demo-2-popup-${mobile ? "phone" : "desktop"}${IDLE_MIN ? "-after-sleep" : ""}`);
    const t0 = Date.now();
    await ev("document.getElementById('demo-continue').click(); true");
    const sawWaking = await until("!!document.querySelector('.demo-waking') || location.pathname === '/gov'", 10).then(() => ev("!!document.querySelector('.demo-waking')"));
    if (sawWaking) await shot(`demo-2b-starting-server-${mobile ? "phone" : "desktop"}`);
    if (IDLE_MIN) expect(sawWaking, 'after sleeping: the popup says "Starting server, this can take up to a minute..."');
    const tGov = await until("location.pathname === '/gov' && !!document.getElementById('demo-badge')", 120);
    expect(tGov !== null, `signed in: government overview with the "Demo access" badge (${((Date.now() - t0) / 1000).toFixed(1)} s)${sawWaking ? "; the popup said the server was starting" : ""}`);
    const tData = await until("/83\\.2/.test(document.body.innerText)", 120);
    expect(tData !== null, `overview data shown (national average 83.2) ${((Date.now() - t0) / 1000).toFixed(1)} s after Continue`);
    expect(!(await ev("document.documentElement.scrollWidth > innerWidth")), "no sideways scroll");
    await ev("window.scrollTo(0, 0); true");
    await shot(`demo-3-dashboard-${mobile ? "phone" : "desktop"}${IDLE_MIN ? "-after-sleep" : ""}`);
  }
  console.log(`\n${failures.length} failed check(s), ${errors.length} page error(s)`);
  errors.forEach((e) => console.log(`  ${e.split("\n")[0]}`));
  ws.close();
  if (failures.length || errors.length) process.exitCode = 1;
} finally { stop(); }
