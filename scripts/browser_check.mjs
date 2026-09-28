// End-to-end browser check of both dashboards, saving a screenshot of every step.
//
//   node scripts/browser_check.mjs [phase2|phase3|phase4|phase5|phase5b|phase6|phase7|phase7b] [outDir] [--strict] [--side-tabs]   (default phase2, docs/screenshots/<phase>)
//   --side-tabs  also keep a government overview and a mine-head dashboard polling in two more tabs
//
// phase2: both dashboards - overview, drill-down, directive loop, corrective actions, incidents.
// phase3: contractor screens for the S4 mine head, government and corporate (NCL).
// phase4: production - government, the Gevra mine head and corporate (SECL): numbers, the detail
//         gate, call for detailed report -> response -> detail view, entry, submit, correction.
// phase5: grievances - public submission and tracking (no login), the Gevra mine head's queue
//         (no sensitive case, no identity), government analytics with the S6 cluster, a sensitive
//         case, corporate (SECL) scope.
// phase5b: the obligation register - the Gevra mine head submits evidence, government rejects
//         (reason required) then accepts, an overdue item escalates (yii obligation/check --at),
//         the register for each role; the map for all three roles with every off-machine request
//         blocked (no internet), and the street map failing gracefully.
// phase6: languages - the login switcher (kept in the browser), the saved preference winning after
//         login, the profile switching at once; then login, overview, mine detail, production,
//         grievances, obligations, map and profile in en, hi, bn, or, te and mr with the network
//         blocked, each screen probed for clipped or spilling text (overflow.json); --strict fails on any.
// phase7: automation - the jobs run with the ai-service unreachable (PHP fallback) and then with it
//         up; the Governance Risk Index beside the score and its components, the predicted risk
//         with its factors and the US-data statement, the detectors' findings with their engine,
//         the priority queue ordered by the index, ANOMALY_DETECTED and escalated alerts, the mine
//         head's view, and the panel in Hindi. Needs the ai-service running (run_all.bat).
// phase7b: the field app on a 360 px phone - sign in, go truly offline (its own field server is
//         stopped and the network emulated off), record two findings with photos and GPS (one far
//         from the mine), lose the login, come back online, sign in and sync, watch the government
//         and mine-head dashboards pick the findings up within one polling cycle, then resend the
//         same queue (as after a lost answer) and confirm nothing is duplicated.
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
  /** Choose an option of a <select> (by value, or by the start of its label) as a user would. */
  async select(selector, value) {
    await this.eval(`(() => { const el = document.querySelector(${JSON.stringify(selector)});
      const opt = [...el.options].find((o) => o.value === ${JSON.stringify(value)}) || [...el.options].find((o) => o.text.startsWith(${JSON.stringify(value)}));
      Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, "value").set.call(el, opt.value);
      el.dispatchEvent(new Event("change", { bubbles: true })); })()`);
    await sleep(200);
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
  /**
   * Sign in as `email` and open `path`. Phase 6: the account's saved language wins after login, so
   * this first saves `lang` to the account (PATCH /v1/users/me) - English unless a check asks for
   * another. Most seeded mine heads prefer their state's language; reseed after a check.
   */
  async as(email, path, lang = "en") {
    const token = await login(email);
    await fetch(`${API}/users/me`, { method: "PATCH", headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
      body: JSON.stringify({ preferred_language: lang }) });
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
  await page.setFile(".modal input[type=file]", resolve(ROOT, "ai-service/samples/images/with_ppe.jpg"));
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
  await page.setFile("#dr-file", resolve(ROOT, "ai-service/samples/images/with_ppe.jpg"));
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

async function phase5(page) {
  const HEAD = "head.cg-krb-03@coalmine.in";   // Gevra: its seed includes two harassment grievances
  const COMPLAINANT = "Ramesh Test-Complainant";
  const tab = (name) => page.click(name, "button[role=tab]");
  const api = async (email, path) => fetch(`${API}${path}`, { headers: { Authorization: `Bearer ${await login(email)}` } });
  let text;

  // What the mine head must never see: the sensitive grievances at its mine (read as government).
  const gevra = (await (await api(HEAD, "/users/me")).json()).mine_id;
  const sensitive = (await (await api("gov@dgms.gov.in", `/grievances?mine_id=${gevra}&sensitive=1`)).json()).map((g) => g.ticket_no);
  expect(sensitive.length > 0, "the seed has sensitive grievances at Gevra");

  console.log("Public: raise a grievance without logging in, then track it");
  await page.goto(`${APP}/login`, 2000);
  await page.shot("01-login-grievance-links", "login page: raise / track a grievance without an account");
  await page.click("Raise a grievance", "#grievance-links a");
  await page.until((t) => t.includes("Choose the mine") && t.includes("Gevra"));
  await page.select("#gr-mine", "Gevra");
  await page.select("#gr-type", "contract_worker");
  await page.select("#gr-language", "hi");
  await page.type("#gr-name", COMPLAINANT);
  await page.type("#gr-contact", "9000000042");
  await page.select("#gr-category", "safety");
  await page.select("#gr-safety", "ppe");
  await page.type("#gr-description", "बेंच 4 पर नए लोडरों को पिछले एक सप्ताह से हेलमेट नहीं दिए गए हैं। कृपया जाँच करें।");
  await page.shot("02-public-form", "public form: mine, who you are, category (safety: which kind), language, text as written; honeypot hidden");
  await page.click("Submit grievance", "#grievance-form button[type=submit]");
  text = await page.until((t) => t.includes("Grievance received"));
  const ticket = await page.eval(`document.querySelector("#grievance-ticket")?.textContent`);
  const code = await page.eval(`document.querySelector("#grievance-code")?.textContent`);
  expect(/^GRV-\d{4}-\d{6}$/.test(ticket ?? ""), "a GRV-YYYY-NNNNNN ticket");
  expect(/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/.test(code ?? ""), "an 8-character tracking code");
  await page.shot("03-public-ticket", `ticket ${ticket} and the tracking code, shown once, with the response due time`);
  await page.click("Track this grievance", "#grievance-done a");
  text = await page.until((t) => t.includes("What happened") && t.includes(ticket));
  expect(!text.includes(COMPLAINANT), "tracking shows no identity");
  expect(!(await page.eval("location.href")).includes(code), "the code is not in the URL");
  await page.shot("04-public-track", "tracking with ticket + code: status and the public timeline only - no text, no people");
  await page.type("#track-code", code === "AAAAAAAA" ? "BBBBBBBB" : "AAAAAAAA");
  await page.click("Track", "form button[type=submit]");
  await page.until((t) => t.includes("No grievance matches"));
  await page.shot("04b-public-track-wrong-code", "a wrong code: the same answer as an unknown ticket");

  console.log("Mine head of Gevra: its queue - never a sensitive grievance, never a name");
  await page.as(HEAD, "/mine");
  await tab("Grievances");
  text = await page.until((t) => t.includes(ticket));
  for (const t of sensitive) expect(!text.includes(t), `sensitive ${t} is not in the mine head's queue`);
  await page.shot("05-head-queue", "mine head: own mine's grievances, most urgent first; sensitive ones are routed to the regulator");
  await page.click(ticket, `tr[data-grievance="${ticket}"] td strong`);
  text = await page.until((t) => t.includes("Timeline") && t.includes("Identity not shown"));
  expect(!text.includes(COMPLAINANT) && !text.includes("9000000042"), "the complainant's identity is not shown to the mine head");
  await page.shot("06-head-detail", "detail: text as written in Hindi (labelled), identity not shown to this role, timeline, actions");
  await page.click("Start investigation", "#grievance-actions button");
  await page.until((t) => t.includes("Under investigation"));
  await page.type("#grievance-note", "Helmets issued to all 12 loaders on bench 4; stock checked with the contractor.");
  await page.click("Resolve", "#grievance-actions button");
  await page.until((t) => t.includes("Resolved") && t.includes("Helmets issued"));
  await page.shot("07-head-resolved", "resolved with a note - the note is what the complainant sees");
  const direct = await (await api(HEAD, `/grievances?sensitive=1`)).json();
  expect(Array.isArray(direct) && direct.length === 0, "the API gives the mine head no sensitive grievance either");

  console.log("Public: the outcome");
  await page.goto(`${APP}/grievance/track?ticket=${ticket}`, 2500);
  await page.type("#track-code", code);
  await page.click("Track", "form button[type=submit]");
  await page.until((t) => t.includes("Outcome"));
  await page.shot("08-public-track-resolved", "the complainant sees the status and the resolution");

  console.log("Government: analytics, the S6 cluster, the escalated queue, a sensitive case");
  await page.as("gov@dgms.gov.in", "/gov");
  await tab("Grievances");
  text = await page.until((t) => t.includes("SLA-breach clusters") && t.includes("Kulda"));
  const firstCluster = await page.eval(`document.querySelector("#grievance-clusters .flagged-list li strong")?.textContent`);
  expect(firstCluster === "Kulda Coal Mine", "S6: Kulda flagged as a breach cluster");
  expect(await page.eval(`document.querySelectorAll("#grievance-clusters .flagged-list li").length`) === 1, "only one mine flagged");
  await page.shot("09-gov-analytics", "government: totals, average time to resolution, SLA breaches, the Kulda breach cluster (S6)");
  await page.scrollTo("By mine");
  expect(await page.eval(`document.querySelector("#grievance-by-mine tbody tr")?.dataset.mine`) === "OD-SUN-07", "Kulda leads the mine table");
  expect(await page.eval(`!document.querySelector('#grievance-by-mine tr[data-mine="WB-BAR-08"].is-flagged')`), "N2: Jhanjra not flagged");
  await page.shot("10-gov-by-category-and-mine", "by category and language; by mine with the cluster flag (Jhanjra's in-SLA burst, N2, is not flagged)");
  await page.scrollTo("Escalated queue");
  await page.shot("11-gov-escalated-queue", "escalated and open: SLA breached, level 1 or 2");
  await page.scrollTo("All grievances");
  await page.click("Sensitive", "#grievance-filter-sensitive");
  await sleep(600);
  await page.shot("12-gov-sensitive", "sensitive grievances (harassment, or about the mine head): visible to the regulator");
  await page.click(sensitive[0], `tr[data-grievance="${sensitive[0]}"] td strong`);
  text = await page.until((t) => t.includes("routed to the regulator"));
  expect(await page.eval(`!!document.querySelector("#grievance-identity") || document.body.innerText.includes("Anonymous")`), "the regulator sees who complained");
  await page.shot("13-gov-sensitive-detail", `a sensitive case at Gevra (${sensitive[0]}): routed to the regulator; the mine head cannot see it`);
  await page.escape();

  console.log("Mine head again: the sensitive case does not exist for it");
  await page.as(HEAD, "/mine");
  await tab("Grievances");
  text = await page.until((t) => t.includes(ticket));
  for (const t of sensitive) expect(!text.includes(t), `${t} still invisible`);
  const byId = await api(HEAD, `/grievances/${(await (await api("gov@dgms.gov.in", `/grievances?mine_id=${gevra}&sensitive=1`)).json())[0].id}`);
  expect(byId.status === 404, "by id it is 404 for the mine head");
  await page.shot("14-head-no-sensitive", `the same mine head: ${sensitive.join(", ")} absent; by id the API answers 404`);

  console.log("Corporate (SECL): its companies' mines only");
  await page.as("corporate.secl@coalmine.in", "/gov");
  await tab("Grievances");
  text = await page.until((t) => t.includes("By mine") && t.includes("Gevra"));
  const codes = await page.eval(`[...document.querySelectorAll("#grievance-by-mine tbody tr")].map((r) => r.dataset.mine)`);
  const secl = (await (await api("corporate.secl@coalmine.in", "/mines?per_page=200")).json()).map((m) => m.code);
  expect(codes.length > 0 && codes.every((c) => secl.includes(c)), "corporate analytics only for SECL mines");
  expect(!text.includes("Kulda"), "Kulda (MCL) is not in SECL's view");
  await page.shot("15-corporate-analytics", "corporate SECL: the same analytics for its 17 mines");
}

// A small, valid PDF for the evidence upload.
function evidencePdf() {
  const body = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    + "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 100]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
  const path = join(mkdtempSync(join(tmpdir(), "cs-evidence-")), "safety-committee-minutes-2026-09.pdf");
  writeFileSync(path, body);
  return path;
}

/** `yii obligation/check --at=<iso>`: what the clock will do when that time comes. */
function obligationCheckAt(iso) {
  const php = process.env.PHP ?? "C:/xampp/php/php.exe";
  return new Promise((res, rej) => {
    const p = spawn(php, [join(ROOT, "api", "yii"), "obligation/check", `--at=${iso}`], { stdio: ["ignore", "pipe", "pipe"] });
    let out = "";
    p.stdout.on("data", (d) => (out += d));
    p.stderr.on("data", (d) => (out += d));
    p.on("close", (code) => (code === 0 ? res(out.trim()) : rej(new Error(`obligation/check: ${out}`))));
  });
}

/** Block every request that is not to this machine - the app must work with no internet. */
async function blockInternet(page) {
  const blocked = [];
  page.ws.addEventListener("message", (e) => {
    const msg = JSON.parse(e.data);
    if (msg.method !== "Fetch.requestPaused") return;
    const { requestId, request } = msg.params;
    const host = new URL(request.url).hostname;
    if (["localhost", "127.0.0.1", "[::1]"].includes(host)) page.send("Fetch.continueRequest", { requestId }).catch(() => null);
    else { blocked.push(host); page.send("Fetch.failRequest", { requestId, errorReason: "InternetDisconnected" }).catch(() => null); }
  });
  await page.send("Fetch.enable", { patterns: [{ urlPattern: "*" }] });
  return blocked;
}

async function phase5b(page) {
  const HEAD = "head.cg-krb-03@coalmine.in";   // Gevra (SECL)
  const tab = (name) => page.click(name, "button[role=tab]");
  const api = async (email, path) => (await fetch(`${API}${path}`, { headers: { Authorization: `Bearer ${await login(email)}` } })).json();
  const count = (selector) => page.eval(`document.querySelectorAll(${JSON.stringify(selector)}).length`);
  const SECTIONS = ["due_soon", "overdue", "open", "submitted", "accepted"];
  let text;

  console.log("Mine head of Gevra: the register, a citation, evidence submitted");
  const mine = await api(HEAD, "/views/obligations");
  const target = mine.due_soon.find((t) => t.status === "open") ?? mine.open[0];
  expect(target, "Gevra has an open task");
  await page.as(HEAD, "/mine");
  await tab("Obligations");
  text = await page.until((t) => t.includes(target.obligation.code));
  const rows = await count(SECTIONS.map((s) => `#obligations-${s} tbody tr`).join(", "));
  const cited = await count(SECTIONS.map((s) => `#obligations-${s} tbody tr .citation-head`).join(", "));
  expect(rows > 0 && rows === cited, `every register row carries its citation (${cited}/${rows})`);
  expect(!text.includes("RPT-08"), "RPT-08 (TODO-VERIFY) has no task");
  await page.shot("01-head-register", `mine head: statutory compliance ${mine.summary.totals.compliance_pct} % (separate from the score), due soon, overdue, open, submitted, accepted - each with act and section`);
  await page.eval(`document.querySelector("#obligations-other details").open = true`);
  await page.scrollTo("Obligations not on the dated register");
  expect(await page.eval(`(document.querySelector('#obligations-other tr[data-code="SAF-11"]')?.textContent ?? "").includes("Monitored")`),
    "SAF-11 is shown as monitored by the sensor rules");
  expect(await count('#obligations-other tr[data-code="RPT-08"]') === 1, "RPT-08 is listed, unverified, with no task");
  await page.shot("01b-head-other-obligations", "obligations without dated tasks, each with its citation and how it is handled (sensor rules, continuous, per shift, on event, once)");
  await page.eval(`document.querySelector("#obligations-other details").open = false`);
  await page.eval("window.scrollTo(0, 0)");
  await page.click(target.obligation.code, `tr[data-task="${target.id}"] strong`);
  await page.until((t) => t.includes(target.period) && t.includes("Evidence"));
  await page.eval(`document.querySelector("#obligation-task details.citation-quote")?.setAttribute("open", "")`);
  await sleep(300);
  expect(await page.eval(`!!document.querySelector("#obligation-task .citation-quote blockquote")`), "the verbatim quote expands");
  await page.shot("02-head-task-citation", `${target.obligation.code} ${target.period}: the citation with the verbatim quote, source file and page; the due time and its basis`);
  await page.setFile("#ob-file", evidencePdf());
  const kind = target.obligation.evidence_type ?? target.obligation.title;
  const evidence = kind[0].toUpperCase() + kind.slice(1);
  await page.type("#ob-note", `${evidence} for ${target.period}, attached.`);
  await page.click("Submit evidence", "#obligation-upload button");
  await page.until((t) => t.includes(`${evidence} for ${target.period}, attached.`));
  const submitted = await api(HEAD, `/obligation-tasks/${target.id}`);
  expect(submitted.status === "submitted" && submitted.latest_submission.status === "pending", "the task is submitted, the evidence pending");
  await page.shot("03-head-submitted", "evidence submitted (file + note): awaiting review");
  await page.escape();

  console.log("Government: the register across mines; reject with a reason, then accept");
  await page.as("gov@dgms.gov.in", "/gov");
  await tab("Obligations");
  await page.until((t) => t.includes("By company") && t.includes("Most overdue"));
  const gov = await api("gov@dgms.gov.in", "/views/obligations");
  expect(await count("#obligation-by-company tbody tr") === gov.summary.by_company.length, "one row per company");
  expect(await count("#obligation-most-overdue tbody tr") > 0, "the most overdue items are listed");
  await page.shot("04-gov-register", `government: statutory compliance ${gov.summary.totals.compliance_pct} % across the fleet, per company, the most overdue items with their citations`);
  // The queue is oldest first; the reviewer narrows it to the state.
  await page.select("#obligation-state-filter", "Chhattisgarh");
  for (let i = 0; i < 40 && await count(`#obligation-pending-review tr[data-task="${target.id}"]`) === 0; i++) await sleep(500);
  expect(await count(`#obligation-pending-review tr[data-task="${target.id}"]`) === 1, "the new evidence awaits review");
  await page.scrollTo("Evidence awaiting review");
  await page.shot("05-gov-pending-and-by-mine", "Chhattisgarh: evidence awaiting review, oldest first; statutory compliance per mine, lowest first, and per domain");
  await page.click(target.obligation.code, `#obligation-pending-review tr[data-task="${target.id}"] strong`);
  await page.until((t) => t.includes(`${evidence} for ${target.period}, attached.`) && t.includes("Accept"));
  await page.click("Reject", "#obligation-review button");
  expect((await api(HEAD, `/obligation-tasks/${target.id}`)).status === "submitted", "no reason, no rejection");
  await page.shot("06-gov-reject-needs-reason", "rejecting without a reason is refused (REASON_REQUIRED)");
  await page.type("#ob-reason", "The copy is not signed by the mine manager. Resubmit the signed copy.");
  await page.click("Reject", "#obligation-review button");
  await page.until((t) => t.includes("not signed by the mine manager"));
  expect((await api(HEAD, `/obligation-tasks/${target.id}`)).status === "rejected", "rejected");
  await page.shot("07-gov-rejected", "rejected with the reason, which the mine head sees");
  await page.escape();

  console.log("Mine head: resubmits; government accepts");
  await page.as(HEAD, "/mine");
  await tab("Obligations");
  await page.until((t) => t.includes(target.obligation.code));
  await page.click(target.obligation.code, `tr[data-task="${target.id}"] strong`);
  await page.until((t) => t.includes("not signed by the mine manager"));
  await page.shot("08-head-sees-rejection", "the mine head sees the rejection and its reason, and uploads again");
  await page.setFile("#ob-file", evidencePdf());
  await page.type("#ob-note", "Signed copy, with the mine manager's signature.");
  await page.click("Submit evidence", "#obligation-upload button");
  await page.until((t) => t.includes("Signed copy"));
  await page.escape();
  await page.as("gov@dgms.gov.in", "/gov");
  await tab("Obligations");
  await page.until((t) => t.includes("Most overdue"));
  await page.select("#obligation-state-filter", "Chhattisgarh");
  for (let i = 0; i < 40 && await count(`#obligation-pending-review tr[data-task="${target.id}"]`) === 0; i++) await sleep(500);
  await page.click(target.obligation.code, `#obligation-pending-review tr[data-task="${target.id}"] strong`);
  await page.until((t) => t.includes("Signed copy") && t.includes("Accept"));
  await page.click("Accept", "#obligation-review button");
  for (let i = 0; i < 20 && (await api(HEAD, `/obligation-tasks/${target.id}`)).status !== "accepted"; i++) await sleep(500);
  expect((await api(HEAD, `/obligation-tasks/${target.id}`)).status === "accepted", "accepted");
  await sleep(800);
  await page.shot("09-gov-accepted", "accepted: the task keeps the rejection and both uploads");
  await page.escape();

  console.log("Government: waive a task, with a reason");
  const waiveId = await page.eval(`document.querySelector("#obligation-most-overdue tbody tr")?.dataset.task`);
  expect(waiveId, "a most-overdue item to waive");
  await page.eval(`document.querySelector('#obligation-most-overdue tr[data-task="${waiveId}"] strong').click()`);
  await page.until((t) => t.includes("Waive this task"));
  await page.eval(`document.querySelector("#obligation-waive").open = true`);
  await sleep(300);
  await page.type("#ob-waiver", "Mine closed for the whole period by a DGMS prohibition order (demo).");
  await page.click("Waive", "#obligation-waive button");
  await page.until((t) => t.includes("Waived by"));
  expect((await api("gov@dgms.gov.in", `/obligation-tasks/${waiveId}`)).status === "waived", "waived");
  await page.shot("09b-gov-waived", "government waives an overdue task with a reason: kept in its history, its alert resolved, out of statutory compliance");
  await page.escape();

  console.log("An overdue item escalating: the clock moved past the due time, then 168 hours on");
  const later = (await api(HEAD, "/views/obligations")).open.filter((t) => t.status === "open" && Date.parse(t.due_at) > Date.now())
    .sort((a, b) => Date.parse(a.due_at) - Date.parse(b.due_at))[0];
  expect(later, "Gevra has a task due later");
  const due = Date.parse(later.due_at);
  console.log("  " + await obligationCheckAt(new Date(due + 3600e3).toISOString()));
  let t1 = await api(HEAD, `/obligation-tasks/${later.id}`);
  expect(t1.status === "overdue" && t1.escalation_level === 1, `${later.obligation.code} overdue, level 1`);
  await page.as(HEAD, "/mine");
  await page.until((t) => t.includes(`${later.obligation.code} for ${later.period} is overdue`));
  await page.eval(`(() => { const m = [...document.querySelectorAll(".alert-message")].find((e) => e.textContent.startsWith(${JSON.stringify(`${later.obligation.code} for ${later.period}`)}));
    m?.closest(".alert-row")?.scrollIntoView({ block: "nearest" }); })()`);
  await sleep(600);
  await page.shot("10-head-overdue-alert", `mine head overview: ${later.obligation.code} ${later.period} overdue - an alert {code, params} with its citation`);
  console.log("  " + await obligationCheckAt(new Date(due + 169 * 3600e3).toISOString()));
  t1 = await api(HEAD, `/obligation-tasks/${later.id}`);
  expect(t1.status === "escalated" && t1.escalation_level === 2, `${later.obligation.code} escalated, level 2`);
  await tab("Obligations");
  await page.until((t) => t.includes(later.obligation.code));
  await page.scrollTo("Overdue");
  expect(await count(`#obligations-overdue tr[data-task="${later.id}"]`) === 1, "the escalated item is in the overdue list");
  await page.shot("11-head-escalated", `${later.obligation.code} ${later.period}: escalated (level 2) 168 hours after the due time`);
  await page.as("gov@dgms.gov.in", "/gov");
  await tab("Obligations");
  await page.until((t) => t.includes("Most overdue"));
  await page.shot("12-gov-after-escalation", "the regulator's register after the clock moved on: overdue and escalated items across the fleet");

  console.log("Corporate (SECL): its companies' mines only");
  await page.as("corporate.secl@coalmine.in", "/gov");
  await tab("Obligations");
  await page.until((t) => t.includes("By company"));
  const companies = await page.eval(`[...document.querySelectorAll("#obligation-by-company tbody tr td:first-child strong")].map((e) => e.textContent)`);
  expect(companies.length === 1 && companies[0] === "SECL", `corporate sees SECL only (${companies})`);
  await page.shot("13-corporate-register", "corporate SECL: statutory compliance for its mines only");

  console.log("The map with no internet: every request off this machine fails");
  const blocked = await blockInternet(page);
  const outlines = async () => ({ states: await count("#mine-map path.state-outline"), districts: await count("#mine-map path.district-outline"), mines: await count("#mine-map path.mine-marker") });
  const mapFor = async (email, path, expected, shot, note) => {
    await page.as(email, path);
    await tab("Map");
    for (let i = 0; i < 40 && ((await outlines()).states === 0 || (await outlines()).mines === 0); i++) await sleep(500);
    await sleep(800);
    const o = await outlines();
    expect(o.states === 36 && o.districts > 0, `state and district outlines drawn offline (${o.states}, ${o.districts})`);
    expect(o.mines === expected, `${expected} mine(s) on the map (got ${o.mines})`);
    const attribution = await page.eval(`document.querySelector("#mine-map .leaflet-control-attribution")?.textContent ?? ""`);
    expect(attribution.includes("Global Energy Monitor") && attribution.includes("CC BY 4.0"), "GEM attribution visible");
    await page.shot(shot, note);
  };
  const all = (await api("gov@dgms.gov.in", "/views/map")).mines.features.length;
  const secl = (await api("corporate.secl@coalmine.in", "/views/map")).mines.features.length;
  await mapFor("gov@dgms.gov.in", "/gov", all, "14-gov-map-offline", `government, offline: ${all} mines at their coordinates, coloured and labelled by band; outlines are local data; GEM and DataMeet credited`);
  await page.eval(`document.querySelector('#mine-map path.mine-marker[data-mine="CG-KRB-03"]').dispatchEvent(new MouseEvent("mouseover", { bubbles: true }))`);
  await sleep(500);
  const tip = await page.eval(`document.querySelector(".leaflet-tooltip")?.textContent ?? ""`);
  expect(tip.includes("Location:") && tip.includes("open alert"), "the tooltip shows open alerts and the location quality");
  await page.shot("15-gov-map-tooltip", "a mine's tooltip: name, band, score, district and location quality");
  await page.eval(`document.querySelector('#mine-map path.mine-marker[data-mine="CG-KRB-03"]').dispatchEvent(new MouseEvent("click", { bubbles: true }))`);
  await page.until((t) => t.includes("Gevra"));
  await sleep(1500);
  expect(/\/gov\/mines\/\d+$/.test(await page.eval("location.pathname")), "a click opens the mine");
  await page.shot("16-gov-map-click-through", "click through to the mine's detail");
  await page.as("gov@dgms.gov.in", "/gov");
  await tab("Map");
  for (let i = 0; i < 40 && (await outlines()).states === 0; i++) await sleep(500);
  await page.eval(`document.querySelector("#map-basemap").click()`);
  await page.until((t) => t.includes("Tiles unavailable"));
  await sleep(800);
  expect((await outlines()).states === 36, "the outlines stay when the street map cannot load");
  const osm = await page.eval(`document.querySelector("#mine-map .leaflet-control-attribution")?.textContent ?? ""`);
  expect(osm.includes("OpenStreetMap"), "OSM attribution while the street map is on");
  await page.shot("17-gov-map-basemap-offline", "street map switched on with no internet: tiles fail, the outlines and mines remain");
  await mapFor("corporate.secl@coalmine.in", "/gov", secl, "18-corporate-map-offline", `corporate SECL, offline: its ${secl} mines only`);
  await mapFor(HEAD, "/mine", 1, "19-head-map-offline", "mine head, offline: their mine only");
  expect(blocked.length > 0 && blocked.every((h) => !["localhost", "127.0.0.1"].includes(h)), "only off-machine requests were blocked");
  console.log(`  blocked ${blocked.length} request(s) to: ${[...new Set(blocked)].join(", ")}`);
  await page.send("Fetch.disable");
}

// Phase 6: text that does not fit. Runs in the page; returns what a reader would see cut off or
// spilling out: clipped text (overflow hidden / ellipsis), text wider than its own box, anything
// past the right edge of the window outside a horizontal-scroll container, and a page that scrolls
// sideways. Charts (SVG) and the map's own panes are left out - they draw, they do not wrap.
const OVERFLOW_PROBE = `(() => {
  const found = [];
  const vw = document.documentElement.clientWidth;
  const skip = (el) => el.closest("svg, .leaflet-pane, .leaflet-control-attribution, .sr-only, [aria-hidden='true']");
  const scroller = (el) => {
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      const o = getComputedStyle(p).overflowX;
      if (o === "auto" || o === "scroll") return p;
    }
    return null;
  };
  const path = (el) => {
    const bits = [];
    for (let e = el; e && e !== document.body && bits.length < 3; e = e.parentElement) {
      bits.unshift(e.tagName.toLowerCase() + (e.id ? "#" + e.id : "") + (typeof e.className === "string" && e.className.trim() ? "." + e.className.trim().split(/\\s+/).slice(0, 2).join(".") : ""));
    }
    return bits.join(" > ");
  };
  const ownText = (el) => [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join("").trim();
  for (const el of document.querySelectorAll("body *")) {
    if (skip(el)) continue;
    const text = ownText(el);
    if (!text) continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === "hidden") continue;
    const clipX = ["hidden", "clip"].includes(cs.overflowX) || cs.textOverflow === "ellipsis";
    const clipY = ["hidden", "clip"].includes(cs.overflowY);
    const add = (kind, px) => found.push({ kind, px: Math.round(px), where: path(el), text: text.slice(0, 60) });
    if (clipX && el.scrollWidth > el.clientWidth + 1) add("clipped-x", el.scrollWidth - el.clientWidth);
    else if (clipY && el.scrollHeight > el.clientHeight + 2) add("clipped-y", el.scrollHeight - el.clientHeight);
    else if (!clipX && cs.display !== "inline" && el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 2 && cs.whiteSpace !== "pre") add("spills", el.scrollWidth - el.clientWidth);
    if (r.right > vw + 1 && !scroller(el)) add("past-window", r.right - vw);
    // Cut by a container: an ancestor with overflow hidden / clip hides part of this text's box.
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
      const as = getComputedStyle(a);
      const hx = ["hidden", "clip"].includes(as.overflowX), hy = ["hidden", "clip"].includes(as.overflowY);
      if (!hx && !hy) continue;
      const ar = a.getBoundingClientRect();
      const cut = Math.max(hy ? ar.top - r.top : 0, hy ? r.bottom - ar.bottom : 0, hx ? ar.left - r.left : 0, hx ? r.right - ar.right : 0);
      if (cut > 1) add("cut-by-container", cut);
      break;
    }
  }
  const page = document.documentElement.scrollWidth - document.documentElement.clientWidth;
  if (page > 1) found.push({ kind: "page-scrolls-sideways", px: page, where: "html", text: "" });
  // Merge repeats (the same element kind in every row of a table).
  const seen = new Map();
  for (const f of found) {
    const k = f.kind + "|" + f.where;
    if (seen.has(k)) seen.get(k).count++; else seen.set(k, { ...f, count: 1 });
  }
  return [...seen.values()];
})()`;

// What the page is rendering with: the Noto faces loaded, and Latin-script text left on screen in
// a non-English UI (data such as mine names is expected; a sentence is a missed string).
const FONT_AND_TEXT_PROBE = `(() => {
  const fonts = [...document.fonts].filter((f) => f.status === "loaded").map((f) => f.family.replace(/"/g, "")).filter((f) => f.startsWith("Noto"));
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const latin = [];
  while (walker.nextNode()) {
    const s = walker.currentNode.textContent.trim();
    const el = walker.currentNode.parentElement;
    if (!s || !el || el.closest("script, style, svg, .mono, .leaflet-control-attribution, .demo-footer, .lang-switch, .citation-quote, .citation-head, blockquote, input, select option")) continue;
    if (/[A-Za-z]{3,}(\\s+[A-Za-z]{2,}){2,}/.test(s)) latin.push(s.slice(0, 70));
  }
  return { fonts: [...new Set(fonts)], latin: [...new Set(latin)].slice(0, 12), latinCount: latin.length };
})()`;

async function phase6(page) {
  const LANGS = ["en", "hi", "bn", "or", "te", "mr"];
  const GOV = "gov@dgms.gov.in";
  const HEAD = "head.cg-krb-03@coalmine.in";   // Gevra: the seed saves Hindi for this account
  const tabBtn = (id) => page.eval(`document.querySelector('button[aria-controls="panel-${id}"]').click()`);
  const waitFor = async (selector, timeout = 20000) => {
    for (let waited = 0; waited < timeout; waited += 400) {
      if (await page.eval(`!!document.querySelector(${JSON.stringify(selector)})`)) return true;
      await sleep(400);
    }
    throw new Error(`waited ${timeout / 1000} s for ${selector}`);
  };
  const lang = () => page.eval("document.documentElement.lang");
  const text = (selector) => page.eval(`document.querySelector(${JSON.stringify(selector)})?.textContent?.trim() ?? ""`);
  const blocked = await blockInternet(page);
  const findings = {};

  // The probe must catch what it is for: two labels made not to fit, then removed.
  await page.goto(`${APP}/login`, 1500);
  const selfTest = await page.eval(`(() => {
    const box = document.createElement("div");
    box.innerHTML = '<div id="pt-clip" style="width:40px;overflow:hidden;white-space:nowrap">A label that cannot fit here</div>'
      + '<button id="pt-spill" style="width:30px;white-space:nowrap">Longwordlabel</button>'
      + '<div style="height:12px;overflow:hidden"><span id="pt-cut" style="display:block;margin-top:8px">Cut</span></div>';
    document.body.appendChild(box);
    const kinds = ${OVERFLOW_PROBE}.filter((f) => /pt-/.test(f.where)).map((f) => f.kind);
    box.remove();
    return kinds;
  })()`);
  expect(selfTest.includes("clipped-x") && selfTest.includes("spills") && selfTest.includes("cut-by-container"),
    `the overflow probe detects clipped, spilling and cut text (${selfTest.join(", ")})`);
  console.log(`  probe self-test: ${selfTest.join(", ")}`);
  const probe = async (name, code) => {
    await page.eval("document.fonts.ready.then(() => true)");
    await sleep(500);
    const issues = await page.eval(OVERFLOW_PROBE);
    const render = await page.eval(FONT_AND_TEXT_PROBE);
    findings[name] = { lang: code, issues, fonts: render.fonts, latinSample: render.latin, latinCount: render.latinCount,
      // A date with an English month in another language: the browser lacked the locale data.
      englishMonths: code === "en" ? [] : await page.eval(`[...new Set(document.body.innerText.match(/\\b\\d{1,2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\\b/g) ?? [])].slice(0, 5)`) };
    return issues;
  };
  const shoot = async (name, note, code) => {
    const issues = await probe(name, code);
    await page.shot(name, `${note}${issues.length ? ` - ${issues.length} overflow finding(s)` : ""}`);
  };

  console.log("Switching language: signed out (browser choice), then the account's saved language wins");
  await page.goto(`${APP}/login`, 1500);
  await page.eval(`sessionStorage.removeItem("smg.token"); localStorage.setItem("smg.lang", "en")`);
  await page.goto(`${APP}/login`, 2500);
  const enButton = await text("form button[type=submit]");
  await page.select("#login-language", "te");
  await sleep(500);
  expect(await lang() === "te" && (await text("form button[type=submit]")) !== enButton, "the login switcher switches at once");
  expect(await page.eval(`localStorage.getItem("smg.lang")`) === "te", "the signed-out choice is kept in the browser");
  await page.shot("00a-login-switcher-te", "login page switched to Telugu with the switcher; kept in this browser until login");
  // Gevra's head saved Hindi: after login Hindi wins over the browser's Telugu.
  const headToken = await login(HEAD);
  await fetch(`${API}/users/me`, { method: "PATCH", headers: { Authorization: `Bearer ${headToken}`, "Content-Type": "application/json" }, body: JSON.stringify({ preferred_language: "hi" }) });
  await page.eval(`sessionStorage.setItem("smg.token", ${JSON.stringify(headToken)})`);
  await page.goto(`${APP}/mine`, 4000);
  await waitFor(".masthead h1");
  expect(await lang() === "hi", `after login the saved preference wins (browser te, account hi; got ${await lang()})`);
  await page.shot("00b-head-saved-hindi", "Gevra mine head: browser set to Telugu, account saved Hindi - Hindi wins after login");
  // The profile page saves a new language and switches immediately.
  await page.goto(`${APP}/profile`, 3000);
  await waitFor("#profile-language");
  const before = await text(".masthead h1");
  await page.eval(`document.querySelector('#profile-language input[value="or"]').click()`);
  for (let i = 0; i < 20 && (await lang()) !== "or"; i++) await sleep(250);
  expect(await lang() === "or" && (await text(".masthead h1")) !== before, "the profile switches the language at once");
  // The interface switches first; the save (PATCH /v1/users/me) completes a moment later.
  let me = {};
  for (let i = 0; i < 20 && me.preferred_language !== "or"; i++) {
    await sleep(250);
    me = await (await fetch(`${API}/users/me`, { headers: { Authorization: `Bearer ${headToken}` } })).json();
  }
  expect(me.preferred_language === "or", "the choice is saved to the account (PATCH /v1/users/me)");
  await sleep(600);
  await page.shot("00c-profile-switched-odia", "profile: choosing Odia saves it to the account and switches without a reload");

  for (const code of LANGS) {
    console.log(`Language ${code}: the main screens`);
    await page.goto(`${APP}/login`, 1200);
    await page.eval(`sessionStorage.removeItem("smg.token"); localStorage.setItem("smg.lang", ${JSON.stringify(code)})`);
    await page.goto(`${APP}/login`, 2000);
    await waitFor("#login-language");
    expect(await lang() === code, `login page in ${code}`);
    await shoot(`${code}-1-login`, `${code}: login`, code);

    await page.as(GOV, "/gov", code);
    await waitFor(".board-bars .core");
    expect(await lang() === code, `government overview in ${code}`);
    await shoot(`${code}-2-gov-overview`, `${code}: government overview`, code);

    await page.goto(`${APP}/gov/mines/5`, 3500);
    await waitFor(".tally-v");
    await shoot(`${code}-3-mine-detail`, `${code}: mine detail (Bhubaneswari)`, code);

    await page.goto(`${APP}/gov`, 3000);
    await waitFor(".board-bars .core");
    await tabBtn("production");
    await waitFor("#panel-production table");
    await shoot(`${code}-4-production`, `${code}: production`, code);

    await tabBtn("grievances");
    await waitFor("#grievance-by-mine");
    await shoot(`${code}-5-grievances`, `${code}: grievances`, code);

    await tabBtn("obligations");
    await waitFor("#obligation-summary");
    await shoot(`${code}-6-obligations`, `${code}: obligation register (titles translated; citations as written)`, code);
    // A detail drawer: the longest labels in the narrowest column.
    await page.eval(`document.querySelector("#obligation-most-overdue tbody tr strong").click()`);
    await waitFor("#obligation-task .citation-block");
    await page.eval(`document.querySelector("#obligation-task details.citation-quote")?.setAttribute("open", "")`);
    await shoot(`${code}-6b-obligation-task`, `${code}: an obligation task - labels translated, the citation and quote as written`, code);
    await page.escape();

    await tabBtn("map");
    await waitFor("#mine-map path.mine-marker");
    await sleep(800);
    await shoot(`${code}-7-map`, `${code}: map`, code);

    await page.goto(`${APP}/profile`, 2500);
    await waitFor("#profile-language");
    await shoot(`${code}-8-profile`, `${code}: profile and language`, code);
  }

  // Reset the demo accounts' languages (the seed's values) and report.
  await fetch(`${API}/users/me`, { method: "PATCH", headers: { Authorization: `Bearer ${await login(GOV)}`, "Content-Type": "application/json" }, body: JSON.stringify({ preferred_language: "en" }) });
  await fetch(`${API}/users/me`, { method: "PATCH", headers: { Authorization: `Bearer ${headToken}`, "Content-Type": "application/json" }, body: JSON.stringify({ preferred_language: "hi" }) });
  await page.send("Fetch.disable");
  writeFileSync(join(OUT, "overflow.json"), JSON.stringify(findings, null, 2));
  const total = Object.values(findings).reduce((n, f) => n + f.issues.length, 0);
  console.log(`\n  overflow findings: ${total} across ${Object.keys(findings).length} screens (overflow.json)`);
  for (const [name, f] of Object.entries(findings)) {
    for (const i of f.issues) console.log(`    ${name}: ${i.kind} ${i.px}px x${i.count} ${i.where} "${i.text}"`);
  }
  const indic = Object.entries(findings).filter(([, f]) => f.lang !== "en" && f.fonts.length === 0).map(([n]) => n);
  console.log(`  Noto faces loaded on every non-English screen: ${indic.length ? "NO - " + indic.join(", ") : "yes"}`);
  console.log(`  requests off this machine: ${blocked.length}${blocked.length ? " (" + [...new Set(blocked)].join(", ") + ")" : ""}`);
  expect(blocked.length === 0, "no request leaves the machine (fonts and outlines are local)");
  expect(!indic.length, "the Indic scripts render with the bundled Noto faces");
  const months = Object.entries(findings).filter(([, f]) => f.englishMonths.length).map(([n, f]) => `${n}: ${f.englishMonths.join(", ")}`);
  console.log(`  English month names on non-English screens: ${months.length ? months.join("; ") : "none"}`);
  expect(!months.length, "dates use the language's month names");
  if (process.argv.includes("--strict")) expect(total === 0, `no overflow findings (${total})`);
}

/** Two more tabs, a government overview and a mine-head dashboard, polling on their own. */
/** Open another tab (desktop size) and sign it in as `email` on `path` of the dashboard. */
async function dashboardTab(email, path) {
  const target = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: "PUT" }).then((r) => r.json());
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener("open", r, { once: true }));
  const tab = new Page(ws);
  await tab.send("Page.enable");
  await tab.send("Runtime.enable");
  await tab.send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
  await tab.as(email, path);
  return { tab, target };
}

/** The field server (scripts/field_server.mjs) on its own port, so the check can switch it off. */
function fieldServer(port) {
  const p = spawn(process.execPath, [join(ROOT, "scripts", "field_server.mjs"), "--no-https"],
    { env: { ...process.env, FIELD_HTTP_PORT: String(port) }, stdio: "ignore" });
  return p;
}

async function waitUrl(url, up = true, timeout = 15000) {
  for (let waited = 0; waited < timeout; waited += 250) {
    const ok = await fetch(url).then((r) => r.ok).catch(() => false);
    if (ok === up) return;
    await sleep(250);
  }
  throw new Error(`${url} did not come ${up ? "up" : "down"}`);
}

async function phase7b(page) {
  const FPORT = 5181;
  const FIELD = `http://localhost:${FPORT}`;
  const INSPECTOR = "inspector.07@dgms.example";
  const PHOTO = resolve(ROOT, "ai-service/samples/images/metro_shaft_workers.jpg");
  const idb = (body) => page.eval(`new Promise((done, fail) => { const r = indexedDB.open("smg-field"); r.onerror = () => fail(r.error);
    r.onsuccess = async () => { const db = r.result; try { done(await (async (db) => { ${body} })(db)); } catch (e) { fail(e); } finally { db.close(); } }; })`);
  const req = (x) => `new Promise((ok, no) => { const q = ${x}; q.onsuccess = () => ok(q.result); q.onerror = () => no(q.error); })`;
  const waitFor = async (selector, timeout = 20000) => {
    for (let waited = 0; waited < timeout; waited += 300) {
      if (await page.eval(`!!document.querySelector(${JSON.stringify(selector)})`)) return;
      await sleep(300);
    }
    throw new Error(`waited for ${selector}`);
  };
  const clickSel = (selector) => page.eval(`document.querySelector(${JSON.stringify(selector)}).click()`);
  const setOffline = (offline) => page.send("Network.emulateNetworkConditions", { offline, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  const api = async (email, path) => {
    const token = await login(email);
    return fetch(`${API}${path}`, { headers: { Authorization: `Bearer ${token}` } }).then((r) => r.json());
  };

  // A phone: 360 px wide, touch, Android, GPS allowed.
  await page.send("Emulation.setDeviceMetricsOverride", { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await page.send("Emulation.setUserAgentOverride", { userAgent: "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36" });
  await page.send("Emulation.setTouchEmulationEnabled", { enabled: true, maxTouchPoints: 5 });
  await page.send("Network.enable");
  await page.send("Browser.grantPermissions", { origin: FIELD, permissions: ["geolocation"] });

  let server = fieldServer(FPORT);
  await waitUrl(`${FIELD}/field`);
  try {
    console.log("Online: sign in once, the service worker keeps the app");
    await page.goto(`${FIELD}/field`, 3000);
    await waitFor("#field-email");
    await page.type("#field-email", INSPECTOR);
    await page.type("#field-password", "demo123");   // demo-only account
    await page.eval(`document.getElementById("field-sign-in").requestSubmit()`);
    await waitFor("#field-sync");
    const sw = await page.eval(`navigator.serviceWorker.ready.then(() => new Promise((r) => setTimeout(r, 1500))).then(async () => ({ caches: (await caches.keys()).length }))`);
    expect(sw.caches === 1, "the service worker installed its cache");
    await page.goto(`${FIELD}/field`, 2500);
    expect(await page.eval("!!navigator.serviceWorker.controller"), "the page is served by the service worker");
    const cached = await page.eval(`caches.keys().then((k) => caches.open(k[0])).then((c) => c.keys()).then((r) => r.length)`);
    await page.shot("01-signed-in-online", `Signed in with signal; ${cached} files kept offline by the service worker`);

    const session = await idb(`return await ${req('db.transaction("kv").objectStore("kv").get("session")')};`);
    const assigned = session.bootstrap.inspections.find((i) => i.status === "scheduled") ?? session.bootstrap.inspections[0];
    const mine = session.bootstrap.mines.find((m) => m.id === assigned.mine_id);
    expect(mine?.lat, "the assigned mine and its location are on the phone");
    const mineCode = mine.code;
    const head = `head.${mineCode.toLowerCase()}@coalmine.in`;
    const before = await api("gov@dgms.gov.in", `/violations?mine_id=${mine.id}&per_page=200`);

    console.log("Offline: field server stopped, network off");
    server.kill();
    await waitUrl(`${FIELD}/field`, false);
    await setOffline(true);
    await page.goto(`${FIELD}/field`, 2500);
    await waitFor("#field-sync");
    let text = await page.text();
    expect(text.includes("Offline"), "the app opens with no network and says it is offline");
    await page.shot("02-offline-app-opens", "No network and no server: the app opens from the phone, with the assigned inspections");

    await page.eval(`[...document.querySelectorAll("#field-assigned button")].find((b) => b.textContent.includes(${JSON.stringify(mine.name)})).click()`);
    await waitFor("[data-item='RS-01']");

    // Finding 1: near the mine, high, a violation with a corrective action, one photo.
    await page.send("Emulation.setGeolocationOverride", { latitude: mine.lat + 0.004, longitude: mine.lon + 0.003, accuracy: 9 });
    await clickSel("[data-item='RS-01']");
    await waitFor("#field-capture-form");
    await clickSel("[data-severity='high']");
    await page.setFile("#field-photo-input", PHOTO);
    await waitFor(".field-thumbs img");
    await page.until((t) => t.includes("Located"));
    await page.type("#field-note", "Two props missing at the face; roof unsupported for about 2 m.");
    await clickSel("#field-with-action");
    await sleep(200);
    await page.type("#field-action", "Set props to the support plan before work resumes.");
    await page.type("#field-due", "3");
    await page.shot("03-capture-offline", "Recording a finding offline: checklist item, severity, obligation, compressed photo, GPS with accuracy");
    await clickSel("#field-save");
    await waitFor("#field-captures");

    // The login is lost while offline (expired): capturing goes on, sync will ask to sign in.
    await idb(`const tx = db.transaction("kv", "readwrite"); const s = await ${req('tx.objectStore("kv").get("session")')};
      s.token = null; await ${req('tx.objectStore("kv").put(s, "session")')}; return true;`);
    await page.goto(`${FIELD}/field`, 2000);
    await waitFor("#field-sync");
    text = await page.text();
    expect(text.includes("Sign-in expired"), "an expired login is shown, and recording goes on");
    await page.eval(`document.querySelector("#field-visits a").click()`);
    await waitFor("[data-item='PP-01']");

    // Finding 2: 200 km from the mine - flagged on the phone and by the server, not refused.
    await page.send("Emulation.setGeolocationOverride", { latitude: mine.lat + 1.8, longitude: mine.lon, accuracy: 25 });
    await clickSel("[data-item='PP-01']");
    await waitFor("#field-capture-form");
    await page.setFile("#field-photo-input", PHOTO);
    await waitFor(".field-thumbs img");
    await waitFor("#field-geo-warning");
    await page.shot("04-geo-check-far", "A finding far from the mine's recorded location: warned, saved, flagged - not blocked");
    await clickSel("#field-save");
    await page.until((t) => (t.match(/waiting/g) ?? []).length >= 3);
    const photos = await idb(`return (await ${req('db.transaction("photos").objectStore("photos").getAll()')}).map((p) => p.bytes);`);
    const original = (await import("node:fs")).statSync(PHOTO).size;
    expect(photos.length === 2 && photos.every((b) => b > 0), "two photos queued");
    await page.shot("05-offline-queue", `Two findings and their photos queued offline (photos ${photos.map((b) => Math.round(b / 1024)).join(" and ")} KB; the camera file was ${Math.round(original / 1024)} KB)`);

    console.log("Back online: sign in again, then sync");
    const gov = await dashboardTab("gov@dgms.gov.in", `/gov/mines/${mine.id}`);
    const headTab = await dashboardTab(head, "/mine");
    await fetch(`http://127.0.0.1:${PORT}/json/activate/${page.targetId}`, { method: "PUT" }).catch(() => null);
    const openViolations = async (tab) => Number((await tab.eval(`[...document.querySelectorAll(".tally-set > div")].find((d) => /Open violations/.test(d.textContent))?.querySelector(".tally-v")?.textContent ?? "-1"`)).replace(/\D/g, ""));
    const govBefore = await openViolations(gov.tab);
    const headBefore = await openViolations(headTab.tab);

    server = fieldServer(FPORT);
    await waitUrl(`${FIELD}/field`);
    await setOffline(false);
    await page.goto(`${FIELD}/field`, 2500);
    await waitFor("#field-sync-button");
    await clickSel("#field-sync-button");
    await waitFor("#field-password");
    text = await page.text();
    expect(text.includes("expired"), "sync asks to sign in first; the queue is untouched");
    await page.shot("06-sign-in-to-sync", "Online again with an expired login: sign in (same account) before the queue is sent");
    await page.type("#field-password", "demo123");
    const syncedAt = Date.now();
    await page.eval(`document.getElementById("field-sign-in").requestSubmit()`);
    text = await page.until((t) => /Sent: \d+ new/.test(t), 30000);
    expect(/Sent: 5 new, 0 already on the server, 0 failed/.test(text), `visit, two findings and two photos sent (${text.match(/Sent:[^.]*/)?.[0]})`);
    await page.shot("07-synced", "Synced: the visit, two findings and two photos");

    // The dashboards poll every 10 s: both pick the findings up within one cycle, no reload.
    let govAfter = govBefore, headAfter = headBefore, seconds = 0;
    for (; seconds < 15 && (govAfter !== govBefore + 2 || headAfter !== headBefore + 2); seconds++) {
      await sleep(1000);
      govAfter = await openViolations(gov.tab);
      headAfter = await openViolations(headTab.tab);
    }
    const elapsed = Math.round((Date.now() - syncedAt) / 1000);
    expect(govAfter === govBefore + 2 && headAfter === headBefore + 2,
      `government ${govBefore} -> ${govAfter}, mine head ${headBefore} -> ${headAfter} open violations`);
    await gov.tab.click("Violations (");
    await gov.tab.shot("08-gov-new-findings", `Government, no reload: open violations ${govBefore} -> ${govAfter} within ${elapsed} s of the sync; the field captures listed with their flag`);
    await gov.tab.eval(`[...document.querySelectorAll("tr.clickable")].find((r) => r.textContent.includes("Field capture")).click()`);
    await sleep(1500);
    await gov.tab.eval(`document.getElementById("field-capture-detail")?.scrollIntoView({ block: "start" })`);
    await sleep(800);
    await gov.tab.shot("09-gov-capture-detail", "The capture on the dashboard: phone time and receipt time, location and accuracy, distance, note, photo");
    await headTab.tab.shot("10-mine-head-sees-it", `Mine head, no reload: open violations ${headBefore} -> ${headAfter}`);

    console.log("The same sync again (as if every answer had been lost): nothing is duplicated");
    const after = await api("gov@dgms.gov.in", `/violations?mine_id=${mine.id}&per_page=200`);
    await idb(`for (const name of ["visits", "captures", "photos"]) { const tx = db.transaction(name, "readwrite"); const st = tx.objectStore(name);
      for (const item of await ${req("st.getAll()")}) { item.status = "pending"; item.result = null; await ${req("st.put(item)")}; } } return true;`);
    await page.goto(`${FIELD}/field`, 2500);
    await waitFor("#field-sync-button");
    await clickSel("#field-sync-button");
    text = await page.until((t) => /Sent: \d+ new/.test(t), 30000);
    expect(/Sent: 0 new, 5 already on the server, 0 failed/.test(text), `resent queue answered from the first sync (${text.match(/Sent:[^.]*/)?.[0]})`);
    const again = await api("gov@dgms.gov.in", `/violations?mine_id=${mine.id}&per_page=200`);
    expect(after.length === before.length + 2 && again.length === after.length, `violations ${before.length} -> ${after.length} -> ${again.length}`);
    const inspection = await api("gov@dgms.gov.in", `/inspections/${assigned.id}?expand=observations`);
    await page.shot("11-resent-no-duplicates", `Resent the same queue: all 5 answered "already on the server"; the mine still has ${again.length} violations (${before.length} before the visit)`);
    writeFileSync(join(OUT, "sync.json"), JSON.stringify({ mine: mineCode, inspection: assigned.id, violations: { before: before.length, after: after.length, afterResend: again.length },
      inspection_status: inspection.status, dashboards: { government: [govBefore, govAfter], mine_head: [headBefore, headAfter], seconds_after_sync: elapsed } }, null, 2));
    gov.tab.ws.close();
    headTab.tab.ws.close();

    // Six languages at 360 px: the home, visit and capture screens probed for clipped or spilling text.
    console.log("Languages at 360 px");
    const findings = {};
    for (const code of ["en", "hi", "bn", "or", "te", "mr"]) {
      await page.goto(`${FIELD}/field`, 2500);
      await waitFor("#field-lang-home");
      await page.select("#field-lang-home", code);
      await sleep(600);
      const probe = async (name) => {
        await page.eval("document.fonts.ready.then(() => true)");
        await sleep(400);
        findings[`${code}-${name}`] = { lang: code, issues: await page.eval(OVERFLOW_PROBE) };
      };
      await probe("home");
      await page.eval(`document.querySelector("#field-visits a").click()`);
      await waitFor("[data-item='RS-01']");
      await page.eval(`document.querySelectorAll(".field-cat").forEach((d) => { d.open = true; })`);
      await probe("visit");
      await clickSel("[data-item='VG-02']");
      await waitFor("#field-capture-form");
      await probe("capture");
      if (code === "hi" || code === "te") await page.shot(`12-capture-${code}`, `The capture screen in ${code === "hi" ? "Hindi" : "Telugu"} at 360 px`);
      await page.goto(`${FIELD}/field/queue`, 2000);
      await waitFor("#field-queue");
      await probe("queue");
    }
    await page.select("#field-lang-home", "en").catch(() => null);
    writeFileSync(join(OUT, "overflow.json"), JSON.stringify(findings, null, 2));
    const total = Object.values(findings).reduce((n, f) => n + f.issues.length, 0);
    console.log(`  overflow findings: ${total} across ${Object.keys(findings).length} screens (overflow.json)`);
    for (const [name, f] of Object.entries(findings)) for (const i of f.issues) console.log(`    ${name}: ${i.kind} ${i.px}px ${i.where} "${i.text}"`);
    if (process.argv.includes("--strict")) expect(total === 0, "no clipped or spilling text in any language at 360 px");
  } finally {
    server.kill();
    await setOffline(false).catch(() => null);
  }
}

/** `yii <args>` with extra environment (e.g. an unreachable ai-service); resolves with its output. */
function yii(args, env = {}) {
  const php = process.env.PHP ?? "C:/xampp/php/php.exe";
  return new Promise((res, rej) => {
    const p = spawn(php, [join(ROOT, "api", "yii"), ...args], { stdio: ["ignore", "pipe", "pipe"], env: { ...process.env, ...env } });
    let out = "";
    p.stdout.on("data", (d) => (out += d));
    p.stderr.on("data", (d) => (out += d));
    p.on("close", (code) => (code === 0 ? res(out.trim()) : rej(new Error(`yii ${args.join(" ")}: ${out}`))));
  });
}

async function phase7(page) {
  const GOV = "gov@dgms.gov.in";
  const HEAD = "head.od-tlc-05@coalmine.in";   // Bhubaneswari, mine 5: a repeat-violation finding
  const waitFor = async (selector, timeout = 20000) => {
    for (let waited = 0; waited < timeout; waited += 400) {
      if (await page.eval(`!!document.querySelector(${JSON.stringify(selector)})`)) return true;
      await sleep(400);
    }
    throw new Error(`waited ${timeout / 1000} s for ${selector}`);
  };
  const scrollToId = async (id) => { await page.eval(`document.getElementById(${JSON.stringify(id)}).scrollIntoView({ block: "start" })`); await sleep(700); };
  const log = [];
  const aiUp = await fetch("http://127.0.0.1:8001/health").then((r) => r.json()).catch(() => null);
  expect(aiUp?.detectors && aiUp?.risk_model, "the ai-service is running with the detectors and the model (restart it after an update)");

  console.log("Jobs with the ai-service unreachable: the PHP twins answer");
  const down = { AI_SERVICE_URL: "http://127.0.0.1:8009" };
  for (const job of ["anomaly", "score"]) log.push(`$ AI_SERVICE_URL=http://127.0.0.1:8009 yii jobs/${job}`, await yii([`jobs/${job}`], down));
  expect(log.join("\n").includes('"engine":"php"') && !log.join("\n").includes('"engine":"ai-service"'), "fallback: every detector and the model ran in PHP");

  await page.as(GOV, "/gov/mines/5");
  await waitFor("#gri-beside-score");
  let text = await page.until((t) => t.includes("Governance Risk Index") && t.includes("Predicted risk"));
  expect(text.includes("Risk index") && text.includes("What makes it up"), "the index beside the compliance score");
  await page.shot("01-gov-mine-score-and-index", "Government mine detail: compliance score (unchanged) with the Governance Risk Index beside it");
  await scrollToId("risk-panel");
  text = await page.text();
  expect(text.includes("Open violations") && text.includes("×"), "the index's components with their arithmetic");
  expect(text.includes("Trained on US regulator data"), "the predicted risk says where the model comes from");
  expect(text.includes("What raises it"), "the predicted risk explains its factors");
  expect(text.includes("Repeat violations") && text.includes("built-in check"), "a finding, computed by the PHP fallback");
  await page.shot("02-gov-risk-panel-fallback", "Risk panel: index components, predicted risk with factors and the US-data statement, findings from the PHP fallback");

  console.log("Jobs again with the ai-service up");
  for (const job of ["anomaly", "score"]) log.push(`$ yii jobs/${job}`, await yii([`jobs/${job}`]));
  expect(log.at(-3).includes('"engine":"ai-service"') && log.at(-1).includes('"engine":"ai-service"'), "the ai-service answered");
  log.push("$ yii jobs/all   (again: idempotent - nothing new)", await yii(["jobs/all"]));
  await page.goto(`${APP}/gov/mines/5`, 4500);
  await waitFor("#risk-panel");
  await scrollToId("risk-panel");
  text = await page.until((t) => t.includes("AI service"));
  expect(text.includes("AI service"), "findings and prediction now from the ai-service");
  await page.shot("03-gov-risk-panel-ai-service", "The same panel after the jobs ran on the ai-service: same findings, engine label changed");

  await page.goto(`${APP}/gov/inspections`, 4500);
  text = await page.until((t) => t.includes("Governance Risk Index") && t.includes("Risk index"));
  expect(text.includes("largest part"), "each queue row gives the index's largest component");
  await page.shot("04-priority-by-index", "Priority queue ordered by the Governance Risk Index (compliance score still shown)");
  await scrollToId("fleet-patterns");
  text = await page.text();
  for (const d of ["Production anomaly", "Flatlined sensor", "Night-shift concentration", "Repeat violations", "Late corrective actions", "Contractor outlier", "Grievance cluster"]) {
    expect(text.includes(d), `fleet findings include ${d}`);
  }
  await page.shot("05-priority-fleet-findings", "All seven detectors' findings across the fleet, each with its reasons");

  await page.goto(`${APP}/gov/mines/6`, 4500);
  text = await page.until((t) => t.includes("Pattern found"));
  expect(text.includes("Pattern found (Flatlined sensor)"), "ANOMALY_DETECTED alert worded");
  expect(/Escalated L[12]/.test(text), "escalated alerts are tagged");
  // Bring the first escalated alert into the feed's view, under the findings.
  await page.eval(`(() => { const tag = [...document.querySelectorAll(".alert-row .tag")].find((e) => /^Escalated L/.test(e.textContent));
    tag?.closest(".alert-row").scrollIntoView({ block: "end" }); window.scrollTo(0, 0); })()`);
  await sleep(500);
  await page.shot("06-anomaly-and-escalated-alerts", "Alerts: a flatlined-sensor finding (ANOMALY_DETECTED) and alerts escalated by jobs/escalate-alerts");

  await page.as(HEAD, "/mine");
  await waitFor("#risk-panel");
  await scrollToId("risk-panel");
  text = await page.until((t) => t.includes("Governance Risk Index"));
  expect(text.includes("Sensitive grievances are not counted in your view"), "the mine head is told the index leaves out sensitive grievances");
  await page.shot("07-mine-head-risk-panel", "Mine head: the same panel for their own mine, sensitive grievances left out");

  await page.as(GOV, "/gov/mines/5", "hi");
  await waitFor("#risk-panel");
  await scrollToId("risk-panel");
  await sleep(800);
  text = await page.text();
  expect(!text.includes("What raises it") && !text.includes("Trained on US regulator data"), "the panel is translated");
  await page.shot("08-risk-panel-hindi", "The risk panel in Hindi");
  await page.as(GOV, "/gov", "en");   // the account back to English

  writeFileSync(join(OUT, "jobs.txt"), log.join("\n") + "\n");
  console.log("  saved jobs.txt - the job runs (fallback, ai-service, idempotent rerun)");
}

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
    // VIEWPORT=1366x768 checks a common office-laptop size (default 1440x900).
    const [vw, vh] = (process.env.VIEWPORT ?? "1440x900").split("x").map(Number);
    await page.send("Emulation.setDeviceMetricsOverride", { width: vw, height: vh, deviceScaleFactor: 1, mobile: false });

    const side = SIDE_TABS ? await openSideTabs() : [];
    // A new tab takes the foreground, and a background tab's screenshot can wait forever:
    // bring the checked tab back to the front.
    if (side.length) await fetch(`http://127.0.0.1:${PORT}/json/activate/${target.id}`, { method: "PUT" }).catch(() => null);

    try {
      page.targetId = target.id;
      await ({ phase2, phase3, phase4, phase5, phase5b, phase6, phase7, phase7b }[PHASE] ?? phase2)(page);
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
