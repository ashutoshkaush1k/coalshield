// The mine risk section (Governance Risk Index and predicted risk cards) at every width and language.
//
//   node frontend/scripts/ui_risk_card_check.mjs before|after [--strict]
//
// Needs the stack running (run_all.bat). Writes to docs/screenshots/ui-risk-card/:
//   <mode>-*.png      the risk section on the government mine detail and the mine head overview,
//                     at 1440, 1024 and 360 px, and in Hindi
//   <mode>-overflow.json  the overflow probe (the one scripts/browser_check.mjs uses for phase 6)
//                     on both screens at 360, 1024, 1128, 1366 and 1440 px in all six languages,
//                     plus two header checks: a card title reached through its link is not under the
//                     sticky header, and no header tab is cut off
// --strict fails on any finding. The accounts' saved languages are put back afterwards.
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const MODE = process.argv[2] === "after" ? "after" : "before";
const STRICT = process.argv.includes("--strict");
const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-risk-card");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9224;
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const LANGS = ["en", "hi", "bn", "or", "te", "mr"];
const WIDTHS = [360, 1024, 1128, 1366, 1440];
const GOV = "gov@dgms.gov.in";
const HEAD = "head.od-tlc-05@coalmine.in";   // Bhubaneswari, mine 5: repeat multiplier and a model factor
const SCREENS = [{ name: "gov", who: GOV, path: "/gov/mines/5" }, { name: "head", who: HEAD, path: "/mine" }];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// One probe for the whole project: taken from scripts/browser_check.mjs rather than copied.
const source = readFileSync(join(ROOT, "scripts", "browser_check.mjs"), "utf8");
const OVERFLOW_PROBE = new Function(`return \`${source.match(/const OVERFLOW_PROBE = `([\s\S]*?)`;\r?\n/)[1]}\`;`)();

// A card title must be clear of the sticky header after its link is followed, and every header tab
// must lie wholly inside the window and inside its own row.
const HEADER_PROBE = `(() => {
  const found = [];
  const head = document.querySelector(".masthead");
  if (!head) return found;
  const hb = head.getBoundingClientRect();
  const vw = document.documentElement.clientWidth;
  for (const tab of head.querySelectorAll(".tab")) {
    const r = tab.getBoundingClientRect();
    if (r.left < -1 || r.right > vw + 1 || r.right > hb.right + 1 || tab.scrollWidth > tab.clientWidth + 1) {
      found.push({ kind: "tab-cut", px: Math.round(Math.max(r.right - vw, r.right - hb.right, tab.scrollWidth - tab.clientWidth, -r.left)), where: "masthead .tab", text: tab.textContent.trim() });
    }
  }
  return found;
})()`;

async function login(email) {
  const r = await fetch(`${API}/auth/login`, { method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: "demo123" }) });   // demo-only accounts
  if (!r.ok) throw new Error(`login ${email}: ${r.status}`);
  return (await r.json()).access_token;
}
const me = async (token, body) => (await fetch(`${API}/users/me`, { method: body ? "PATCH" : "GET",
  headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" }, body: body && JSON.stringify(body) })).json();

class Page {
  constructor(ws) {
    this.ws = ws; this.id = 0; this.pending = new Map(); this.errors = [];
    ws.addEventListener("message", (e) => {
      const msg = JSON.parse(e.data);
      if (msg.id && this.pending.has(msg.id)) { this.pending.get(msg.id)(msg); this.pending.delete(msg.id); }
      if (msg.method === "Runtime.exceptionThrown") this.errors.push(msg.params.exceptionDetails.text);
    });
  }
  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((res, rej) => this.pending.set(id, (m) => (m.error ? rej(new Error(m.error.message)) : res(m.result))));
  }
  async eval(expression) {
    const r = await this.send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + " in " + expression.slice(0, 80));
    return r.result.value;
  }
  async waitFor(selector, timeout = 20000) {
    for (let waited = 0; waited < timeout; waited += 300) {
      if (await this.eval(`!!document.querySelector(${JSON.stringify(selector)})`)) return;
      await sleep(300);
    }
    throw new Error(`waited ${timeout / 1000} s for ${selector}`);
  }
  async width(w) {
    await this.send("Emulation.setDeviceMetricsOverride", { width: w, height: w < 768 ? 800 : 900, deviceScaleFactor: 1, mobile: w < 768 });
  }
  async open(path) {
    await this.send("Page.navigate", { url: `${APP}${path}` });
    await sleep(1500);
    await this.waitFor("#risk-panel");
    await this.eval("document.fonts.ready.then(() => true)");
    await sleep(700);
  }
  async signIn(email, lang) {
    const token = await login(email);
    await me(token, { preferred_language: lang });
    await this.send("Page.navigate", { url: `${APP}/login` });
    await sleep(1200);
    await this.eval(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", ${JSON.stringify(lang)})`);
  }
  /** The risk section (or `selector`) as one image, however tall. */
  async shootElement(name, selector = "#risk-panel") {
    const box = await this.eval(`(() => { const r = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();
      return { x: Math.max(0, r.left - 8) + scrollX, y: Math.max(0, r.top - 8) + scrollY, width: r.width + 16, height: r.height + 16 }; })()`);
    const { data } = await this.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true, clip: { ...box, scale: 1 } });
    writeFileSync(join(OUT, `${MODE}-${name}.png`), Buffer.from(data, "base64"));
    console.log(`  saved ${MODE}-${name}.png`);
  }
  async shootViewport(name) {
    const { data } = await this.send("Page.captureScreenshot", { format: "png" });
    writeFileSync(join(OUT, `${MODE}-${name}.png`), Buffer.from(data, "base64"));
    console.log(`  saved ${MODE}-${name}.png`);
  }
}

async function run(page) {
  const findings = {};
  const record = (key, issues) => { if (issues.length) findings[key] = issues; };
  const saved = {};
  for (const s of SCREENS) saved[s.who] = (await me(await login(s.who))).preferred_language;

  try {
    console.log("Screenshots");
    for (const s of SCREENS) {
      await page.width(1440);
      await page.signIn(s.who, "en");
      await page.open(s.path);
      await page.shootElement(`${s.name}-1440`);
      if (s.name === "gov" && MODE === "after") {
        // The working behind each number: both expanders open.
        await page.eval(`document.querySelectorAll("#risk-panel details").forEach((d) => { d.open = true; })`);
        await sleep(400);
        await page.shootElement("gov-1440-expanded");
      }
      if (s.name === "gov") {
        for (const w of [1024, 360]) { await page.width(w); await page.open(s.path); await page.shootElement(`${s.name}-${w}`); }
        // The "what makes it up" link beside the score, and a jump to the prediction card: the card
        // title must not land under the sticky header (checked where the page can scroll past it).
        for (const w of [1440, 1024, 360]) {
          await page.width(w);
          await page.open(s.path);
          for (const card of ["governance-risk", "predicted-risk"]) {
            await page.eval(card === "governance-risk"
              ? `document.querySelector('a[href="#governance-risk"]').click()`
              : `document.getElementById("predicted-risk").scrollIntoView({ block: "start" })`);
            await sleep(900);
            const covered = await page.eval(`(() => { const h = document.querySelector(".masthead").getBoundingClientRect();
              const t = document.querySelector("#${card} h2").getBoundingClientRect(); return Math.round(h.bottom - t.top); })()`);
            if (covered > 0) (findings[`anchor-${card}-${w}`] ??= []).push({ kind: "title-under-header", px: covered, where: `#${card} h2`, text: "" });
            if (card === "governance-risk" && w === 1024) await page.shootViewport("gov-anchor-1024");
          }
        }
      } else {
        await page.width(360); await page.open(s.path);
        await page.shootViewport("head-360-header");
      }
    }
    await page.width(1440);
    await page.signIn(GOV, "hi");
    await page.open("/gov/mines/5");
    await page.shootElement("gov-1440-hi");

    console.log("Overflow probe: 2 screens x 6 languages x 5 widths");
    for (const code of LANGS) {
      for (const s of SCREENS) {
        await page.signIn(s.who, code);
        for (const w of WIDTHS) {
          await page.width(w);
          await page.open(s.path);
          const issues = [...await page.eval(OVERFLOW_PROBE), ...await page.eval(HEADER_PROBE)];
          record(`${s.name}-${code}-${w}`, issues);
          process.stdout.write(`  ${s.name} ${code} ${w}: ${issues.length}\n`);
        }
      }
    }
    // The header tabs on the government overview (the longest tab row) at every width and language.
    for (const code of LANGS) {
      await page.signIn(GOV, code);
      for (const w of WIDTHS) {
        await page.width(w);
        await page.send("Page.navigate", { url: `${APP}/gov` });
        await sleep(1500);
        await page.waitFor(".masthead .tab");
        await sleep(500);
        record(`gov-overview-tabs-${code}-${w}`, await page.eval(HEADER_PROBE));
      }
    }
  } finally {
    for (const s of SCREENS) await me(await login(s.who), { preferred_language: saved[s.who] });
  }

  writeFileSync(join(OUT, `${MODE}-overflow.json`), JSON.stringify(findings, null, 2));
  const total = Object.values(findings).reduce((n, f) => n + f.length, 0);
  console.log(`\n${MODE}: ${total} finding(s) in ${Object.keys(findings).length} of ${2 * LANGS.length * WIDTHS.length + LANGS.length * WIDTHS.length + 1} checks (${MODE}-overflow.json)`);
  for (const [k, list] of Object.entries(findings)) for (const f of list) console.log(`  ${k}: ${f.kind} ${f.px}px ${f.where} "${f.text}"`);
  if (page.errors.length) console.log(`  browser errors: ${[...new Set(page.errors)].slice(0, 5).join(" | ")}`);
  if (STRICT && (total || page.errors.length)) throw new Error(`${total} finding(s), ${page.errors.length} browser error(s)`);
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  if (!exe) throw new Error("Edge or Chrome not found");
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-risk-"))}`,
    "--window-size=1440,900", "--hide-scrollbars", "about:blank"], { stdio: "ignore" });
  try {
    let target;
    for (let i = 0; i < 40 && !target; i++) {
      await sleep(250);
      target = await fetch(`http://127.0.0.1:${PORT}/json/list`).then((r) => r.json()).then((l) => l.find((t) => t.type === "page")).catch(() => null);
    }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener("open", r, { once: true }));
    const page = new Page(ws);
    await page.send("Page.enable");
    await page.send("Runtime.enable");
    await run(page);
    ws.close();
  } finally {
    browser.kill();
  }
}

main().catch((e) => { console.error(e.message); process.exit(1); });
