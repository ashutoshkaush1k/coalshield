// Drawer motion check: hover intent (opens after 120 ms at rest on the hamburger, not before), the
// 300 ms close after leaving both the icon and the drawer (no flicker when moving between them),
// instant open on click and keyboard, only transform/opacity animating, the backdrop at 0.25, the page
// behind not moving, frame rate and layout work during open and close (Performance metrics and a
// requestAnimationFrame frame log), the stagger on open only, and reduced motion as a quick fade.
//
//   node frontend/scripts/ui_drawer_motion_check.mjs     (needs the stack running: run_all.bat)
import { execFileSync, spawn } from "node:child_process";
import { existsSync, mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9232;
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

// Frames and layout during an animation: a rAF log in the page, and LayoutCount / RecalcStyleCount
// from the Performance domain, sampled after the frame that starts the animation and again at its end.
const FRAMES = (ms) => `new Promise((res) => { const t = []; const start = performance.now();
  const tick = (now) => { t.push(now); if (now - start < ${ms}) requestAnimationFrame(tick); else res(t); }; requestAnimationFrame(tick); })`;

async function run(p) {
  const failures = [];
  const expect = (ok, what) => { console.log(`  ${ok ? "ok  " : "FAIL"} ${what}`); if (!ok) failures.push(what); };
  const metrics = async () => Object.fromEntries((await p.send("Performance.getMetrics")).metrics.map((m) => [m.name, m.value]));
  const isOpen = () => p.evaluate(`document.getElementById("nav-drawer").classList.contains("is-open")`);
  const mouse = (x, y) => p.send("Input.dispatchMouseEvent", { type: "mouseMoved", x, y, button: "none" });
  const token = await login("gov@dgms.gov.in");
  await p.send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
  await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(900);
  await p.evaluate(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", "en")`);
  await p.send("Page.navigate", { url: `${APP}/gov` }); await sleep(2500);
  for (let i = 0; i < 40 && !(await p.evaluate(`!!document.querySelector(".board .core")`)); i++) await sleep(300);
  await sleep(800);
  const toggle = await p.evaluate(`(() => { const r = document.getElementById("nav-toggle").getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()`);

  console.log("Setup");
  const css = await p.evaluate(`(() => { const d = getComputedStyle(document.getElementById("nav-drawer")); const b = getComputedStyle(document.querySelector(".nav-backdrop"));
    return { props: d.transitionProperty, dur: d.transitionDuration, ease: d.transitionTimingFunction, mounted: !!document.getElementById("nav-drawer"), backdrop: b.transitionProperty }; })()`);
  expect(css.mounted && /^transform$/.test(css.props) && css.backdrop === "opacity", `drawer mounted; only transform and the backdrop's opacity transition (${css.props}; ${css.backdrop})`);
  expect(css.dur.startsWith("0.2s") && css.ease.startsWith("cubic-bezier(0.4, 0, 1, 1)"), `close: 200 ms, cubic-bezier(0.4, 0, 1, 1) (${css.dur}, ${css.ease})`);

  console.log("Hover intent");
  await mouse(900, 500); await sleep(200);
  // Timed in the page: pointer arrival on the icon -> the drawer's class changing to is-open.
  await p.evaluate(`(() => { window.__intent = {}; const d = document.getElementById("nav-drawer");
    document.getElementById("nav-toggle").addEventListener("pointerenter", () => { window.__intent.enter = performance.now(); }, { once: true });
    new MutationObserver((m, o) => { if (d.classList.contains("is-open")) { window.__intent.open = performance.now(); o.disconnect(); } }).observe(d, { attributes: true, attributeFilter: ["class"] }); })()`);
  await mouse(toggle.x, toggle.y); await sleep(400);
  const intent = await p.evaluate(`window.__intent`);
  const delay = intent.open - intent.enter;
  expect(delay >= 118 && delay < 200, `opens ${delay.toFixed(0)} ms after the pointer reaches the hamburger (hover intent 120 ms)`);
  expect(await isOpen(), "open once the pointer has rested 120 ms");
  const openCss = await p.evaluate(`(() => { const d = getComputedStyle(document.getElementById("nav-drawer")); return { dur: d.transitionDuration, ease: d.transitionTimingFunction }; })()`);
  expect(openCss.dur.startsWith("0.6s") && openCss.ease.startsWith("cubic-bezier(0.22, 1, 0.36, 1)"), `open: 600 ms, cubic-bezier(0.22, 1, 0.36, 1) (${openCss.dur}, ${openCss.ease})`);
  await sleep(400);
  await mouse(150, 300); await sleep(250);          // into the drawer (it covers the icon now)
  await mouse(420, 300); await sleep(150);          // out of both ...
  await mouse(150, 320); await sleep(250);          // ... and back within 300 ms
  expect(await isOpen(), "moving out and back within 300 ms does not close it (no flicker)");
  await mouse(900, 500); await sleep(200);
  expect(await isOpen(), "still open 200 ms after leaving both");
  await sleep(200);
  expect(!(await isOpen()), "closed 300 ms after leaving both");
  await mouse(toggle.x, toggle.y); await sleep(60); await mouse(900, 500); await sleep(300);
  expect(!(await isOpen()), "a pointer passing over the icon for 60 ms does not open it");

  console.log("Esc with the pointer resting on the icon");
  await sleep(400);
  for (const type of ["mousePressed", "mouseReleased"]) await p.send("Input.dispatchMouseEvent", { type, x: toggle.x, y: toggle.y, button: "left", clickCount: 1 });
  await sleep(400);
  await p.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
  await p.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
  await sleep(150); await mouse(toggle.x + 1, toggle.y); await sleep(400);
  expect(!(await isOpen()), "closed by Esc, it does not reopen while the pointer rests on the icon");
  await mouse(900, 500); await sleep(200); await mouse(toggle.x, toggle.y); await sleep(300);
  expect(await isOpen(), "after the pointer leaves and comes back, hover opens it again");
  await mouse(900, 500); await sleep(600);

  console.log("Closed: inert, yet the page's own scripts can still click an item");
  expect(await p.evaluate(`document.getElementById("nav-drawer").inert === true`), "the closed drawer is inert");
  await p.evaluate(`document.querySelector('#nav-drawer [data-tab="sensors"]').click()`); await sleep(1500);
  expect(await p.evaluate(`location.search.includes("tab=sensors")`), "a programmatic click on a drawer item still works (browser checks rely on it)");
  await p.send("Page.navigate", { url: `${APP}/gov` }); await sleep(2500);

  console.log("Click and keyboard open at once");
  await sleep(400);
  for (const type of ["mousePressed", "mouseReleased"]) await p.send("Input.dispatchMouseEvent", { type, x: toggle.x, y: toggle.y, button: "left", clickCount: 1 });
  await sleep(20);
  expect(await isOpen(), "a click opens it at once");
  await p.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
  await p.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
  await sleep(500);
  await mouse(900, 500); await sleep(100);
  await p.evaluate(`document.getElementById("nav-toggle").focus()`);
  await p.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13, text: "\r" });
  await p.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13 });
  await sleep(20);
  expect(await isOpen(), "Enter opens it at once");
  await p.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
  await p.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
  await sleep(600);

  console.log("Frames and layout during the animation");
  const pageBefore = await p.evaluate(`JSON.stringify(document.querySelector(".main").getBoundingClientRect()) + scrollY + document.documentElement.scrollWidth`);
  for (const [label, action, ms] of [["open", `document.getElementById("nav-toggle").click()`, 640], ["close", `document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }))`, 240]]) {
    await p.evaluate(action);
    await p.evaluate(`new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))`);   // the frame that starts it
    const before = await metrics();
    const frames = await p.evaluate(FRAMES(ms - 30));
    const after = await metrics();
    const gaps = frames.slice(1).map((t, i) => t - frames[i]);
    const fps = (frames.length - 1) / ((frames.at(-1) - frames[0]) / 1000);
    const layouts = after.LayoutCount - before.LayoutCount;
    const styles = after.RecalcStyleCount - before.RecalcStyleCount;
    console.log(`  ${label}: ${frames.length} frames, ${fps.toFixed(1)} fps, longest frame ${Math.max(...gaps).toFixed(1)} ms, layouts ${layouts}, style recalcs ${styles}`);
    expect(fps >= 55 && Math.max(...gaps) < 34, `${label}: about 60 fps with no dropped run of frames`);
    expect(layouts === 0, `${label}: no layout recalculation while it animates`);
    if (label === "open") {
      const pageOpen = await p.evaluate(`JSON.stringify(document.querySelector(".main").getBoundingClientRect()) + scrollY + document.documentElement.scrollWidth`);
      expect(pageOpen === pageBefore, "the page behind does not move or reflow");
      const bd = await p.evaluate(`getComputedStyle(document.querySelector(".nav-backdrop")).opacity`);
      expect(bd === "0.25", `backdrop at 0.25 (${bd})`);
      const stagger = await p.evaluate(`[...document.querySelectorAll("#nav-drawer .nav-stagger")].map((e) => parseFloat(getComputedStyle(e).animationDelay) * 1000)`);
      expect(stagger.length > 3 && stagger.every((d, i) => i === 0 || d - stagger[i - 1] === 40) && Math.max(...stagger) <= 400, `stagger on open: 40 ms apart, at most 400 ms (${stagger.join(", ")})`);
      await sleep(300);
    } else {
      await sleep(200);
      const anim = await p.evaluate(`getComputedStyle(document.querySelector("#nav-drawer .nav-stagger")).animationName`);
      expect(anim === "none", "no stagger on close");
    }
  }

  console.log("Reduced motion");
  await p.send("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
  await p.evaluate(`document.getElementById("nav-toggle").click()`); await sleep(40);
  const rm = await p.evaluate(`(() => { const d = getComputedStyle(document.getElementById("nav-drawer")); const s = getComputedStyle(document.querySelector("#nav-drawer .nav-stagger"));
    return { transform: d.transform, props: d.transitionProperty, dur: d.transitionDuration, anim: s.animationName }; })()`);
  expect(rm.transform === "none" && rm.props.startsWith("opacity") && rm.dur.startsWith("0.12s") && rm.anim === "none", `a quick fade only: no slide, no stagger (${JSON.stringify(rm)})`);
  await p.send("Emulation.setEmulatedMedia", { features: [] });

  console.log(`\n${failures.length} failed check(s), ${p.errors.length} browser error(s)`);
  if (failures.length || p.errors.length) throw new Error("drawer motion check failed");
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
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-motion-"))}`,
    "--window-size=1440,900", "--hide-scrollbars", "about:blank"], { stdio: "ignore" });
  try {
    let target;
    for (let i = 0; i < 40 && !target; i++) { await sleep(250); target = await fetch(`http://127.0.0.1:${PORT}/json/list`).then((r) => r.json()).then((l) => l.find((t) => t.type === "page")).catch(() => null); }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener("open", r, { once: true }));
    const p = connect(ws);
    await p.send("Page.enable"); await p.send("Runtime.enable"); await p.send("Performance.enable");
    await run(p);
    ws.close();
  } finally { stopBrowser(browser); }
}
main().catch((e) => { console.error(e.message); process.exit(1); });
