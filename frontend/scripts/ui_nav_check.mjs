// Navigation drawer check: overflow probe (government overview and mine-head dashboard, 1366 and
// 360 px, English and Hindi, drawer closed and open), the drawer's behaviour (hover, mouse leave,
// Esc, keyboard, a drawer tab), and four screenshots in docs/screenshots/ui-nav/.
//
//   node frontend/scripts/ui_nav_check.mjs [--strict]     (needs the stack running: run_all.bat)
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-nav");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9225;
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const SCREENS = [{ name: "gov", who: "gov@dgms.gov.in", path: "/gov", drawerTab: "sensors" },
  { name: "head", who: "head.od-tlc-05@coalmine.in", path: "/mine", drawerTab: "sensors" }];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const source = readFileSync(join(ROOT, "scripts", "browser_check.mjs"), "utf8");
const OVERFLOW_PROBE = new Function(`return \`${source.match(/const OVERFLOW_PROBE = `([\s\S]*?)`;\r?\n/)[1]}\`;`)();

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
  const findings = {};
  const failures = [];
  const expect = (ok, what) => { console.log(`  ${ok ? "ok  " : "FAIL"} ${what}`); if (!ok) failures.push(what); };
  const width = (w) => p.send("Emulation.setDeviceMetricsOverride", { width: w, height: w < 768 ? 800 : 860, deviceScaleFactor: 1, mobile: w < 768 });
  const waitFor = async (sel) => { for (let i = 0; i < 60; i++) { if (await p.evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return; await sleep(300); } throw new Error(`no ${sel}`); };
  const isOpen = () => p.evaluate(`document.getElementById("nav-drawer").classList.contains("is-open")`);
  const centre = (sel) => p.evaluate(`(() => { const r = document.querySelector(${JSON.stringify(sel)}).getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()`);
  const mouse = (type, { x, y }) => p.send("Input.dispatchMouseEvent", { type, x, y, button: type === "mouseMoved" ? "none" : "left", clickCount: 1 });
  const key = (k, code, vk) => p.send("Input.dispatchKeyEvent", { type: "keyDown", key: k, code, windowsVirtualKeyCode: vk }).then(() => p.send("Input.dispatchKeyEvent", { type: "keyUp", key: k, code, windowsVirtualKeyCode: vk }));
  const shot = async (name) => { const { data } = await p.send("Page.captureScreenshot", { format: "png" }); writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, "base64")); console.log(`  saved ${name}.png`); };
  const open = async (s, lang) => {
    const token = await login(s.who);
    await me(token, { preferred_language: lang });
    await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(1000);
    await p.evaluate(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", ${JSON.stringify(lang)})`);
    await p.send("Page.navigate", { url: `${APP}${s.path}` }); await sleep(1500);
    await waitFor(".masthead .tab"); await p.evaluate("document.fonts.ready.then(() => true)"); await sleep(600);
  };
  const saved = {};
  for (const s of SCREENS) saved[s.who] = (await me(await login(s.who))).preferred_language;
  try {
    for (const s of SCREENS) {
      console.log(`${s.name}: behaviour and screenshots (1366 px)`);
      await width(1366);
      await open(s, "en");
      expect(!(await p.evaluate(`!!document.querySelector(".sidebar")`)) && (await p.evaluate(`document.querySelector(".main").getBoundingClientRect().left`)) === 0, "no docked sidebar; content starts at the left edge");
      expect(await p.evaluate(`document.querySelectorAll(".masthead .tab").length`) === 5, "five tabs on the bar");
      expect(!(await p.evaluate(`document.body.innerText.includes("Problem statement")`)), "no problem-statement line");
      await shot(`${s.name}-drawer-closed`);
      const toggle = await centre("#nav-toggle");
      await mouse("mouseMoved", toggle); await sleep(400);
      expect(await isOpen(), "hovering the hamburger opens the drawer");
      await mouse("mouseMoved", { x: 150, y: 300 }); await sleep(150);          // into the drawer
      await mouse("mouseMoved", { x: 900, y: 500 }); await sleep(120);
      expect(await isOpen(), "still open 120 ms after the pointer leaves");
      await sleep(400);
      expect(!(await isOpen()), "closed 300 ms after the pointer leaves");
      await mouse("mouseMoved", toggle); await sleep(400);
      await mouse("mouseMoved", { x: 150, y: 300 }); await sleep(300);
      await shot(`${s.name}-drawer-open`);
      await key("Escape", "Escape", 27); await sleep(300);
      expect(!(await isOpen()), "Esc closes it");
      await mouse("mouseMoved", { x: 900, y: 700 }); await sleep(500);
      await p.evaluate(`document.getElementById("nav-toggle").focus()`);
      await p.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13, text: "\r" }); await p.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13 }); await sleep(400);
      expect(await isOpen() && await p.evaluate(`!!document.activeElement.closest("#nav-drawer")`), "Enter opens it and moves focus into it");
      await p.evaluate(`document.querySelector('#nav-drawer [data-tab="${s.drawerTab}"]').click()`); await sleep(800);
      expect(!(await isOpen()) && await p.evaluate(`!!document.getElementById("panel-${s.drawerTab}")`), `a drawer tab (${s.drawerTab}) opens its panel and closes the drawer`);
      expect(await p.evaluate(`document.getElementById("nav-current")?.textContent.trim().length > 0`), "its name shows next to the hamburger");
      await p.evaluate(`document.querySelector(".masthead .home-link").click()`); await sleep(800);
      expect(await p.evaluate(`!!document.getElementById("panel-overview") && location.pathname === ${JSON.stringify(s.path)}`), "the logo link goes back to the home overview");
      expect(await p.evaluate(`[...document.querySelectorAll("#nav-drawer .nav-list > li")].at(-1).textContent.includes("Profile")`), "Profile and language is the last item of More views");
      await p.evaluate(`document.getElementById("nav-toggle").click()`); await sleep(300);
      await p.send("Input.dispatchMouseEvent", { type: "mousePressed", x: 1200, y: 600, button: "left", clickCount: 1 });
      await p.send("Input.dispatchMouseEvent", { type: "mouseReleased", x: 1200, y: 600, button: "left", clickCount: 1 }); await sleep(300);
      expect(!(await isOpen()), "a click outside closes it");
    }
    console.log("Overflow probe: 2 screens x en/hi x 1366/360, drawer closed and open");
    for (const lang of ["en", "hi"]) {
      for (const s of SCREENS) {
        await open(s, lang);
        for (const w of [1366, 360]) {
          await width(w); await sleep(700);
          const closed = await p.evaluate(OVERFLOW_PROBE);
          await p.evaluate(`document.getElementById("nav-toggle").click()`); await sleep(500);
          const opened = await p.evaluate(OVERFLOW_PROBE);
          await key("Escape", "Escape", 27); await sleep(300);
          const k = `${s.name}-${lang}-${w}`;
          if (closed.length) findings[`${k}-closed`] = closed;
          if (opened.length) findings[`${k}-open`] = opened;
          console.log(`  ${k}: ${closed.length} closed, ${opened.length} open`);
        }
      }
    }
  } finally {
    for (const s of SCREENS) await me(await login(s.who), { preferred_language: saved[s.who] });
  }
  writeFileSync(join(OUT, "overflow.json"), JSON.stringify(findings, null, 2));
  const total = Object.values(findings).reduce((n, f) => n + f.length, 0);
  for (const [k, list] of Object.entries(findings)) for (const f of list) console.log(`  ${k}: ${f.kind} ${f.px}px ${f.where} "${f.text}"`);
  console.log(`\n${total} overflow finding(s), ${failures.length} failed check(s), ${p.errors.length} browser error(s)`);
  if (process.argv.includes("--strict") && (total || failures.length || p.errors.length)) throw new Error("check failed");
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-nav-"))}`,
    "--window-size=1366,860", "--hide-scrollbars", "about:blank"], { stdio: "ignore" });
  try {
    let target;
    for (let i = 0; i < 40 && !target; i++) { await sleep(250); target = await fetch(`http://127.0.0.1:${PORT}/json/list`).then((r) => r.json()).then((l) => l.find((t) => t.type === "page")).catch(() => null); }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener("open", r, { once: true }));
    const p = connect(ws);
    await p.send("Page.enable"); await p.send("Runtime.enable");
    await run(p);
    ws.close();
  } finally { browser.kill(); }
}
main().catch((e) => { console.error(e.message); process.exit(1); });
