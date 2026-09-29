// The app shell's navigation, the same on every signed-in page (AppShell renders it; no page renders
// its own header, sidebar or footer):
//   - top bar: hamburger, logo + app name (a link home), the current drawer page's name, five tabs;
//   - drawer: the other tabs ("More views") and Profile and language, then the user and Sign out.
// The drawer opens over the page on hover (a mouse), and on click, tap or Enter / Space (phones have
// no hover); it closes 300 ms after the pointer leaves, on Esc, or on a click outside.
// Tabs come from navTabs.js; choosing one opens it on the user's home page (?tab=).
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { Link, NavLink, useLocation } from "react-router-dom";
import { LogOut, Menu, UserRound } from "lucide-react";
import { useT } from "../../i18n/t";
import { TabIcon, Tabs } from "../common/Tabs";
import { useHomeTabs } from "./navTabs";
import { useMastheadHeight } from "./useMastheadHeight";

const NavContext = createContext(null);
const CLOSE_DELAY_MS = 300;
export const APP_NAME = "Smart Mine Governance";
const PROFILE_PATH = "/profile";

export function NavProvider({ children }) {
  const [open, setOpen] = useState(false);
  const timer = useRef(null);
  const cancelClose = useCallback(() => { clearTimeout(timer.current); timer.current = null; }, []);
  const closeSoon = useCallback(() => { cancelClose(); timer.current = setTimeout(() => setOpen(false), CLOSE_DELAY_MS); }, [cancelClose]);
  const value = useMemo(() => ({ open, setOpen, cancelClose, closeSoon }), [open, cancelClose, closeSoon]);
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
    <Link to={home} className={`home-link ${className}`} onClick={() => nav.setOpen(false)}>
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
  const inDrawer = drawerTabs.find((tab) => tab.id === active);
  const current = inDrawer
    ? { id: inDrawer.id, label: inDrawer.label }
    : location.pathname === PROFILE_PATH ? { id: "profile", label: t("shell.profile") } : null;
  const fromMouse = (e) => e.pointerType === "mouse";
  return (
    <header className="masthead" ref={ref} id="app-top-bar">
      <div className="masthead-brand">
        <button type="button" className="nav-toggle" id="nav-toggle" aria-label={t("nav.menu")} title={t("nav.menu")}
                aria-expanded={nav.open} aria-controls="nav-drawer"
                onPointerEnter={(e) => { if (fromMouse(e)) { nav.cancelClose(); nav.setOpen(true); } }}
                onPointerLeave={(e) => { if (fromMouse(e)) nav.closeSoon(); }}
                // A mouse has already opened it by hovering: its click keeps it open. A tap or a key toggles.
                onClick={(e) => { nav.cancelClose(); nav.setOpen(e.nativeEvent.pointerType === "mouse" ? true : !nav.open); }}>
          <Menu size={22} aria-hidden="true" />
        </button>
        <HomeLink />
        {current && (
          <span className="nav-current" id="nav-current">
            {current.id === "profile" ? <UserRound size={18} aria-hidden="true" /> : <TabIcon id={current.id} />}{current.label}
          </span>
        )}
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
  const { open, setOpen } = nav;
  const { drawerTabs, active, onHome, goTo } = useHomeTabs();

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => {
      if (e.key === "Escape") { setOpen(false); document.getElementById("nav-toggle")?.focus(); }
    };
    const onDown = (e) => {
      if (!ref.current?.contains(e.target) && !document.getElementById("nav-toggle")?.contains(e.target)) setOpen(false);
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onDown);
    return () => { document.removeEventListener("keydown", onKey); document.removeEventListener("pointerdown", onDown); };
  }, [open, setOpen]);

  // Opened from the keyboard: move focus into the drawer.
  useEffect(() => {
    if (open && document.activeElement?.id === "nav-toggle") ref.current?.querySelector("button, a")?.focus({ preventScroll: true });
  }, [open]);

  return (
    <nav className={`nav-drawer${open ? " is-open" : ""}`} id="nav-drawer" ref={ref} aria-label={t("nav.menu")}
         onPointerEnter={(e) => { if (e.pointerType === "mouse") nav.cancelClose(); }}
         onPointerLeave={(e) => { if (e.pointerType === "mouse") nav.closeSoon(); }}>
      <HomeLink className="nav-drawer-brand"><strong>{APP_NAME}</strong></HomeLink>

      <div className="nav-section">
        <span className="label">{t("nav.more")}</span>
        <ul className="nav-list">
          {drawerTabs.map((tab) => (
            <li key={tab.id}>
              <button type="button" className="nav-item" data-tab={tab.id}
                      aria-current={active === tab.id ? "true" : undefined}
                      aria-controls={onHome ? `panel-${tab.id}` : undefined}
                      onClick={() => { goTo(tab.id); setOpen(false); }}>
                <TabIcon id={tab.id} />{tab.label}
              </button>
            </li>
          ))}
          <li>
            <NavLink to={PROFILE_PATH} className="nav-item" id="profile-link" data-tab="profile" onClick={() => setOpen(false)}>
              <UserRound size={18} aria-hidden="true" />{t("shell.profile")}
            </NavLink>
          </li>
        </ul>
      </div>

      <div className="nav-foot">
        <div className="who">{who}</div>
        <div className="role">{scope}</div>
        <button type="button" className="nav-item" onClick={onSignOut}>
          <LogOut size={18} aria-hidden="true" />{t("shell.signOut")}
        </button>
      </div>
    </nav>
  );
}
