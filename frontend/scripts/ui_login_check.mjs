// Password show / hide toggle on the dashboard login and the field app login, at 360 px: toggles
// with a click and with the keyboard, switches its aria-label, never submits, never overlaps the
// text, and the password is hidden again on reload and after sign-in. Screenshots in
// docs/screenshots/ui-login/.
//
//   node frontend/scripts/ui_login_check.mjs     (needs the stack running: run_all.bat)
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-login");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const PORT = 9228;
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

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
  const waitFor = async (sel) => { for (let i = 0; i < 60; i++) { if (await p.evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return; await sleep(300); } throw new Error(`no ${sel}`); };
  const shot = async (name) => { const { data } = await p.send("Page.captureScreenshot", { format: "png" }); writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, "base64")); console.log(`  saved ${name}.png`); };
  const state = (id) => p.evaluate(`(() => { const i = document.getElementById(${JSON.stringify(id)}), b = i.parentElement.querySelector(".password-toggle");
    const ir = i.getBoundingClientRect(), br = b.getBoundingClientRect(), textRight = ir.right - parseFloat(getComputedStyle(i).paddingRight);
    return { type: i.type, label: b.getAttribute("aria-label"), buttonType: b.type, inside: br.left >= ir.left && br.right <= ir.right + 0.5 && br.top >= ir.top - 0.5 && br.bottom <= ir.bottom + 0.5,
             clear: textRight <= br.left + 0.5, height: Math.round(ir.height), path: location.pathname, sideways: document.documentElement.scrollWidth > document.documentElement.clientWidth }; })()`);
  const typeInto = async (id, text) => {
    await p.evaluate(`document.getElementById(${JSON.stringify(id)}).focus()`);
    await p.send("Input.insertText", { text });
  };
  const key = async (k, code, vk, text) => { await p.send("Input.dispatchKeyEvent", { type: "keyDown", key: k, code, windowsVirtualKeyCode: vk, text }); await p.send("Input.dispatchKeyEvent", { type: "keyUp", key: k, code, windowsVirtualKeyCode: vk }); };
  await p.send("Emulation.setDeviceMetricsOverride", { width: 360, height: 780, deviceScaleFactor: 1, mobile: true });

  for (const [name, path, id] of [["dashboard", "/login", "password"], ["field", "/field", "field-password"]]) {
    console.log(`${name} login (${path}, 360 px)`);
    await p.send("Page.navigate", { url: `${APP}${path}` }); await sleep(1500);
    await p.evaluate(`sessionStorage.clear(); localStorage.setItem("smg.lang", "en")`);
    await p.send("Page.navigate", { url: `${APP}${path}` }); await sleep(1500);
    await waitFor(`#${id}`);
    await p.evaluate(`(() => { const i = document.getElementById(${JSON.stringify(id)}); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(i, ""); i.dispatchEvent(new Event("input", { bubbles: true })); })()`);
    await typeInto(id, "a-long-demo-password-123");
    let s = await state(id);
    const heightHidden = s.height;
    expect(s.type === "password" && s.label === "Show password" && s.buttonType === "button", "hidden at first; button says Show password and is type=button");
    expect(s.inside && s.clear && !s.sideways, "button inside the input's right edge, clear of the text, no sideways scroll");
    if (name === "dashboard") await shot("dashboard-login-hidden");
    await p.evaluate(`document.querySelector("#${id}").parentElement.querySelector(".password-toggle").click()`); await sleep(300);
    s = await state(id);
    expect(s.type === "text" && s.label === "Hide password" && s.path === path, "click shows it, label switches, nothing submitted");
    expect(s.height === heightHidden, "no layout shift");
    await shot(`${name}-login-shown`);
    await p.evaluate(`document.querySelector("#${id}").parentElement.querySelector(".password-toggle").focus()`);
    await key("Enter", "Enter", 13, "\r"); await sleep(300);
    s = await state(id);
    expect(s.type === "password" && s.path === path, "Enter on the button hides it again without submitting");
    await key(" ", "Space", 32, " "); await sleep(300);
    expect((await state(id)).type === "text", "Space toggles it too");
    await p.send("Page.reload"); await sleep(1800); await waitFor(`#${id}`);
    expect((await state(id)).type === "password", "hidden again after reload");
  }

  console.log("dashboard: hidden again after sign-in");
  await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(1500); await waitFor("#password");
  await p.evaluate(`(() => { const set = (id, v) => { const i = document.getElementById(id); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(i, v); i.dispatchEvent(new Event("input", { bubbles: true })); };
    set("email", "gov@dgms.gov.in"); set("password", "demo123"); })()`);   // demo-only account
  await p.evaluate(`document.querySelector(".password-toggle").click()`); await sleep(200);
  await p.evaluate(`(() => { window.__seen = []; const i = document.getElementById("password"); new MutationObserver(() => window.__seen.push(i.type)).observe(i, { attributes: true, attributeFilter: ["type"] }); })()`);
  await p.evaluate(`document.querySelector("form button[type=submit]").click()`); await sleep(200);
  const seen = await p.evaluate(`window.__seen`);
  expect(seen[0] === "password", `submitting hides the password first (${seen.join(",") || "no change"})`);
  await sleep(2500);
  expect(await p.evaluate(`location.pathname !== "/login"`), "signed in and left the login page (field unmounted)");
  await p.evaluate(`sessionStorage.clear()`);

  console.log(`\n${failures.length} failed check(s), ${p.errors.length} browser error(s)`);
  if (failures.length || p.errors.length) throw new Error("login check failed");
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-login-"))}`,
    "--window-size=360,780", "--hide-scrollbars", "about:blank"], { stdio: "ignore" });
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
