// UI polish checks (pass 1 pages: government overview, mine detail for government and mine head,
// Obligations; plus the shell's drawer and search panel).
//
//   node frontend/scripts/ui_polish_check.mjs shots <before|after>   full-page screenshots at 1440 px
//                                                                    (and mine detail + Obligations at 360 px)
//   node frontend/scripts/ui_polish_check.mjs check                   layout check + overflow probe
//
// The layout check fails when two cards in the same row differ in height by more than 2 px, when a
// table scrolls sideways at 1366 px or wider, or when a mine code wraps. The overflow probe (the one
// scripts/browser_check.mjs uses) runs at 360, 1366 and 1920 px in English and Hindi.
// Writes to docs/screenshots/ui-polish/pass1/. Needs the stack running (run_all.bat).
import { execFileSync, spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const MODE = process.argv[2] ?? "check";
const WHEN = process.argv[3] ?? "after";
const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-polish", process.env.PASS ?? "pass1");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9230;
const GOV = "gov@dgms.gov.in", HEAD = "head.od-tlc-05@coalmine.in";
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const source = readFileSync(join(ROOT, "scripts", "browser_check.mjs"), "utf8");
const OVERFLOW_PROBE = new Function(`return \`${source.match(/const OVERFLOW_PROBE = `([\s\S]*?)`;\r?\n/)[1]}\`;`)();

// [name, account (null: signed out), path, element that shows the page is ready]
const PASS1 = [
  ["gov-overview", GOV, "/gov", ".board .core"],
  ["mine-detail-gov", GOV, "/gov/mines/5", "#mine-records"],
  ["mine-detail-head", HEAD, "/mine", "#mine-records"],
  ["obligations", GOV, "/gov?tab=obligations", "#panel-obligations .panel-block"],
];
const tab = (who, home, id) => [`${who === GOV ? "gov" : "head"}-${id}`, who, `${home}?tab=${id}`, `#panel-${id} .panel-block, #panel-${id} .empty`];
const PASS2 = [
  ["login", null, "/login", "#login-language"],
  ["grievance-raise", null, "/grievance", "#grievance-form"],
  ["grievance-track", null, "/grievance/track", "form"],
  tab(GOV, "/gov", "ranking"), tab(GOV, "/gov", "production"), tab(GOV, "/gov", "map"), tab(GOV, "/gov", "sensors"),
  tab(GOV, "/gov", "trends"), tab(GOV, "/gov", "contractors"), tab(GOV, "/gov", "grievances"),
  tab(HEAD, "/mine", "production"), tab(HEAD, "/mine", "map"), tab(HEAD, "/mine", "sensors"), tab(HEAD, "/mine", "trends"),
  tab(HEAD, "/mine", "contractors"), tab(HEAD, "/mine", "grievances"), tab(HEAD, "/mine", "obligations"),
  ["profile", GOV, "/profile", "#profile-language"],
  ["field", null, "/field", "#field-sign-in, .field-app"],
];
const PAGES = (process.env.PASS ?? "pass1") === "pass2" ? PASS2 : PASS1;
const WIDTHS = (process.env.PASS ?? "pass1") === "pass2" ? [360, 1366, 1440, 1920] : [360, 1366, 1920];

// Same-row cards (tops within 2 px, sharing a grid or flex parent) must match in height; tables must
// not scroll sideways at >= 1366 px; mine codes must stay on one line.
const LAYOUT = `(() => {
  const issues = [];
  const vw = document.documentElement.clientWidth;
  const cards = [...document.querySelectorAll("#root .panel-block, #root .record-tile, #root .stat-card")].filter((c) => c.offsetParent);
  const byParent = new Map();
  for (const c of cards) { const k = c.parentElement; if (!byParent.has(k)) byParent.set(k, []); byParent.get(k).push(c); }
  for (const [parent, list] of byParent) {
    const rows = new Map();
    for (const c of list) { const r = c.getBoundingClientRect(); const key = [...rows.keys()].find((t) => Math.abs(t - r.top) <= 2) ?? r.top; if (!rows.has(key)) rows.set(key, []); rows.get(key).push({ c, h: r.height }); }
    for (const row of rows.values()) {
      if (row.length < 2) continue;
      const hs = row.map((x) => x.h), d = Math.max(...hs) - Math.min(...hs);
      if (d > 2) issues.push({ kind: "row-height", px: Math.round(d), where: (row[0].c.id || row[0].c.className) + " +" + (row.length - 1), text: row.map((x) => Math.round(x.h)).join("/") });
    }
  }
  if (vw >= 1366) {
    for (const t of document.querySelectorAll("#root table")) {
      if (!t.offsetParent) continue;
      for (let p = t.parentElement; p && p !== document.body; p = p.parentElement) {
        const o = getComputedStyle(p).overflowX;
        if ((o === "auto" || o === "scroll") && p.scrollWidth > p.clientWidth + 1) { issues.push({ kind: "table-scrolls", px: p.scrollWidth - p.clientWidth, where: t.closest("[id]")?.id ?? "table", text: "" }); break; }
      }
    }
  }
  for (const el of document.querySelectorAll("#root .mine-code, #root .core-code, #root td .mono")) {
    if (!el.offsetParent) continue;
    const lh = parseFloat(getComputedStyle(el).lineHeight) || 16;
    if (el.getBoundingClientRect().height > lh * 1.5) issues.push({ kind: "code-wraps", px: 0, where: el.closest("[id]")?.id ?? "", text: el.textContent.trim().slice(0, 20) });
  }
  return issues;
})()`;

async function login(email) {
  const r = await fetch(`${API}/auth/login`, { method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: "demo123" }) });   // demo-only accounts
  if (!r.ok) throw new Error(`login ${email}: ${r.status}`);
  return (await r.json()).access_token;
}
const api = async (token, path, body) => (await fetch(`${API}${path}`, { method: body ? "PATCH" : "GET",
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
  const width = (w) => p.send("Emulation.setDeviceMetricsOverride", { width: w, height: w < 768 ? 800 : 900, deviceScaleFactor: 1, mobile: w < 768 });
  const waitFor = async (sel) => { for (let i = 0; i < 70; i++) { if (await p.evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return; await sleep(300); } throw new Error(`no ${sel}`); };
  let current = null;
  const signIn = async (who, lang) => {
    if (current === `${who}|${lang}`) return;
    if (!who) {
      await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(900);
      await p.evaluate(`sessionStorage.clear(); localStorage.setItem("smg.lang", ${JSON.stringify(lang)})`);
    } else {
      const token = await login(who); await api(token, "/users/me", { preferred_language: lang });
      await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(900);
      await p.evaluate(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", ${JSON.stringify(lang)})`);
    }
    current = `${who}|${lang}`;
  };
  const open = async ([, who, path, ready], lang = "en") => {
    await signIn(who, lang);
    await p.send("Page.navigate", { url: `${APP}${path}` }); await sleep(1500);
    await waitFor(ready); await p.evaluate("document.fonts.ready.then(() => true)"); await sleep(900);
  };
  const fullShot = async (name) => {
    const size = await p.evaluate(`({ w: document.documentElement.clientWidth, h: Math.min(document.documentElement.scrollHeight, 6000) })`);
    const { data } = await p.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true, clip: { x: 0, y: 0, width: size.w, height: size.h, scale: 1 } });
    writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, "base64")); console.log(`  saved ${name}.png`);
  };
  const viewShot = async (name) => { const { data } = await p.send("Page.captureScreenshot", { format: "png" }); writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, "base64")); console.log(`  saved ${name}.png`); };

  const saved = {};
  for (const who of [GOV, HEAD]) saved[who] = (await api(await login(who), "/users/me")).preferred_language;
  let failed = 0;
  try {
    if (MODE === "shots") {
      await width(1440);
      for (const page of PAGES) { await open(page); await fullShot(`${WHEN}-${page[0]}-1440`); }
      if (PAGES === PASS2) {
        await width(360);
        for (const page of PAGES.filter((pg) => ["login", "grievance-raise", "gov-ranking", "head-production", "profile", "field"].includes(pg[0]))) { await open(page); await fullShot(`${WHEN}-${page[0]}-360`); }
        return;
      }
      await open(PAGES[0]);
      await p.evaluate(`document.getElementById("nav-toggle").click()`); await sleep(600);
      await viewShot(`${WHEN}-drawer-1440`);
      await p.evaluate(`document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }))`); await sleep(300);
      if (await p.evaluate(`!!document.getElementById("search-trigger")`)) {
        await p.evaluate(`document.getElementById("search-trigger").click()`); await sleep(400);
        await p.evaluate(`(() => { const i = document.getElementById("search-input"); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(i, "coal"); i.dispatchEvent(new Event("input", { bubbles: true })); })()`);
        await sleep(1200);
        await viewShot(`${WHEN}-search-1440`);
      }
      await width(360);
      for (const page of [PAGES[1], PAGES[3]]) { await open(page); await fullShot(`${WHEN}-${page[0]}-360`); }
    } else {
      const findings = {};
      for (const lang of ["en", "hi"]) {
        for (const w of WIDTHS) {
          await width(w);
          for (const page of PAGES) {
            await open(page, lang);
            const overflow = await p.evaluate(OVERFLOW_PROBE);
            const layout = await p.evaluate(LAYOUT);
            const all = [...overflow, ...layout];
            if (all.length) findings[`${page[0]}-${lang}-${w}`] = all;
            console.log(`  ${page[0]} ${lang} ${w}: overflow ${overflow.length}, layout ${layout.length}`);
          }
        }
      }
      writeFileSync(join(OUT, "check.json"), JSON.stringify(findings, null, 2));
      for (const [k, list] of Object.entries(findings)) for (const f of list) console.log(`  ${k}: ${f.kind} ${f.px}px ${f.where} "${f.text}"`);
      failed = Object.values(findings).reduce((n, f) => n + f.length, 0);
      console.log(`\n${failed} finding(s), ${p.errors.length} browser error(s)`);
      failed += p.errors.length;
    }
  } finally {
    for (const who of [GOV, HEAD]) await api(await login(who), "/users/me", { preferred_language: saved[who] });
  }
  if (failed) throw new Error("check failed");
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
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-polish-"))}`,
    "--window-size=1440,900", "--hide-scrollbars", "about:blank"], { stdio: "ignore" });
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
