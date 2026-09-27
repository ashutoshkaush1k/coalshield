// End-to-end browser check of both dashboards, saving a screenshot of every step.
//
//   node scripts/browser_check.mjs [phase2|phase3|phase4] [outDir] [--side-tabs]   (default phase2, docs/screenshots/<phase>)
//   --side-tabs  also keep a government overview and a mine-head dashboard polling in two more tabs
//
// phase2: both dashboards - overview, drill-down, directive loop, corrective actions, incidents.
// phase3: contractor screens for the S4 mine head, government and corporate (NCL).
// phase4: production - government, the Gevra mine head and corporate (SECL): numbers, the detail
//         gate, call for detailed report -> response -> detail view, entry, submit, correction.
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

const ARGS = process.argv.slice(2).filter((a) => !a.startsWith("--"));
const PHASE = ARGS[0] ?? "phase2";
const OUT = resolve(ARGS[1] ?? `docs/screenshots/${PHASE}`);
// --side-tabs: keep a government overview and a mine-head dashboard open and polling in two more
// tabs for the whole run - two dashboards side by side while the checked one is driven.
const SIDE_TABS = process.argv.includes("--side-tabs");
const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..");
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
  /** Click the first `selector` whose text starts with `text`, waiting up to 20 s for it to appear. */
  async click(text, selector = "button") {
    for (let waited = 0; ; waited += 500) {
      const ok = await this.eval(`(() => { const el = [...document.querySelectorAll(${JSON.stringify(selector)})]
        .find((b) => b.textContent.trim().startsWith(${JSON.stringify(text)})); if (el) el.click(); return !!el; })()`);
      if (ok) break;
      if (waited >= 20000) throw new Error(`no ${selector} starting "${text}"`);
      await sleep(500);
    }
    await sleep(1800);
  }
  async type(selector, text) {
    await this.eval(`(() => { const el = document.querySelector(${JSON.stringify(selector)});
      const proto = el.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(proto, "value").set.call(el, ${JSON.stringify(text)});
      el.dispatchEvent(new Event("input", { bubbles: true })); })()`);
  }
  async text() { return this.eval("document.body.innerText"); }
  /** The page text once `test(text)` holds, polling for up to 20 s (slow API responses); the last text otherwise. */
  async until(test, timeout = 20000) {
    for (let waited = 0; ; waited += 500) {
      const text = await this.text();
      if (test(text) || waited >= timeout) return text;
      await sleep(500);
    }
  }
  async setFile(selector, path) {
    const { root } = await this.send("DOM.getDocument", { depth: -1 });
    const { nodeId } = await this.send("DOM.querySelector", { nodeId: root.nodeId, selector });
    if (!nodeId) throw new Error(`no ${selector}`);
    await this.send("DOM.setFileInputFiles", { nodeId, files: [path] });
    await sleep(300);
  }
  async scrollTo(text, selector = "h2") {
    await this.eval(`(() => { const el = [...document.querySelectorAll(${JSON.stringify(selector)})]
      .find((b) => b.textContent.trim().startsWith(${JSON.stringify(text)})); if (el) el.scrollIntoView({ block: "start" }); })()`);
    await sleep(600);
  }
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

async function phase2(page) {
  let text;
  console.log("Government");
  await page.goto(`${APP}/login`, 2500);
  await page.shot("01-login", "login page with the new quick-fill accounts, demo footer and GEM credit");
  await page.as("gov@dgms.gov.in", "/gov");
  text = await page.text();
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
}

async function phase3(page) {
  const S4 = "Prakash Infra Projects";
  let text;

  console.log("Mine head of MP-SIN-42 (Block-B, NCL) - the S4 mine");
  await page.as("head.mp-sin-42@coalmine.in", "/mine");
  await page.click("Contractors", "button[role=tab]");
  text = await page.until((t) => t.includes(S4) && t.includes("Goswami"));
  expect(text.indexOf(S4) > -1 && text.indexOf(S4) < text.indexOf("Goswami"), "S4 is listed first");
  expect(text.includes("Flagged"), "S4 is flagged");
  await page.shot("01-head-contractor-list", "mine head: contractors worst first - S4 flagged at the top, with what drives it");
  await page.click(S4, "td strong");
  await sleep(2500);
  await page.shot("02-head-s4-overview", "S4 overview: reasons, score arithmetic, licence (OSH Code s.48(3), LAB-02)");
  await page.click("Documents", "button[role=tab]");
  await page.shot("03-head-s4-missing-documents", "S4 documents: missing wage registers and EPF challans, month by month");
  await page.click("Violations", "button[role=tab]");
  await page.shot("04-head-s4-violations", "S4's linked violations");
  await page.click("Alerts", "button[role=tab]");
  await page.shot("05-head-s4-alerts", "S4's alerts: CONTRACTOR_DOC_MISSING per month, medicals overdue (HLT-01)");
  await page.click("Workers", "button[role=tab]");
  await page.shot("06-head-s4-workers", "S4 workers with VT (SAF-04) and medical (HLT-01) status");

  await page.click("Documents", "button[role=tab]");
  await page.click("Upload", "td button");
  await page.setFile(".modal input[type=file]", resolve(ROOT, "backend/data/samples/images/with_ppe.jpg"));
  await page.shot("07-head-upload-document", "uploading a missing month's document (preset from the missing row)");
  await page.click("Upload", ".modal .overlay-foot button");
  await sleep(2500);
  await page.shot("08-head-document-uploaded", "uploaded: one missing document fewer, awaiting verification");
  await page.escape();

  await page.click("Register contractor");
  const fields = { "cn-name": "Test Haulage Co", "cn-registration_no": "REG-TEST-0001", "cn-labour_licence_no": "LL/TEST/0001",
    "cn-epf_code": "EPF-TEST-0001", "cn-esi_code": "ESI-TEST-0001", "cn-contact": "+91-00000-00000",
    "cn-licence": "2026-10-15", "cn-c-wo": "WO/MP-SIN-42/2026/9001", "cn-c-val": "2500000", "cn-c-end": "2027-09-30" };
  for (const [id, value] of Object.entries(fields)) await page.type(`#${id}`, value);
  await page.shot("09-head-register-contractor", "registering a contractor with its first contract at this mine");
  await page.click("Save", ".modal .overlay-foot button");
  text = await page.until((t) => t.includes("Test Haulage Co"));
  expect(text.includes("Test Haulage Co"), "new contractor listed");
  await page.shot("10-head-contractor-registered", "registered: licence expiring within 30 days already counts against it");

  await page.click("Overview", "button[role=tab]");
  await page.click("Violations (");
  await page.click("No ", "td strong");
  await page.shot("11-head-violation-contractor-select", "violation detail: responsible-contractor selector");
  await page.escape();
  await page.escape();

  console.log("Government");
  await page.as("gov@dgms.gov.in", "/gov");
  const firstFlagged = async () => {
    for (let waited = 0; waited < 20000; waited += 500) {
      const name = await page.eval(`document.querySelector(".flagged-list li strong")?.textContent`);
      if (name) return name;
      await sleep(500);
    }
    return null;
  };
  const govFirst = await firstFlagged();
  await page.scrollTo("Contractor compliance");
  expect(govFirst === S4, "S4 first on the government overview card");
  await page.shot("12-gov-overview-contractor-card", "government overview: contractor compliance card, S4 first among flagged");
  await page.click("Contractors", "button[role=tab]");
  await sleep(3000);
  await page.shot("13-gov-contractors-per-mine", "read-only summary per mine: count, compliance %, flagged, blacklisted");
  await page.scrollTo("All contractors");
  await page.shot("14-gov-all-contractors", "all contractors nationally, worst first - S4 at the top");
  await page.click(S4, "td strong");
  await sleep(2500);
  text = await page.text();
  expect(!text.includes("Register contractor") && !text.includes("Change status"), "government view is read-only");
  await page.shot("15-gov-s4-detail", "S4 detail for government: all five contracts, read-only");
  await page.escape();

  console.log("Corporate (NCL)");
  await page.as("corporate.ncl@coalmine.in", "/gov");
  await page.click("Contractors", "button[role=tab]");
  expect((await firstFlagged()) === S4, "S4 first for corporate NCL");
  await page.shot("16-corporate-contractors", "corporate NCL: its mines only; S4 flagged first");
}

async function phase4(page) {
  const GEVRA = 'tr[data-mine="CG-KRB-03"]';
  const tab = (name) => page.click(name, "button[role=tab]");
  let text;

  console.log("Government: numbers only, Gevra flagged (S2), call for a detailed report");
  await page.as("gov@dgms.gov.in", "/gov");
  await tab("Production");
  text = await page.until((t) => t.includes("Production across mines") && t.includes("Gevra"));
  expect(await page.eval(`!!document.querySelector('${GEVRA}.is-flagged')`), "Gevra (S2) is flagged");
  expect(await page.eval(`!document.querySelector('${GEVRA}').innerText.includes('Pending')`), "no request for Gevra yet");
  await page.shot("01-gov-production-numbers", "government: numbers only per mine - day and month to date, anomaly flag (Gevra, S2, 4 Sep)");
  await page.click("View detail", `${GEVRA} button`);
  await page.until((t) => t.includes("Detailed report required"));
  await page.shot("02-gov-detail-required", "detail before any answered request: 403 DETAIL_REQUEST_REQUIRED, explained");
  await page.escape();
  await page.click("Call for detailed report", `${GEVRA} button`);
  await page.shot("03-gov-call-for-report", "Call for Detailed Report: range around the flagged day, reason prefilled from the anomaly, deadline");
  await page.click("Send request", ".modal .overlay-foot button");
  await page.until((t) => t.includes("Request sent to the mine"));
  expect(await page.eval(`document.querySelector('${GEVRA}').innerText.includes('Pending')`), "request pending");
  await page.scrollTo("Calls for detailed report");
  await page.shot("04-gov-request-pending", "the request, pending; overdue ones escalate automatically with an alert");

  console.log("Mine head of Gevra: entry, submit, correction with reason, answer the request");
  await page.as("head.cg-krb-03@coalmine.in", "/mine");
  await tab("Production");
  await page.until((t) => t.includes("Target vs actual") && t.includes("Pending"));
  await page.shot("05-head-production", "mine head: inbox with the pending call, charts - target vs actual (anomaly marked), cumulative, shift split");
  await page.click("New entry", "#production-new");
  const figures = { "pe-coal_target_t": "3350", "pe-coal_actual_t": "3120.5", "pe-ob_target_m3": "9800", "pe-ob_actual_m3": "9400",
    "pe-dispatch_t": "2900", "pe-closing_stock_t": "41250", "pe-breakdown_hours": "1.5", "pe-manpower_present": "640",
    "pe-remarks": "Dragline 3 down for 90 minutes" };
  for (const [id, value] of Object.entries(figures)) await page.type(`#${id}`, value);
  await page.shot("06-head-entry-form", "daily entry form: one shift's figures");
  await page.click("Submit", "#pe-submit");
  await page.until((t) => t.includes("Entry submitted and locked"));
  await page.scrollTo("Entries");
  await page.shot("07-head-entry-submitted", "submitted: the entry is locked");
  await page.click("Correct with reason", "tr[data-entry] button");
  await page.type("#pe-coal_actual_t", "3080.5");
  await page.type("#pe-reason", "Weighbridge reading corrected after reconciliation");
  await page.shot("08-head-correction-form", "correcting a locked entry needs a reason");
  await page.click("Save correction", ".modal .overlay-foot button");
  text = await page.until((t) => t.includes("Correction saved and logged") && t.includes("3120.5 → 3080.5"));
  expect(text.includes("3120.5 → 3080.5"), "edit log shows old and new value");
  await page.scrollTo("Entries");
  await page.shot("09-head-edit-log", "the correction in the edit log: field, old -> new, reason, who, when");
  await page.scrollTo("Calls for detailed report");
  await page.click("Respond", "#production-inbox button");
  await page.type("#dr-note", "Shift-wise registers, weighbridge slips and the dragline log for 1-7 September attached. The 4 September figure includes a backlog of 2 days' dispatch.");
  await page.setFile("#dr-file", resolve(ROOT, "backend/data/samples/images/with_ppe.jpg"));
  await page.shot("10-head-respond", "answering the call: note and attachment");
  await page.click("Respond", ".modal .overlay-foot button");
  await page.until((t) => t.includes("Response sent"));
  await page.shot("11-head-request-answered", "answered: status submitted");

  console.log("Government: detail opens for the answered range; accept and close");
  await page.as("gov@dgms.gov.in", "/gov");
  await tab("Production");
  await page.until((t) => t.includes("Production across mines") && t.includes("Gevra"));
  expect(await page.eval(`document.querySelector('${GEVRA}').innerText.includes('Submitted')`), "request answered");
  await page.click("View detail", `${GEVRA} button`);
  text = await page.until((t) => t.includes("Response from the mine") && t.includes("Shift entries"));
  expect(text.includes("Shift-wise registers, weighbridge slips"), "the mine's response is shown");
  await page.shot("12-gov-detail-view", "detail view for the answered range: the mine's response, charts, every shift entry");
  await page.escape();
  await page.scrollTo("Calls for detailed report");
  await page.click("Accept and close", "#production-requests button");
  await page.until((t) => t.includes("Request closed"));
  await page.shot("13-gov-request-closed", "accepted and closed");

  console.log("Corporate (SECL): its 17 mines; the closed request opens Gevra's detail");
  await page.as("corporate.secl@coalmine.in", "/gov");
  await tab("Production");
  text = await page.until((t) => t.includes("Production across mines") && t.includes("Gevra"));
  expect(await page.eval(`document.querySelectorAll('tr[data-mine]').length`) === 17, "corporate sees SECL's 17 mines");
  await page.shot("14-corporate-production", "corporate SECL: the same numbers-only table for its 17 mines");
  await page.click("View detail", `${GEVRA} button`);
  await page.until((t) => t.includes("Response from the mine"));
  await page.shot("15-corporate-detail", "corporate: Gevra's detail, opened by the answered (closed) request");
}

/** Two more tabs, a government overview and a mine-head dashboard, polling on their own. */
async function openSideTabs() {
  const tabs = [];
  for (const [who, path] of [["gov@dgms.gov.in", "/gov"], ["head.od-tlc-05@coalmine.in", "/mine"]]) {
    const target = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: "PUT" }).then((r) => r.json());
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener("open", r, { once: true }));
    const tab = new Page(ws);
    let count = 0, errors = 0;
    const started = Date.now();
    ws.addEventListener("message", (e) => {
      const msg = JSON.parse(e.data);
      if (msg.method === "Network.responseReceived" && msg.params.response.url.includes("/v1/")) {
        count++;
        if (msg.params.response.status >= 500) errors++;
      }
      if (msg.method === "Network.loadingFailed") errors++;
    });
    await tab.send("Page.enable");
    await tab.send("Runtime.enable");
    await tab.send("Network.enable");
    await tab.as(who, path);
    tabs.push({ who, ws, requests: () => ({ count, errors, seconds: (Date.now() - started) / 1000 }) });
    console.log(`  side tab open: ${who} on ${path}`);
  }
  return tabs;
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  if (!exe) throw new Error("Edge or Chrome not found");
  const profile = mkdtempSync(join(tmpdir(), "cs-check-"));
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    "--window-size=1440,900", "--hide-scrollbars", "--disable-background-timer-throttling",
    "--disable-renderer-backgrounding", "--disable-backgrounding-occluded-windows", "about:blank"], { stdio: "ignore" });
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
    await page.send("DOM.enable");
    await page.send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    const side = SIDE_TABS ? await openSideTabs() : [];
    // A new tab takes the foreground, and a background tab's screenshot can wait forever:
    // bring the checked tab back to the front.
    if (side.length) await fetch(`http://127.0.0.1:${PORT}/json/activate/${target.id}`, { method: "PUT" }).catch(() => null);

    try {
      await ({ phase2, phase3, phase4 }[PHASE] ?? phase2)(page);
    } catch (e) {
      // Keep what the page showed when a check failed, for diagnosis.
      const { data } = await page.send("Page.captureScreenshot", { format: "png" }).catch(() => ({}));
      if (data) writeFileSync(join(OUT, "failure.png"), Buffer.from(data, "base64"));
      writeFileSync(join(OUT, "failure.txt"), await page.text().catch(() => ""));
      throw e;
    }

    ws.close();
    for (const tab of side) {
      const polls = tab.requests();
      console.log(`  side tab ${tab.who}: ${polls.count} API requests in ${Math.round(polls.seconds)} s, ${polls.errors} failed`);
      results.push({ name: `side-tab ${tab.who}`, note: `${polls.count} API requests in ${Math.round(polls.seconds)} s`, errors: polls.errors ? [`${polls.errors} failed requests`] : [] });
      tab.ws.close();
    }
  } finally {
    browser.kill();
  }
  writeFileSync(join(OUT, "results.json"), JSON.stringify(results, null, 2));
  const withErrors = results.filter((r) => r.errors.length);
  console.log(`\n${results.filter((r) => !r.name.startsWith("side-tab")).length} screenshots in ${OUT}; ${withErrors.length} step(s) logged browser errors.`);
  for (const r of withErrors) console.log(`  ${r.name}: ${r.errors.slice(0, 3).join(" | ")}`);
}

main().catch((e) => { console.error(e.message); process.exit(1); });
