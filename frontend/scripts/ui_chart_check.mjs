// "Compliance by mine" chart check: identical tracks, the readout inside each track, names under
// their bars, horizontal scroll inside the card with a state selected; the overflow probe on the
// government overview at 1366 and 360 px in English and Hindi; screenshots in docs/screenshots/ui-chart/.
//
//   node frontend/scripts/ui_chart_check.mjs [--strict]     (needs the stack running: run_all.bat)
import { execFileSync, spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-chart");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9226;
const GOV = "gov@dgms.gov.in";
const STATE = "Jharkhand";   // the state with the most mines: the chart has to scroll
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const source = readFileSync(join(ROOT, "scripts", "browser_check.mjs"), "utf8");
const OVERFLOW_PROBE = new Function(`return \`${source.match(/const OVERFLOW_PROBE = `([\s\S]*?)`;\r?\n/)[1]}\`;`)();

// Every track the same box; every readout inside its track; every name column under its bar.
const GEOMETRY = `(() => {
  const cores = [...document.querySelectorAll(".board .core")];
  const r = (el) => el.getBoundingClientRect();
  const w = new Set(cores.map((c) => Math.round(r(c).width))), h = new Set(cores.map((c) => Math.round(r(c).height)));
  const tops = new Set(cores.map((c) => Math.round(r(c).top)));
  const outside = cores.filter((c) => { const t = r(c), o = r(c.querySelector(".core-readout")); return o.top < t.top - 0.5 || o.bottom > t.bottom + 0.5 || o.left < t.left - 0.5 || o.right > t.right + 0.5; }).length;
  const misaligned = cores.filter((c) => Math.abs(r(c).left - r(c.parentElement.querySelector(".core-name")).left) > 0.5).length;
  const scroll = document.querySelector(".board-scroll");
  return { bars: cores.length, widths: [...w], heights: [...h], tops: [...tops], outside, misaligned,
           scrolls: scroll.scrollWidth > scroll.clientWidth + 1, pageSideways: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1 };
})()`;

async function login(email) {
  const r = await fetch(`${API}/auth/login`, { method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: "demo123" }) });   // demo-only accounts
  return (await r.json()).access_token;
}
const me = async (token, body) => (await fetch(`${API}/users/me`, { method: body ? "PATCH" : "GET",
  headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" }, body: body && JSON.stringify(body) })).json();

function connect(ws) {
  let id = 0; const pending = new Map(); const errors = [];
  ws.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    if (m.method === "Runtime.exceptionThrown") errors.push(m.params.exceptionDetails.text);
  });
  const send = (method, params = {}) => new Promise((res, rej) => { const i = ++id; pending.set(i, (m) => (m.error ? rej(new Error(m.error.message)) : res(m.result))); ws.send(JSON.stringify({ id: i, method, params })); });
  const evaluate = async (expression) => {
    const r = await send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + " in " + expression.slice(0, 80));
    return r.result.value;
  };
  return { send, evaluate, errors };
}

async function run(p) {
  const findings = {}; const failures = [];
  const expect = (ok, what) => { console.log(`  ${ok ? "ok  " : "FAIL"} ${what}`); if (!ok) failures.push(what); };
  const width = (w) => p.send("Emulation.setDeviceMetricsOverride", { width: w, height: w < 768 ? 800 : 900, deviceScaleFactor: 1, mobile: w < 768 });
  const waitFor = async (sel) => { for (let i = 0; i < 60; i++) { if (await p.evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return; await sleep(300); } throw new Error(`no ${sel}`); };
  const chart = () => p.evaluate(`(() => { const b = document.querySelector(".board").closest(".panel-block"); b.scrollIntoView({ block: "start" }); const r = b.getBoundingClientRect(); return { x: r.left, y: r.top + scrollY, width: r.width, height: r.height }; })()`);
  const shot = async (name) => {
    const box = await chart(); await sleep(400);
    const { data } = await p.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true, clip: { ...box, scale: 1 } });
    writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, "base64")); console.log(`  saved ${name}.png`);
  };
  const selectState = async (state) => {
    await p.evaluate(`(() => { const el = document.querySelector("#panel-overview select");
      Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, "value").set.call(el, ${JSON.stringify(state)});
      el.dispatchEvent(new Event("change", { bubbles: true })); })()`);
    for (let i = 0; i < 40; i++) { if (await p.evaluate(`document.querySelectorAll(".board .core").length > 10`)) break; await sleep(300); }
    await sleep(800);
  };
  const open = async (lang) => {
    const token = await login(GOV);
    await me(token, { preferred_language: lang });
    await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(1000);
    await p.evaluate(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", ${JSON.stringify(lang)})`);
    await p.send("Page.navigate", { url: `${APP}/gov` }); await sleep(1500);
    await waitFor(".board .core"); await p.evaluate("document.fonts.ready.then(() => true)"); await sleep(600);
  };
  const saved = (await me(await login(GOV))).preferred_language;
  try {
    for (const w of [1366, 360]) {
      await width(w);
      await open("en");
      for (const [label, state] of [["all states", null], [STATE, STATE]]) {
        if (state) await selectState(state);
        const g = await p.evaluate(GEOMETRY);
        console.log(`${w} px, ${label}: ${g.bars} bars, width ${g.widths}, height ${g.heights}, scrolls ${g.scrolls}`);
        expect(g.widths.length === 1 && g.heights.length === 1 && g.tops.length === 1, "every track the same width, height and top");
        expect(g.outside === 0, "every score and band inside its track");
        expect(g.misaligned === 0, "names aligned under their bars");
        expect(!g.pageSideways, "the page itself never scrolls sideways");
        if (state) expect(g.scrolls, "many bars: the chart scrolls inside the card");
        if (w === 1366) await shot(state ? "chart-state-selected" : "chart-all-states");
      }
    }
    console.log("Overflow probe: government overview, en/hi, 1366/360 px, all states and one state");
    for (const lang of ["en", "hi"]) {
      for (const w of [1366, 360]) {
        await width(w); await open(lang);
        const all = await p.evaluate(OVERFLOW_PROBE);
        await selectState(STATE);
        const one = await p.evaluate(OVERFLOW_PROBE);
        if (all.length) findings[`${lang}-${w}-all`] = all;
        if (one.length) findings[`${lang}-${w}-${STATE}`] = one;
        console.log(`  ${lang} ${w}: ${all.length} all states, ${one.length} ${STATE}`);
      }
    }
  } finally {
    await me(await login(GOV), { preferred_language: saved });
  }
  writeFileSync(join(OUT, "overflow.json"), JSON.stringify(findings, null, 2));
  const total = Object.values(findings).reduce((n, f) => n + f.length, 0);
  for (const [k, list] of Object.entries(findings)) for (const f of list) console.log(`  ${k}: ${f.kind} ${f.px}px ${f.where} "${f.text}"`);
  console.log(`\n${total} overflow finding(s), ${failures.length} failed check(s), ${p.errors.length} browser error(s)`);
  if (process.argv.includes("--strict") && (total || failures.length || p.errors.length)) throw new Error("check failed");
}

// Stop the headless browser and every process it started: on Windows killing the launcher alone
// leaves Edge's child processes (and its debugging port) running.
function stopBrowser(child) {
  if (process.platform !== "win32") { child.kill(); return; }
  try { execFileSync("taskkill", ["/pid", String(child.pid), "/T", "/F"], { stdio: "ignore" }); } catch { /* already gone */ }
  // Edge's launcher can hand off and exit, orphaning the real browser: end every process that uses
  // this run's temporary profile.
  const dir = child.spawnargs.find((a) => a.startsWith("--user-data-dir="))?.slice("--user-data-dir=".length);
  if (!dir) return;
  const filter = `Get-CimInstance Win32_Process -Filter "Name='msedge.exe' or Name='chrome.exe'" | Where-Object { $_.CommandLine -like '*${dir.replace(/'/g, "''")}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`;
  try { execFileSync("powershell", ["-NoProfile", "-Command", filter], { stdio: "ignore" }); } catch { /* nothing left */ }
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-chart-"))}`,
    "--window-size=1366,900", "--hide-scrollbars", "about:blank"], { stdio: "ignore" });
  try {
    let target;
    for (let i = 0; i < 40 && !target; i++) { await sleep(250); target = await fetch(`http://127.0.0.1:${PORT}/json/list`).then((r) => r.json()).then((l) => l.find((t) => t.type === "page")).catch(() => null); }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener("open", r, { once: true }));
    const p = connect(ws);
    await p.send("Page.enable"); await p.send("Runtime.enable");
    await run(p);
    ws.close();
  } finally { stopBrowser(browser); }
}
main().catch((e) => { console.error(e.message); process.exit(1); });
