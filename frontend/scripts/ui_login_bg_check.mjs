// Login and public grievance pages with the background photo: the image loads (AVIF/WebP/JPEG set,
// navy first), covers the window without scrollbars at 360, 1366, 1440 and 1920 px, the overflow
// probe finds nothing, and every text drawn directly on the photo meets WCAG AA even over the
// lightest possible pixel under the thinnest part of the navy wash. Reduced motion: no fade.
// Screenshots: docs/screenshots/ui-login/.
//
//   node frontend/scripts/ui_login_bg_check.mjs     (needs the frontend on 5173)
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-login");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const PORT = 9235;
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const source = readFileSync(join(ROOT, "scripts", "browser_check.mjs"), "utf8");
const OVERFLOW_PROBE = new Function(`return \`${source.match(/const OVERFLOW_PROBE = `([\s\S]*?)`;\r?\n/)[1]}\`;`)();

// Text whose nearest painted background is the photo (not a white card or a navy strip): its
// contrast against white blended under the lightest wash (--bg-shade-edge), the worst case.
const CONTRAST = `(() => {
  const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
  const lum = ([r, g, b]) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  const rgb = (s) => (s.match(/[\\d.]+/g) || []).map(Number);
  const edge = rgb(getComputedStyle(document.documentElement).getPropertyValue("--bg-shade-edge"));
  const a = edge[3];
  const bg = [0, 1, 2].map((i) => edge[i] * a + 255 * (1 - a));
  const out = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const el = walker.currentNode.parentElement; const text = walker.currentNode.textContent.trim();
    if (!text || !el || !el.offsetParent || el.closest(".brand-bg, .sr-only, option")) continue;
    let p = el, onPhoto = false;
    for (; p; p = p.parentElement) {
      const cs = getComputedStyle(p);
      if (p.classList?.contains("has-bg")) { onPhoto = true; break; }
      const c = rgb(cs.backgroundColor); if (c.length === 3 || (c.length === 4 && c[3] > 0.5)) break;
    }
    if (!onPhoto) continue;
    const fg = rgb(getComputedStyle(el).color).slice(0, 3);
    const l1 = lum(fg), l2 = lum(bg);
    const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    out.push({ text: text.slice(0, 40), ratio: Math.round(ratio * 100) / 100 });
  }
  return out;
})()`;

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
  const loaded = async () => { for (let i = 0; i < 40; i++) { if (await p.evaluate(`!!document.querySelector(".brand-bg img.is-loaded")`)) return true; await sleep(250); } return false; };
  await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(800);
  await p.evaluate(`sessionStorage.clear(); localStorage.setItem("smg.lang", "en")`);
  for (const [name, path] of [["login", "/login"], ["grievance-raise", "/grievance"], ["grievance-track", "/grievance/track"]]) {
    for (const w of [1366, 1440, 1920, 360]) {
      await width(w);
      await p.send("Page.navigate", { url: `${APP}${path}` }); await sleep(800);
      const ok = await loaded(); await sleep(700);
      const g = await p.evaluate(`(() => { const img = document.querySelector(".brand-bg img"), bg = document.querySelector(".brand-bg"), cs = getComputedStyle(img);
        return { src: img.currentSrc.split("/").pop(), fit: cs.objectFit, pos: cs.objectPosition, fixed: getComputedStyle(bg).position, navy: getComputedStyle(bg).backgroundColor,
                 sideways: document.documentElement.scrollWidth > document.documentElement.clientWidth, cover: Math.round(bg.getBoundingClientRect().width) === document.documentElement.clientWidth }; })()`);
      const overflow = await p.evaluate(OVERFLOW_PROBE);
      const contrast = await p.evaluate(CONTRAST);
      const worst = contrast.reduce((m, c) => Math.min(m, c.ratio), 99);
      expect(ok && g.fit === "cover" && g.pos === "50% 65%" && g.fixed === "fixed" && g.cover && !g.sideways && g.navy === "rgb(15, 23, 42)",
        `${name} ${w} px: ${g.src} loaded, cover at 50% 65%, fixed, navy underneath, no sideways scroll`);
      expect(overflow.length === 0, `${name} ${w} px: overflow probe ${overflow.length} finding(s)${overflow.length ? " " + JSON.stringify(overflow.slice(0, 3)) : ""}`);
      expect(contrast.length > 0 && worst >= 4.5, `${name} ${w} px: ${contrast.length} text(s) on the photo, worst case ${worst}:1 (AA 4.5)`);
      if (name === "login" && (w === 1440 || w === 360)) {
        const { data } = await p.send("Page.captureScreenshot", { format: "png" });
        writeFileSync(join(OUT, `login-bg-${w}.png`), Buffer.from(data, "base64")); console.log(`  saved login-bg-${w}.png`);
      }
    }
  }
  await width(1440);
  await p.send("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
  await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(1500);
  const rm = await p.evaluate(`getComputedStyle(document.querySelector(".brand-bg img")).transitionDuration`);
  expect(rm === "0s", `reduced motion: no fade (${rm})`);
  await p.send("Emulation.setEmulatedMedia", { features: [] });
  console.log(`\n${failures.length} failed check(s), ${p.errors.length} browser error(s)`);
  if (failures.length || p.errors.length) throw new Error("login background check failed");
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-bg-"))}`,
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
