// End-to-end browser check of both dashboards, saving a screenshot of every step.
//
//   node scripts/browser_check.mjs [outDir]        (default docs/screenshots/phase2)
//
// Needs the stack running (run_all.bat: API on 8080, frontend on 5173) on a freshly seeded demo
// database (api\yii.bat seed demo). Drives the installed Edge or Chrome headless over the
// DevTools protocol with Node's built-in fetch and WebSocket - no packages to install.
// It changes data the way a user would: raises a directive, resolves it, records and closes a
// corrective action. Reseed afterwards for a clean demo.
import { spawn } from "node:child_process";
import { mkdirSync, mkdtempSync, writeFileSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const OUT = resolve(process.argv[2] ?? "docs/screenshots/phase2");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9223;
const BROWSERS = [
  "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
  "C:/Program Files/Google/Chrome/Application/chrome.exe",
];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];

async function login(email) {
  const r = await fetch(`${API}/auth/login`, {
    method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: "demo123" }),   // demo-only accounts
  });
  if (!r.ok) throw new Error(`login ${email}: ${r.status}`);
  return (await r.json()).access_token;
}

class Page {
  constructor(ws) { this.ws = ws; this.id = 0; this.pending = new Map(); this.errors = [];
    ws.addEventListener("message", (e) => {
      const msg = JSON.parse(e.data);
      if (msg.id && this.pending.has(msg.id)) { this.pending.get(msg.id)(msg); this.pending.delete(msg.id); }
      if (msg.method === "Runtime.exceptionThrown") this.errors.push(msg.params.exceptionDetails.text);
      if (msg.method === "Log.entryAdded" && msg.params.entry.level === "error") this.errors.push(msg.params.entry.text);
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
  async goto(url, wait = 3500) { await this.send("Page.navigate", { url }); await sleep(wait); }
  async click(text, selector = "button") {
    const ok = await this.eval(`(() => { const el = [...document.querySelectorAll(${JSON.stringify(selector)})]
      .find((b) => b.textContent.trim().startsWith(${JSON.stringify(text)})); if (el) el.click(); return !!el; })()`);
    if (!ok) throw new Error(`no ${selector} starting "${text}"`);
    await sleep(1800);
  }
  async type(selector, text) {
    await this.eval(`(() => { const el = document.querySelector(${JSON.stringify(selector)});
      const proto = el.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(proto, "value").set.call(el, ${JSON.stringify(text)});
      el.dispatchEvent(new Event("input", { bubbles: true })); })()`);
  }
  async text() { return this.eval("document.body.innerText"); }
  async shot(name, note) {
    const { data } = await this.send("Page.captureScreenshot", { format: "png" });
    writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, "base64"));
    results.push({ name, note, errors: this.errors.splice(0) });
    console.log(`  saved ${name}.png - ${note}`);
  }
  async escape() {
    await this.send("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
    await this.send("Input.dispatchKeyEvent", { type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
    await sleep(500);
  }
  async as(email, path) {
    const token = await login(email);
    await this.goto(`${APP}/login`, 1500);
    await this.eval(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)})`);
    await this.goto(`${APP}${path}`, 4500);
  }
}

function expect(condition, message) {
  if (!condition) throw new Error(`check failed: ${message}`);
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  if (!exe) throw new Error("Edge or Chrome not found");
  const profile = mkdtempSync(join(tmpdir(), "cs-check-"));
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
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
    await page.send("Log.enable");
    await page.send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    console.log("Government");
    await page.goto(`${APP}/login`, 2500);
    await page.shot("01-login", "login page with the new quick-fill accounts, demo footer and GEM credit");
    await page.as("gov@dgms.gov.in", "/gov");
    let text = await page.text();
    expect(text.includes("83.2") && text.includes("Bhubaneswari Coal Mine"), "national overview shows 83.2 and the demo mines");
    await page.shot("02-gov-overview", "national overview: average 83.2, 6 / 21 / 47, the five worst mines");
    await page.click("Priority Queue");
    await page.shot("03-gov-priority", "inspection priority queue with translated reasons");
    await page.click("Sensors");
    await page.shot("04-gov-sensors", "fleet sensor standing against the rules.yaml limits");
    await page.click("Trends");
    await page.shot("05-gov-trends", "national breach frequency, 6-hour buckets");

    await page.goto(`${APP}/gov/mines/5`, 4500);
    text = await page.text();
    expect(text.includes("45") && text.includes("OD-TLC-05"), "Bhubaneswari scores 45");
    await page.shot("06-gov-mine-detail", "Bhubaneswari (OD-TLC-05): score 45, arithmetic, alerts");
    await page.click("Flag for inspection");
    await sleep(1500);
    await page.shot("07-gov-directive-raised", "directive raised: toast and the directive on top of the alerts");
    await page.click("Violations (");
    await page.shot("08-gov-violations", "violations drawer: all categories, open and resolved");
    await page.escape();
    await page.click("Corrective actions (");
    await page.shot("09-gov-corrective-actions", "corrective actions with overdue flags");
    await page.escape();
    await page.click("Incidents (");
    await page.click("Dangerous occurrence", "td strong");
    await page.shot("10-gov-incident-detail", "incident detail: 48-hour check citing RPT-05, linked strata violation");
    await page.escape();
    await page.escape();
    await page.click("Audit trail (");
    await page.shot("11-gov-audit-trail", "audit trail of the mine (hash-chained)");
    await page.escape();
    await page.click("Sensor trends");
    await page.shot("12-gov-sensor-trends", "sensor trend drawer with legal limit lines");
    await page.escape();

    console.log("Mine head (Bhubaneswari)");
    await page.as("head.od-tlc-05@coalmine.in", "/mine");
    await page.shot("13-head-overview", "mine head overview: own mine only, open directive from DGMS");
    await page.click("Resolve with proof");
    await page.type("textarea", "Bench 3 re-bolted and examined by the overman; shift briefed on PPE.");
    await sleep(300);
    await page.shot("14-head-resolve-directive", "resolving the directive with proof");
    await page.click("Submit resolution");
    await sleep(1500);
    await page.shot("15-head-directive-resolved", "directive resolved; visible to DGMS with the proof");
    await page.click("Violations (");
    await page.click("No helmet", "td strong");
    await page.click("Record corrective action");
    await page.type("textarea", "Issue helmets at the bench entry and add a PPE check to the shift start.");
    await sleep(300);
    await page.shot("16-head-record-action", "recording a corrective action for a PPE violation");
    await page.click("Save action");
    await page.escape();
    await page.escape();
    await page.click("Corrective actions (");
    await page.click("Issue helmets at the bench entry", "td");
    await page.click("Resolve with proof");
    await page.type("textarea", "Helmets issued; PPE check added to the shift-start briefing.");
    await page.click("Submit");
    await page.escape();
    await page.escape();
    await sleep(2500);
    text = await page.text();
    expect(text.includes("50") && text.includes("Medium risk"), "score rose from 45 to 50, band medium");
    await page.shot("17-head-score-recovered", "action closed with proof: violation resolved, 45 -> 50, High -> Medium");
    await page.click("Sensors");
    await page.shot("18-head-sensors", "own-mine sensors: dust as an 8-hour average (HLT-04), wet bulb (HLT-05)");
    await page.click("Trends");
    await page.shot("19-head-trends", "own-mine breach frequency");

    console.log("Corporate (SECL)");
    await page.as("corporate.secl@coalmine.in", "/gov");
    text = await page.text();
    expect(text.includes("SECL"), "corporate sees SECL scope");
    await page.shot("20-corporate-overview", "corporate: only SECL's 17 mines, same screens");
    await page.goto(`${APP}/gov/mines/5`, 4000);
    text = await page.text();
    expect(text.includes("Not available"), "another company's mine is 404");
    await page.shot("21-corporate-out-of-scope", "another company's mine answers 404, shown as not available");

    ws.close();
  } finally {
    browser.kill();
  }
  writeFileSync(join(OUT, "results.json"), JSON.stringify(results, null, 2));
  const withErrors = results.filter((r) => r.errors.length);
  console.log(`\n${results.length} screenshots in ${OUT}; ${withErrors.length} step(s) logged browser errors.`);
  for (const r of withErrors) console.log(`  ${r.name}: ${r.errors.slice(0, 3).join(" | ")}`);
}

main().catch((e) => { console.error(e.message); process.exit(1); });
