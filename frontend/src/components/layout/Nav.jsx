// The app shell's navigation, the same on every signed-in page (AppShell renders it; no page renders
// its own header, sidebar or footer):
//   - top bar: hamburger, logo + app name (a link home), the current drawer page's name, five tabs;
//   - drawer: the other tabs ("More views") and Profile and language, then the user and Sign out.
// The drawer opens over the page when the pointer rests 120 ms on the hamburger (hover intent), and at
// once on click, tap or Enter / Space (phones have no hover); it closes 300 ms after the pointer has
// left both the hamburger and the drawer, on Esc, or on a click outside. It stays mounted off-screen
// and only its transform and opacity animate (with a light navy backdrop that fades in step), so
// opening and closing never reflow the page (styles/index.css, "navigation drawer").
// Tabs come from navTabs.js; choosing one opens it on the user's home page (?tab=).
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { Link, NavLink, useLocation } from "react-router-dom";
import { DemoBadge } from "../auth/DemoAccess";
import { LogOut, Menu, UserRound } from "lucide-react";
import { useT } from "../../i18n/t";
import { TabIcon, Tabs } from "../common/Tabs";
import { GlobalSearch } from "../search/GlobalSearch";
import { useHomeTabs } from "./navTabs";
import { useMastheadHeight } from "./useMastheadHeight";

const NavContext = createContext(null);
const OPEN_DELAY_MS = 120;   // hover intent: the pointer must rest on the hamburger this long
const CLOSE_DELAY_MS = 300;  // after leaving both the hamburger and the drawer
export const APP_NAME = "COALSHIELD";
const PROFILE_PATH = "/profile";

export function NavProvider({ children }) {
  const [open, setOpen] = useState(false);
  const timer = useRef(null);
  // One pending timer, either an intended open or a delayed close; any new intent replaces it.
  const cancelClose = useCallback(() => { clearTimeout(timer.current); timer.current = null; }, []);
  // After an explicit close (Esc, a click outside, choosing an item) a pointer still resting on the
  // hamburger must not reopen it by hover; it may again once the pointer has left the icon.
  const hoverBlocked = useRef(false);
  const openSoon = useCallback(() => {
    cancelClose();
    if (!hoverBlocked.current) timer.current = setTimeout(() => setOpen(true), OPEN_DELAY_MS);
  }, [cancelClose]);
  const closeSoon = useCallback(() => { cancelClose(); timer.current = setTimeout(() => setOpen(false), CLOSE_DELAY_MS); }, [cancelClose]);
  const openNow = useCallback(() => { cancelClose(); setOpen(true); }, [cancelClose]);
  const closeNow = useCallback(() => { cancelClose(); hoverBlocked.current = true; setOpen(false); }, [cancelClose]);
  const releaseHover = useCallback(() => { hoverBlocked.current = false; }, []);
  const value = useMemo(() => ({ open, setOpen, cancelClose, openSoon, closeSoon, openNow, closeNow, releaseHover }),
    [open, cancelClose, openSoon, closeSoon, openNow, closeNow, releaseHover]);
  return <NavContext.Provider value={value}>{children}</NavContext.Provider>;
}

const useNav = () => useContext(NavContext);

export function Logo() {
  return <img className="app-logo" src={`${import.meta.env.BASE_URL}favicon.svg`} alt="" width="28" height="28" />;
}

/** Logo and app name as a link to the user's home (its Overview tab). Closes the drawer. */
function HomeLink({ className = "", children }) {
  const { home } = useHomeTabs();
  const nav = useNav();
  return (
    <Link to={home} className={`home-link ${className}`} onClick={() => nav.closeNow()} aria-label={APP_NAME}>
      <Logo />{children ?? <span className="brand-name">{APP_NAME}</span>}
    </Link>
  );
}

/** The shell's top bar: hamburger, home link, the current drawer page's name, and the five bar tabs. */
export function TopBar() {
  const t = useT();
  const nav = useNav();
  const location = useLocation();
  const { barTabs, drawerTabs, active, onHome, goTo } = useHomeTabs();
  const ref = useRef(null);
  useMastheadHeight(ref);
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 4);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);
  const inDrawer = drawerTabs.find((tab) => tab.id === active);
  const current = inDrawer
    ? { id: inDrawer.id, label: inDrawer.label }
    : location.pathname === PROFILE_PATH ? { id: "profile", label: t("shell.profile") } : null;
  const fromMouse = (e) => e.pointerType === "mouse";
  return (
    <header className={`masthead${scrolled ? " is-scrolled" : ""}`} ref={ref} id="app-top-bar">
      <div className="masthead-brand">
        <button type="button" className="nav-toggle" id="nav-toggle" aria-label={t("nav.menu")} title={t("nav.menu")}
                aria-expanded={nav.open} aria-controls="nav-drawer"
                onPointerEnter={(e) => { if (fromMouse(e)) { if (nav.open) nav.cancelClose(); else nav.openSoon(); } }}
                onPointerLeave={(e) => { if (fromMouse(e)) { nav.releaseHover(); if (nav.open) nav.closeSoon(); else nav.cancelClose(); } }}
                // A mouse click opens at once (and keeps it open); a tap or a key toggles at once.
                onClick={(e) => { if (e.nativeEvent.pointerType === "mouse" || !nav.open) nav.openNow(); else nav.closeNow(); }}>
          <Menu size={22} aria-hidden="true" />
        </button>
        <HomeLink />
        <DemoBadge />
        {current && (
          <span className="nav-current" id="nav-current">
            {current.id === "profile" ? <UserRound size={18} aria-hidden="true" /> : <TabIcon id={current.id} />}{current.label}
          </span>
        )}
        <GlobalSearch />
      </div>
      <div className="spacer" />
      <Tabs tabs={barTabs} active={active} onChange={goTo} controls={onHome} />
    </header>
  );
}

export function NavDrawer({ who, scope, onSignOut }) {
  const t = useT();
  const nav = useNav();
  const ref = useRef(null);
  const { open, closeNow } = nav;
  const { barTabs, drawerTabs, active, onHome, goTo } = useHomeTabs();

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => {
      if (e.key === "Escape") { closeNow(); document.getElementById("nav-toggle")?.focus(); }
    };
    const onDown = (e) => {
      if (!ref.current?.contains(e.target) && !document.getElementById("nav-toggle")?.contains(e.target)) closeNow();
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onDown);
    return () => { document.removeEventListener("keydown", onKey); document.removeEventListener("pointerdown", onDown); };
  }, [open, closeNow]);

  // Opened from the keyboard: move focus into the drawer.
  useEffect(() => {
    if (open && document.activeElement?.id === "nav-toggle") ref.current?.querySelector("button, a")?.focus({ preventScroll: true });
  }, [open]);

  // Stagger order for the open animation (each item 20 ms after the one before, at most 200 ms).
  let order = 0;
  const stagger = () => ({ className: "nav-stagger", style: { "--i": order++ } });
  return (
    <>
    <div className={`nav-backdrop${open ? " is-open" : ""}`} aria-hidden="true" />
    {/* Closed, it stays mounted off-screen and inert: out of focus, clicks and screen readers. */}
    <nav className={`nav-drawer${open ? " is-open" : ""}`} id="nav-drawer" ref={ref} aria-label={t("nav.menu")} {...(open ? {} : { inert: "" })}
         onPointerEnter={(e) => { if (e.pointerType === "mouse") nav.cancelClose(); }}
         onPointerLeave={(e) => { if (e.pointerType === "mouse") nav.closeSoon(); }}>
      <div {...stagger()}><HomeLink className="nav-drawer-brand"><strong>{APP_NAME}</strong></HomeLink></div>

      {/* Phones only (below 768 px the top bar shows no tabs): the five bar tabs, here. */}
      <div className="nav-section nav-main-views">
        <span className="label nav-stagger" style={{ "--i": order++ }}>{t("nav.main")}</span>
        <ul className="nav-list nav-list-main">
          {barTabs.map((tab) => (
            <li key={tab.id} {...stagger()}>
              <button type="button" className="nav-item" data-main-tab={tab.id}
                      aria-current={active === tab.id ? "true" : undefined}
                      onClick={() => { goTo(tab.id); closeNow(); }}>
                <TabIcon id={tab.id} />{tab.label}
              </button>
            </li>
          ))}
        </ul>
      </div>

      <div className="nav-section">
        <span className="label nav-stagger" style={{ "--i": order++ }}>{t("nav.more")}</span>
        <ul className="nav-list">
          {drawerTabs.map((tab) => (
            <li key={tab.id} {...stagger()}>
              <button type="button" className="nav-item" data-tab={tab.id}
                      aria-current={active === tab.id ? "true" : undefined}
                      aria-controls={onHome ? `panel-${tab.id}` : undefined}
                      onClick={() => { goTo(tab.id); closeNow(); }}>
                <TabIcon id={tab.id} />{tab.label}
              </button>
            </li>
          ))}
          <li {...stagger()}>
            <NavLink to={PROFILE_PATH} className="nav-item" id="profile-link" data-tab="profile" onClick={() => closeNow()}>
              <UserRound size={18} aria-hidden="true" />{t("shell.profile")}
            </NavLink>
          </li>
        </ul>
      </div>

      <div className="nav-foot nav-stagger" style={{ "--i": order++ }}>
        <div className="who">{who}</div>
        <div className="role">{scope}</div>
        <button type="button" className="nav-item" onClick={onSignOut}>
          <LogOut size={18} aria-hidden="true" />{t("shell.signOut")}
        </button>
      </div>
    </nav>
    </>
  );
}
