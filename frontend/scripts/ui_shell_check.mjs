// One app shell on every signed-in page: opens each signed-in route for each role and fails if the
// top bar (hamburger, logo link, five tabs), the drawer items (More views + Profile and language,
// the user block, Sign out) or the footer are missing or differ, or if a page renders its own
// header, sidebar or footer. Also checks the active-tab highlight (?tab=, the drawer, Profile) and
// that tabs chosen on the Profile page open on the user's home. Screenshots of the Profile page
// (drawer closed and open) go to docs/screenshots/ui-nav/.
//
//   node frontend/scripts/ui_shell_check.mjs     (needs the stack running: run_all.bat)
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..", "..");
const OUT = join(ROOT, "docs", "screenshots", "ui-nav");
const APP = process.env.APP_URL ?? "http://localhost:5173";
const API = process.env.API_URL ?? "http://localhost:8080/v1";
const PORT = 9227;
const BROWSERS = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const MULTI = { bar: ["overview", "priority", "obligations", "production", "map"], drawer: ["sensors", "trends", "contractors", "grievances", "profile"] };
const HEAD = { bar: ["overview", "production", "obligations", "contractors", "grievances"], drawer: ["sensors", "trends", "map", "profile"] };
// [route, bar tab highlighted, drawer item highlighted, final path]
const multiRoutes = (mine) => [
  ["/gov", "overview", null, "/gov"], ["/gov?tab=production", "production", null, "/gov"], ["/gov?tab=sensors", null, "sensors", "/gov"],
  ["/gov/inspections", "priority", null, "/gov"], [`/gov/mines/${mine}`, null, null, `/gov/mines/${mine}`], ["/profile", null, "profile", "/profile"],
];
const ROLES = [
  { who: "gov@dgms.gov.in", set: MULTI, home: "/gov", routes: multiRoutes },
  { who: "corporate.secl@coalmine.in", set: MULTI, home: "/gov", routes: multiRoutes },
  { who: "inspector.01@dgms.example", set: MULTI, home: "/gov", routes: multiRoutes },
  { who: "head.od-tlc-05@coalmine.in", set: HEAD, home: "/mine", routes: () => [
    ["/mine", "overview", null, "/mine"], ["/mine?tab=grievances", "grievances", null, "/mine"], ["/mine?tab=map", null, "map", "/mine"],
    ["/profile", null, "profile", "/profile"]] },
];

const SHELL = `(() => {
  const q = (s) => document.querySelector(s), qa = (s) => [...document.querySelectorAll(s)];
  return {
    path: location.pathname,
    headers: qa("header").length, footers: qa("footer").length, sidebars: qa(".sidebar, aside").length,
    topBar: !!q("#app-top-bar"), toggle: !!q("#app-top-bar #nav-toggle"), logo: !!q("#app-top-bar a.home-link img.app-logo"),
    bar: qa("#app-top-bar .tab").map((b) => b.dataset.tab),
    drawer: qa("#nav-drawer .nav-list [data-tab]").map((b) => b.dataset.tab),
    drawerLogo: !!q("#nav-drawer a.home-link"), who: (q("#nav-drawer .nav-foot .who")?.textContent ?? "").trim().length > 0,
    signOut: !!q("#nav-drawer .nav-foot button"), footer: qa(".site-footer #data-sources").length,
    barActive: qa("#app-top-bar .tab[aria-selected='true']").map((b) => b.dataset.tab),
    drawerActive: qa("#nav-drawer [aria-current], #nav-drawer .nav-item.active").map((b) => b.dataset.tab),
    underline: getComputedStyle(q("#app-top-bar a.home-link")).textDecorationLine,
  };
})()`;

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
  const failures = [];
  const expect = (ok, what) => { if (!ok) { failures.push(what); console.log(`  FAIL ${what}`); } return ok; };
  const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
  const waitFor = async (sel) => { for (let i = 0; i < 60; i++) { if (await p.evaluate(`!!document.querySelector(${JSON.stringify(sel)})`)) return; await sleep(300); } throw new Error(`no ${sel}`); };
  const go = async (path) => { await p.send("Page.navigate", { url: `${APP}${path}` }); await sleep(1200); await waitFor("#app-top-bar .tab"); await waitFor(".page-title, .panel"); await sleep(500); };
  const shot = async (name) => { const { data } = await p.send("Page.captureScreenshot", { format: "png" }); writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, "base64")); console.log(`  saved ${name}.png`); };
  await p.send("Emulation.setDeviceMetricsOverride", { width: 1366, height: 860, deviceScaleFactor: 1, mobile: false });

  for (const role of ROLES) {
    const token = await login(role.who);
    const saved = (await api(token, "/users/me")).preferred_language;
    await api(token, "/users/me", { preferred_language: "en" });
    try {
      const mines = await api(token, "/mines");
      const mine = (Array.isArray(mines) ? mines : mines.items ?? [])[0]?.id;
      await p.send("Page.navigate", { url: `${APP}/login` }); await sleep(900);
      await p.evaluate(`sessionStorage.setItem("smg.token", ${JSON.stringify(token)}); localStorage.setItem("smg.lang", "en")`);
      let first = null;
      for (const [route, barActive, drawerActive, finalPath] of role.routes(mine)) {
        await go(route);
        const s = await p.evaluate(SHELL);
        const where = `${role.who} ${route}`;
        const shell = { bar: s.bar, drawer: s.drawer, topBar: s.topBar, toggle: s.toggle, logo: s.logo, drawerLogo: s.drawerLogo, who: s.who, signOut: s.signOut, footer: s.footer };
        first ??= shell;
        const ok = [
          expect(s.path === finalPath, `${where}: lands on ${finalPath} (got ${s.path})`),
          expect(s.headers === 1 && s.footers === 1 && s.sidebars === 0, `${where}: exactly one header and one footer, no sidebar (${s.headers}/${s.footers}/${s.sidebars})`),
          expect(s.topBar && s.toggle && s.logo && s.drawerLogo && s.who && s.signOut && s.footer === 1, `${where}: top bar, logo links, user block, Sign out and footer present`),
          expect(same(s.bar, role.set.bar), `${where}: bar tabs ${s.bar}`),
          expect(same(s.drawer, role.set.drawer), `${where}: drawer items ${s.drawer}`),
          expect(same(shell, first), `${where}: shell identical to ${role.routes(mine)[0][0]}`),
          expect(same(s.barActive, barActive ? [barActive] : []), `${where}: bar highlight ${s.barActive} (want ${barActive})`),
          expect(same(s.drawerActive, drawerActive ? [drawerActive] : []), `${where}: drawer highlight ${s.drawerActive} (want ${drawerActive})`),
          expect(s.underline === "none", `${where}: logo link not underlined`),
        ].every(Boolean);
        console.log(`  ${ok ? "ok  " : "FAIL"} ${where}`);
      }
      // From the Profile page: a bar tab and a drawer item open that tab on the home page.
      const barTarget = role.set.bar[2], drawerTarget = role.set.drawer[0];
      await go("/profile");
      if (role.who === "gov@dgms.gov.in") {
        await shot("profile-drawer-closed");
        await p.evaluate(`document.getElementById("nav-toggle").click()`); await sleep(500);
        await shot("profile-drawer-open");
        await p.evaluate(`document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }))`); await sleep(300);
      }
      await p.evaluate(`document.querySelector('#app-top-bar .tab[data-tab="${barTarget}"]').click()`); await sleep(1500);
      let at = await p.evaluate(`location.pathname + location.search + "|" + !!document.getElementById("panel-${barTarget}")`);
      expect(at === `${role.home}?tab=${barTarget}|true`, `${role.who}: bar tab from Profile opens ${role.home}?tab=${barTarget} (got ${at})`);
      await go("/profile");
      await p.evaluate(`document.querySelector('#nav-drawer [data-tab="${drawerTarget}"]').click()`); await sleep(1500);
      at = await p.evaluate(`location.pathname + location.search + "|" + !!document.getElementById("panel-${drawerTarget}") + "|" + (document.getElementById("nav-current")?.textContent ?? "")`);
      expect(at.startsWith(`${role.home}?tab=${drawerTarget}|true|`) && !at.endsWith("|"), `${role.who}: drawer item from Profile opens ${role.home}?tab=${drawerTarget}, named beside the hamburger (got ${at})`);
      console.log(`  ok   ${role.who}: tabs from Profile open on ${role.home}`);
    } finally {
      await api(token, "/users/me", { preferred_language: saved });
    }
  }
  console.log(`\n${failures.length} failed check(s), ${p.errors.length} browser error(s)`);
  if (failures.length || p.errors.length) throw new Error("shell check failed");
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const exe = BROWSERS.find(existsSync);
  const browser = spawn(exe, ["--headless=new", `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), "cs-shell-"))}`,
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
