// Mine detail page and search panel: header actions, record tiles, card height, the "what makes it
// up" link, the score expander, alert list formatting; search by keyboard (Ctrl+K, "/", arrows,
// Enter, Esc) for each result type, corporate scoping, none for a mine head, recent searches; the
// overflow probe at 1366 and 360 px in English and Hindi. Screenshots: docs/screenshots/ui-detail-search/.
//
//   node frontend/scripts/ui_detail_search_check.mjs     (needs the stack running: run_all.bat)
import { execFileSync, spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-detail-search");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9229;
const GOV = "gov@dgms.gov.in", CORP = "corporate.secl@coalmine.in", HEAD = "head.od-tlc-05@coalmine.in";
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const source = readFileSync(join(ROOT, "scripts", "browser_check.mjs"), "utf8");
const OVERFLOW_PROBE = new Function(`return \`${source.match(/const OVERFLOW_PROBE = `([\s\S]*?)`;\r?\n/)[1]}\`;`)();

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
  const failures = []; const findings = {};
  const expect = (ok, what) => { console.log(`  ${ok ? "ok  " : "FAIL"} ${what}`); if (!ok) failures.push(what); };
  const width = (w) => p.send("Emulation.setDeviceMetricsOverride", { width: w, height: w < 768 ? 800 : 860, deviceScaleFactor: 1, mobile: w < 768 });
  const waitFor = async (sel, ms = 20000) => { for (let i = 0; i < ms / 300; i++) { if (await p.evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return true; await sleep(300); } return false; };
  const shot = async (name) => { const { data } = await p.send("Page.captureScreenshot", { format: "png" }); writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, "base64")); console.log(`  saved ${name}.png`); };
  const key = async (k, code, vk, mods = 0, text) => { await p.send("Input.dispatchKeyEvent", { type: "keyDown", key: k, code, windowsVirtualKeyCode: vk, modifiers: mods, text }); await p.send("Input.dispatchKeyEvent", { type: "keyUp", key: k, code, windowsVirtualKeyCode: vk, modifiers: mods }); };
  const signIn = async (who, lang = "en") => {
    const token = await login(who); await api(token, "/users/me", { preferred_language: lang });
    await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(900);
    await p.evaluate(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", ${JSON.stringify(lang)})`);
    return token;
  };
  const go = async (path, sel) => { await p.send("Page.navigate", { url: `${APP}${path}` }); await sleep(1200); await waitFor(sel); await p.evaluate("document.fonts.ready.then(() => true)"); await sleep(500); };
  const search = async (q) => {
    await p.evaluate(`(() => { const i = document.getElementById("search-input"); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(i, ${JSON.stringify(q)}); i.dispatchEvent(new Event("input", { bubbles: true })); })()`);
    for (let i = 0; i < 30; i++) { await sleep(200); if (await p.evaluate(`!!document.querySelector("#search-results .search-option:not([data-group=recent]), #search-empty")`)) break; }
    await sleep(200);
  };
  const saved = {};
  for (const who of [GOV, CORP, HEAD]) saved[who] = (await api(await login(who), "/users/me")).preferred_language;

  try {
    const govToken = await signIn(GOV);
    await width(1366);
    console.log("Mine detail (government, 1366 px)");
    await go("/gov/mines/5", "#mine-records");
    const d = await p.evaluate(`(() => {
      const r = (el) => el.getBoundingClientRect();
      const actions = [...document.querySelectorAll(".page-title-actions > *")].map((e) => e.textContent.trim());
      const tiles = [...document.querySelectorAll("#mine-records .record-tile")];
      const card = document.querySelector("#mine-records").closest(".panel-block"), body = card.querySelector(".panel-body");
      const last = [...body.children].at(-1);
      return { actions, tiles: tiles.length, widths: [...new Set(tiles.map((t) => Math.round(r(t).width)))], rows: new Set(tiles.map((t) => Math.round(r(t).top))).size,
        gap: Math.round(r(card).bottom - r(last).bottom), loneButtons: body.querySelectorAll(":scope > .row > button, .records-bar").length,
        formulaHidden: !document.querySelector("#score-how, .formula"), text: document.body.innerText };
    })()`);
    expect(d.actions.length === 2 && /Back/.test(d.actions[0]) && /Flag for inspection/.test(d.actions[1]), `header actions: ${d.actions.join(" | ")}`);
    expect(d.tiles === 5 && d.widths.length === 1 && d.rows === 1, `five equal tiles in one row (${d.tiles}, widths ${d.widths}, rows ${d.rows})`);
    expect(d.loneButtons === 0, "no lone buttons in the summary card");
    expect(d.gap <= 26, `card fits its content (${d.gap}px below the last item: the card padding and border)`);
    expect(d.formulaHidden, "no score formula on the page");
    expect(!/drafts: -/.test(d.text) && !/[A-Z]{3}-\d{2},[A-Z]/.test(d.text), "alert text: no empty parts, list separators with spaces");
    await shot("mine-detail-gov-1366");
    await p.evaluate(`document.getElementById("gri-explain-link").click()`); await sleep(1200);
    const g = await p.evaluate(`(() => { const t = document.querySelector("#governance-risk h2").getBoundingClientRect(), h = document.querySelector(".masthead").getBoundingClientRect();
      return { below: t.top >= h.bottom, inView: t.top < innerHeight, focused: document.activeElement?.id === "governance-risk", link: getComputedStyle(document.getElementById("gri-explain-link")).textDecorationLine }; })()`);
    expect(g.below && g.inView && g.focused && g.link.includes("underline"), `"What makes it up" is an underlined link that scrolls to the index card (${JSON.stringify(g)})`);
    await p.evaluate("scrollTo(0, 0)");
    await width(360); await go("/gov/mines/5", "#mine-records");
    const m = await p.evaluate(`(() => { const tiles = [...document.querySelectorAll("#mine-records .record-tile")].map((t) => t.getBoundingClientRect());
      const rows = {}; tiles.forEach((t) => { const k = Math.round(t.top); rows[k] = (rows[k] ?? 0) + 1; }); return Object.values(rows); })()`);
    expect(JSON.stringify(m) === "[2,2,1]", `360 px: two tiles per row, the last across the row (${m})`);
    await p.evaluate(`document.getElementById("mine-records").scrollIntoView({ block: "center" })`); await sleep(400);
    await shot("mine-detail-gov-360");

    console.log("Mine detail (mine head, 1366 px)");
    await signIn(HEAD); await width(1366); await go("/mine", "#mine-records");
    const h = await p.evaluate(`({ actions: [...document.querySelectorAll(".page-title-actions > button")].map((e) => e.textContent.trim()), tiles: document.querySelectorAll("#mine-records .record-tile").length, search: !!document.getElementById("search-trigger") })`);
    expect(h.actions.length === 1 && h.tiles === 5, `mine head keeps its own action in the header (${h.actions}), five tiles`);
    expect(!h.search, "no search for a mine head");
    await key("k", "KeyK", 75, 2); await sleep(400);
    expect(!(await p.evaluate(`!!document.getElementById("search-panel")`)), "Ctrl+K does nothing for a mine head");
    await shot("mine-detail-head-1366");

    console.log("Search (government)");
    await signIn(GOV); await go("/gov", "#search-trigger");
    await p.evaluate(`localStorage.removeItem("smg.recentSearches")`);
    await key("k", "KeyK", 75, 2); await sleep(400);
    expect(await p.evaluate(`document.activeElement?.id === "search-input"`), "Ctrl+K opens the panel with the input focused");
    await search("moon");
    expect(await p.evaluate(`document.querySelector("#search-results .search-option[data-group=mines]")?.textContent.includes("Moonidih")`), "finds a mine by name");
    await shot("search-panel-results");
    await key("Enter", "Enter", 13, 0, "\r"); await sleep(1500);
    expect(await p.evaluate(`location.pathname`) === "/gov/mines/1", "Enter opens the mine's page");
    await p.send("Page.navigate", { url: `${APP}/gov` }); await sleep(1500); await waitFor("#search-trigger");
    await key("/", "Slash", 191, 0, "/"); await sleep(400);
    expect(await p.evaluate(`!!document.getElementById("search-panel")`), '"/" opens the panel');
    expect(await p.evaluate(`document.querySelector("#search-results .search-option[data-group=recent]")?.textContent.includes("moon")`), "recent searches are shown");
    const grievance = (await api(govToken, "/search?q=GRV")).grievances[0];
    await search(grievance.ticket_no);
    await key("Enter", "Enter", 13, 0, "\r"); await sleep(2500);
    expect(await p.evaluate(`location.search.includes("tab=grievances") && !!document.querySelector(".drawer")`), "a grievance ticket opens its drawer on the Grievances tab");
    await key("Escape", "Escape", 27); await sleep(400);
    const contractor = (await api(govToken, "/contractors?per_page=1"))[0];
    await p.evaluate(`document.getElementById("search-trigger").click()`); await sleep(400);
    await search(contractor.registration_no ?? contractor.contractor?.registration_no ?? contractor.name);
    await key("ArrowDown", "ArrowDown", 40); await key("ArrowUp", "ArrowUp", 38);
    await key("Enter", "Enter", 13, 0, "\r"); await sleep(2500);
    expect(await p.evaluate(`location.search.includes("tab=contractors") && !!document.querySelector(".drawer")`), "a contractor opens its drawer on the Contractors tab (arrows move the selection)");
    await key("Escape", "Escape", 27); await sleep(400);
    const obligation = (await api(govToken, "/obligations"))[0];
    await p.evaluate(`document.getElementById("search-trigger").click()`); await sleep(400);
    await search(obligation.code);
    await key("Enter", "Enter", 13, 0, "\r"); await sleep(2500);
    expect(await p.evaluate(`location.search.includes("tab=obligations") && !!document.getElementById("obligation-drawer")`), "an obligation opens its register entry on the Obligations tab");
    await key("Escape", "Escape", 27); await sleep(400);
    await p.evaluate(`document.getElementById("search-trigger").click()`); await sleep(400);
    await search("zzqqxx");
    expect(await p.evaluate(`!!document.getElementById("search-empty")`), '"No results" state');
    await shot("search-panel-no-results");
    await key("Escape", "Escape", 27); await sleep(400);
    expect(!(await p.evaluate(`!!document.getElementById("search-panel")`)) && await p.evaluate(`document.activeElement?.id === "search-trigger"`), "Esc closes it and returns focus");

    console.log("Search (corporate SECL)");
    await signIn(CORP); await go("/gov", "#search-trigger");
    await p.evaluate(`document.getElementById("search-trigger").click()`); await sleep(400);
    await search("coal");
    const subs = await p.evaluate(`[...document.querySelectorAll("#search-results .search-option[data-group=mines] .search-option-sub")].map((e) => e.textContent)`);
    expect(subs.length > 0 && subs.every((s) => s.endsWith("SECL")), `corporate sees only its company's mines (${subs.length})`);
    await search("JH-DHN-01");
    expect(await p.evaluate(`!document.querySelector("#search-results .search-option[data-group=mines]")`), "a mine of another company is not found");
    await key("Escape", "Escape", 27);

    console.log("Overflow probe: 1366 / 360 px, English / Hindi");
    for (const lang of ["en", "hi"]) {
      for (const w of [1366, 360]) {
        await width(w);
        await signIn(GOV, lang);
        await go("/gov/mines/5", "#mine-records");
        const a = await p.evaluate(OVERFLOW_PROBE);
        await go("/gov", "#search-trigger");
        await p.evaluate(`document.getElementById("search-trigger").click()`); await sleep(400);
        await search("coal");
        const b = await p.evaluate(OVERFLOW_PROBE);
        await key("Escape", "Escape", 27);
        await signIn(HEAD, lang); await go("/mine", "#mine-records");
        const c = await p.evaluate(OVERFLOW_PROBE);
        for (const [k, v] of [[`detail-gov-${lang}-${w}`, a], [`search-${lang}-${w}`, b], [`detail-head-${lang}-${w}`, c]]) if (v.length) findings[k] = v;
        console.log(`  ${lang} ${w}: detail ${a.length}, search ${b.length}, mine head ${c.length}`);
        if (lang === "hi" && w === 360) { await signIn(GOV, lang); await go("/gov", "#search-trigger"); await p.evaluate(`document.getElementById("search-trigger").click()`); await sleep(400); await search("coal"); await shot("search-panel-hi-360"); await key("Escape", "Escape", 27); }
      }
    }
  } finally {
    for (const who of [GOV, CORP, HEAD]) await api(await login(who), "/users/me", { preferred_language: saved[who] });
  }
  writeFileSync(join(OUT, "overflow.json"), JSON.stringify(findings, null, 2));
  const total = Object.values(findings).reduce((n, f) => n + f.length, 0);
  for (const [k, list] of Object.entries(findings)) for (const f of list) console.log(`  ${k}: ${f.kind} ${f.px}px ${f.where} "${f.text}"`);
  console.log(`\n${total} overflow finding(s), ${failures.length} failed check(s), ${p.errors.length} browser error(s)`);
  if (total || failures.length || p.errors.length) throw new Error("check failed");
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
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-ds-"))}`,
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
  } finally { stopBrowser(browser); }
}
main().catch((e) => { console.error(e.message); process.exit(1); });
