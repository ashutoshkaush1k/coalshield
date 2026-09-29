// Pass 1 fixes: focus ring only for keyboard focus; numbers in the normal font; bar labels inside the
// track at scores 0, 5, 15 and 100; the glass top bar blurs what scrolls under it and gains a
// stronger edge. Screenshots in docs/screenshots/ui-polish/pass1/.
//
//   node frontend/scripts/ui_polish_fixes_check.mjs     (needs the stack running: run_all.bat)
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-polish", "pass1");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9231;
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function login(email) {
  const r = await fetch(`${API}/auth/login`, { method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: "demo123" }) });   // demo-only account
  return (await r.json()).access_token;
}

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
  const failures = [];
  const expect = (ok, what) => { console.log(`  ${ok ? "ok  " : "FAIL"} ${what}`); if (!ok) failures.push(what); };
  const width = (w) => p.send("Emulation.setDeviceMetricsOverride", { width: w, height: w < 768 ? 800 : 900, deviceScaleFactor: 1, mobile: w < 768 });
  const waitFor = async (sel) => { for (let i = 0; i < 60; i++) { if (await p.evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return; await sleep(300); } throw new Error(`no ${sel}`); };
  const token = await login("gov@dgms.gov.in");
  await width(1440);
  await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(900);
  await p.evaluate(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", "en")`);
  await p.send("Page.navigate", { url: `${APP}/gov` }); await sleep(1500); await waitFor(".board .core");

  console.log("1. Focus ring only for keyboard focus");
  const tab = await p.evaluate(`(() => { const r = document.querySelector('#app-top-bar .tab[data-tab="ranking"]').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()`);
  for (const type of ["mousePressed", "mouseReleased"]) await p.send("Input.dispatchMouseEvent", { type, x: tab.x, y: tab.y, button: "left", clickCount: 1 });
  await sleep(800);
  const afterClick = await p.evaluate(`(() => { const el = document.activeElement; const cs = getComputedStyle(el); return { tag: el.dataset?.tab ?? el.tagName, outline: cs.outlineStyle, width: cs.outlineWidth, selected: el.getAttribute("aria-selected") }; })()`);
  expect(afterClick.outline === "none" || afterClick.width === "0px", `after a mouse click on a tab: no outline (${JSON.stringify(afterClick)})`);
  const underline = await p.evaluate(`getComputedStyle(document.querySelector('#app-top-bar .tab[aria-selected="true"]'), "::after").backgroundColor`);
  expect(underline === "rgb(6, 182, 212)", `the active tab shows the cyan underline (${underline})`);
  await p.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Tab", code: "Tab", windowsVirtualKeyCode: 9 });
  await p.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Tab", code: "Tab", windowsVirtualKeyCode: 9 });
  await sleep(300);
  const afterKey = await p.evaluate(`(() => { const cs = getComputedStyle(document.activeElement); return { outline: cs.outlineStyle, color: cs.outlineColor }; })()`);
  expect(afterKey.outline === "solid" && afterKey.color === "rgb(6, 182, 212)", `keyboard focus shows the cyan ring (${JSON.stringify(afterKey)})`);
  await p.send("Page.navigate", { url: `${APP}/gov` }); await sleep(1500); await waitFor(".board .core");

  console.log("2. Numbers in the normal font");
  const fonts = await p.evaluate(`[...document.querySelectorAll(".board-axis span, .tally-v, .hero-number")].map((e) => getComputedStyle(e).fontFamily).filter((f) => /mono/i.test(f)).length`);
  expect(fonts === 0, "axis labels and figures are not monospace");

  console.log("3. Bar labels inside the track at scores 0, 5, 15 and 100 (1440 and 360 px)");
  const GEO = `((scores) => {
    const cores = [...document.querySelectorAll(".board .core")].slice(0, scores.length);
    cores.forEach((c, i) => { c.querySelector(".core-fill").style.height = Math.max(1, scores[i]) + "%"; c.querySelector(".core-readout").style.setProperty("--score", scores[i]); });
    return new Promise((res) => requestAnimationFrame(() => setTimeout(() => res(cores.map((c, i) => {
      const t = c.getBoundingClientRect(), r = c.querySelector(".core-readout").getBoundingClientRect(), f = c.querySelector(".core-fill").getBoundingClientRect();
      return { score: scores[i], inside: r.top >= t.top - 0.5 && r.bottom <= t.bottom + 0.5 && r.left >= t.left - 0.5 && r.right <= t.right + 0.5,
               aboveFill: r.bottom <= f.top + 0.5 || scores[i] >= 90, gapBottom: Math.round(t.bottom - r.bottom) };
    })), 600)));
  })([0, 5, 15, 100])`;
  for (const w of [1440, 360]) {
    await width(w); await sleep(600);
    const res = await p.evaluate(GEO);
    for (const r of res) expect(r.inside && r.aboveFill, `${w} px, score ${r.score}: label inside the track${r.score < 90 ? ", just above the fill" : ""} (${r.gapBottom}px from the bottom)`);
    await p.evaluate(`document.querySelector(".board").scrollIntoView({ block: "center" })`); await sleep(400);
    const { data } = await p.send("Page.captureScreenshot", { format: "png" });
    writeFileSync(join(OUT, `fix-bar-labels-0-5-15-100-${w}.png`), Buffer.from(data, "base64"));
  }
  await width(1440);
  await p.send("Page.navigate", { url: `${APP}/gov/mines/5` }); await sleep(1500); await waitFor("#mine-records");

  console.log("4. Glass top bar");
  await p.evaluate("scrollTo(0, 0)"); await sleep(300);
  const top = await p.evaluate(`(() => { const h = document.getElementById("app-top-bar"), cs = getComputedStyle(h); return { cls: h.className, blur: cs.backdropFilter || cs.webkitBackdropFilter, bg: cs.backgroundColor, shadow: cs.boxShadow }; })()`);
  expect(/blur\(12px\)/.test(top.blur) && /rgba\(255, 255, 255, 0\.6\)/.test(top.bg), `translucent white at 60 % with a 12 px blur (${top.bg}, ${top.blur})`);
  await p.evaluate("scrollTo(0, 190)"); await sleep(500);
  const scrolled = await p.evaluate(`(() => { const h = document.getElementById("app-top-bar"), cs = getComputedStyle(h); return { cls: h.className, shadow: cs.boxShadow, border: cs.borderBottomColor }; })()`);
  expect(/is-scrolled/.test(scrolled.cls) && scrolled.shadow !== top.shadow, `stronger edge once content scrolls under it (${scrolled.border})`);
  const y = await p.evaluate("scrollY");
  const { data } = await p.send("Page.captureScreenshot", { format: "png", clip: { x: 0, y, width: 1440, height: 260, scale: 1 } });
  writeFileSync(join(OUT, "fix-glass-scrolled-1440.png"), Buffer.from(data, "base64"));
  console.log("  saved fix-glass-scrolled-1440.png and fix-bar-labels-0-5-15-100-*.png");

  console.log(`\n${failures.length} failed check(s), ${p.errors.length} browser error(s)`);
  if (failures.length || p.errors.length) throw new Error("fixes check failed");
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-fix-"))}`,
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
  } finally { browser.kill(); }
}
main().catch((e) => { console.error(e.message); process.exit(1); });
