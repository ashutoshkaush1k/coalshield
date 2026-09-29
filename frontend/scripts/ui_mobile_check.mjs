// Phone layout check: every page (and the drawer, the search panel, an open record drawer and the
// field app) at 360, 390 and 414 px in English and Hindi, plus Telugu at 360 px. Fails on:
//   - horizontal page scroll, or anything the overflow probe (scripts/browser_check.mjs) finds;
//   - a touch target under 44 x 44 px (buttons, links, inputs, selects, summaries, [role=button],
//     [tabindex]); a link inside running text is exempt, as in WCAG 2.5.8;
//   - visible text under 14 px.
//   node frontend/scripts/ui_mobile_check.mjs [check|shots]     (needs the stack running)
// shots: every page at 390 px (English) to docs/screenshots/mobile/.
import { execFileSync, spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const MODE = process.argv[2] ?? "check";
const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "mobile");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9236;
const GOV = "gov@dgms.gov.in", HEAD = "head.od-tlc-05@coalmine.in";
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const source = readFileSync(join(ROOT, "scripts", "browser_check.mjs"), "utf8");
const OVERFLOW_PROBE = new Function(`return \`${source.match(/const OVERFLOW_PROBE = `([\s\S]*?)`;\r?\n/)[1]}\`;`)();

// [name, account (null: signed out), path, ready selector, optional action run once ready]
const tab = (who, home, id) => [`${who === GOV ? "gov" : "head"}-${id}`, who, `${home}?tab=${id}`, `#panel-${id} .panel-block, #panel-${id} .empty`];
const PAGES = [
  ["login", null, "/login", "#login-language"],
  ["grievance-raise", null, "/grievance", "#grievance-form"],
  ["grievance-track", null, "/grievance/track", "form"],
  ["gov-overview", GOV, "/gov", ".board .core"],
  tab(GOV, "/gov", "ranking"), tab(GOV, "/gov", "obligations"), tab(GOV, "/gov", "production"), tab(GOV, "/gov", "map"),
  tab(GOV, "/gov", "sensors"), tab(GOV, "/gov", "trends"), tab(GOV, "/gov", "contractors"), tab(GOV, "/gov", "grievances"),
  ["mine-detail-gov", GOV, "/gov/mines/5", "#mine-records"],
  ["mine-detail-records", GOV, "/gov/mines/5", "#mine-records", `document.getElementById("records-violations").click()`, ".drawer"],
  ["drawer", GOV, "/gov", ".board .core", `document.getElementById("nav-toggle").click()`, "#nav-drawer.is-open"],
  ["search", GOV, "/gov", ".board .core", `(() => { document.getElementById("search-trigger").click(); setTimeout(() => { const i = document.getElementById("search-input"); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(i, "coal"); i.dispatchEvent(new Event("input", { bubbles: true })); }, 200); })()`, "#search-results .search-option"],
  ["head-overview", HEAD, "/mine", "#mine-records"],
  tab(HEAD, "/mine", "production"), tab(HEAD, "/mine", "obligations"), tab(HEAD, "/mine", "contractors"), tab(HEAD, "/mine", "grievances"),
  tab(HEAD, "/mine", "sensors"), tab(HEAD, "/mine", "map"),
  ["profile", GOV, "/profile", "#profile-language"],
  ["field", null, "/field", "#field-sign-in, .field-app"],
];

const MOBILE = `(() => {
  const issues = [];
  const vw = document.documentElement.clientWidth;
  if (document.documentElement.scrollWidth > vw + 1) issues.push({ kind: "page-scrolls-sideways", px: document.documentElement.scrollWidth - vw, where: "html", text: "" });
  const visible = (el) => { const r = el.getBoundingClientRect(); if (r.width === 0 || r.height === 0) return false;
    for (let e = el; e && e !== document.body; e = e.parentElement) { const cs = getComputedStyle(e); if (cs.visibility === "hidden" || cs.display === "none" || parseFloat(cs.opacity) === 0) return false; if (e.inert) return false; }
    return !el.closest(".sr-only, [aria-hidden='true']"); };
  const where = (el) => { const bits = []; for (let e = el; e && e !== document.body && bits.length < 3; e = e.parentElement) bits.unshift(e.tagName.toLowerCase() + (e.id ? "#" + e.id : "") + (typeof e.className === "string" && e.className.trim() ? "." + e.className.trim().split(/\\s+/)[0] : "")); return bits.join(" > "); };
  const seen = new Map();
  const add = (kind, px, el) => { const k = kind + "|" + where(el); if (seen.has(k)) { seen.get(k).count++; return; } const f = { kind, px: Math.round(px), where: where(el), text: (el.textContent || el.getAttribute("aria-label") || "").trim().slice(0, 40), count: 1 }; seen.set(k, f); issues.push(f); };
  for (const el of document.querySelectorAll("a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [tabindex]:not([tabindex='-1'])")) {
    if (!visible(el) || el.closest("svg, .leaflet-pane")) continue;
    // A link inside running text is exempt (WCAG 2.5.8).
    if (el.tagName === "A" && getComputedStyle(el).display === "inline" && el.parentElement.textContent.trim().length > el.textContent.trim().length) continue;
    // A checkbox or radio is tapped through its label: measure the label.
    const target = el.matches("input[type=checkbox], input[type=radio]") ? (el.closest("label") ?? document.querySelector(\`label[for="\${el.id}"]\`) ?? el) : el;
    const r = target.getBoundingClientRect();
    if (r.width < 43.5 || r.height < 43.5) add("touch-target", Math.min(r.width, r.height), el);
  }
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const t = walker.currentNode.textContent.trim(); const el = walker.currentNode.parentElement;
    if (!t || !el || !visible(el) || el.closest("script, style, option")) continue;
    const size = parseFloat(getComputedStyle(el).fontSize);
    if (size < 13.9) add("text-under-14px", size, el);
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
  const waitFor = async (sel, ms = 20000) => { for (let i = 0; i < ms / 300; i++) { if (await p.evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return; await sleep(300); } throw new Error(`no ${sel}`); };
  let current = null;
  const signIn = async (who, lang) => {
    if (current === `${who}|${lang}`) return;
    await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(900);
    if (!who) await p.evaluate(`sessionStorage.clear(); localStorage.setItem("smg.lang", ${JSON.stringify(lang)})`);
    else {
      const token = await login(who); await api(token, "/users/me", { preferred_language: lang });
      await p.evaluate(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", ${JSON.stringify(lang)})`);
    }
    current = `${who}|${lang}`;
  };
  const open = async ([, who, path, ready, action, after], lang) => {
    await signIn(who, lang);
    await p.send("Page.navigate", { url: `${APP}${path}` }); await sleep(1200);
    await waitFor(ready); await p.evaluate("document.fonts.ready.then(() => true)"); await sleep(600);
    if (action) { await p.evaluate(action); await waitFor(after); await sleep(900); }
  };
  const width = (w) => p.send("Emulation.setDeviceMetricsOverride", { width: w, height: 844, deviceScaleFactor: 1, mobile: true });
  const saved = {};
  for (const who of [GOV, HEAD]) saved[who] = (await api(await login(who), "/users/me")).preferred_language;
  let total = 0;
  try {
    if (MODE === "shots") {
      mkdirSync(OUT, { recursive: true });
      await width(390);
      for (const page of PAGES) {
        await open(page, "en");
        const h = await p.evaluate(`Math.min(document.documentElement.scrollHeight, 6000)`);
        const { data } = await p.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: !page[4], clip: page[4] ? undefined : { x: 0, y: 0, width: 390, height: h, scale: 1 } });
        writeFileSync(join(OUT, `${page[0]}-390.png`), Buffer.from(data, "base64")); console.log(`  saved ${page[0]}-390.png`);
      }
      return;
    }
    const findings = {};
    const runs = process.env.RUNS ? process.env.RUNS.split(",").map((r) => { const [l, w] = r.split(":"); return [l, Number(w)]; })
      : [["en", 360], ["en", 390], ["en", 414], ["hi", 360], ["hi", 390], ["hi", 414], ["te", 360]];
    for (const [lang, w] of runs) {
      await width(w);
      for (const page of PAGES) {
        try { await open(page, lang); } catch (e) { findings[`${page[0]}-${lang}-${w}`] = [{ kind: "not-ready", px: 0, where: e.message, text: "" }]; continue; }
        const issues = [...await p.evaluate(OVERFLOW_PROBE), ...await p.evaluate(MOBILE)];
        if (issues.length) findings[`${page[0]}-${lang}-${w}`] = issues;
        console.log(`  ${page[0]} ${lang} ${w}: ${issues.length}`);
      }
    }
    mkdirSync(OUT, { recursive: true });
    writeFileSync(join(OUT, "check.json"), JSON.stringify(findings, null, 2));
    total = Object.values(findings).reduce((n, f) => n + f.length, 0);
    console.log(`\n${total} finding(s), ${p.errors.length} browser error(s) (docs/screenshots/mobile/check.json)`);
  } finally {
    for (const who of [GOV, HEAD]) await api(await login(who), "/users/me", { preferred_language: saved[who] });
  }
  if (total || p.errors.length) throw new Error("mobile check failed");
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
  const exe = BROWSERS.find(existsSync);
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-mobile-"))}`,
    "--window-size=414,844", "--hide-scrollbars", "about:blank"], { stdio: "ignore" });
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
