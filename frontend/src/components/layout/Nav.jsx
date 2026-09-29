// Navigation drawer: replaces the permanent sidebar. A hamburger at the far left of the top bar opens
// it over the page - on hover (a mouse), and on click, tap or Enter / Space (phones have no hover).
// It closes 300 ms after the pointer leaves, on Esc, or on a click outside.
//
// A tabbed page shows five tabs on its bar; the rest are published here (Masthead) and listed in
// the drawer. Tab state stays local to the page: the drawer only calls the page's onChange.
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { NavLink } from "react-router-dom";
import { LogOut, Menu, UserRound } from "lucide-react";
import { useT } from "../../i18n/t";
import { TabIcon } from "../common/Tabs";

const NavContext = createContext(null);
const CLOSE_DELAY_MS = 300;
export const APP_NAME = "Smart Mine Governance";

export function NavProvider({ children }) {
  const [open, setOpen] = useState(false);
  const [pageTabs, setPageTabs] = useState(null);   // { tabs, active, onChange } of the drawer's tabs
  const timer = useRef(null);
  const cancelClose = useCallback(() => { clearTimeout(timer.current); timer.current = null; }, []);
  const closeSoon = useCallback(() => { cancelClose(); timer.current = setTimeout(() => setOpen(false), CLOSE_DELAY_MS); }, [cancelClose]);
  const value = useMemo(() => ({ open, setOpen, cancelClose, closeSoon, pageTabs, setPageTabs }),
    [open, cancelClose, closeSoon, pageTabs]);
  return <NavContext.Provider value={value}>{children}</NavContext.Provider>;
}

export const useNav = () => useContext(NavContext);

export function Logo() {
  return <img className="app-logo" src={`${import.meta.env.BASE_URL}favicon.svg`} alt="" width="28" height="28" />;
}

/** Hamburger, logo and app name - and the current tab's name when that tab lives in the drawer. */
export function NavToggle() {
  const t = useT();
  const nav = useNav();
  if (!nav) return null;
  const current = nav.pageTabs?.tabs.find((tab) => tab.id === nav.pageTabs.active);
  const fromMouse = (e) => e.pointerType === "mouse";
  return (
    <div className="masthead-brand">
      <button type="button" className="nav-toggle" id="nav-toggle" aria-label={t("nav.menu")} title={t("nav.menu")}
              aria-expanded={nav.open} aria-controls="nav-drawer"
              onPointerEnter={(e) => { if (fromMouse(e)) { nav.cancelClose(); nav.setOpen(true); } }}
              onPointerLeave={(e) => { if (fromMouse(e)) nav.closeSoon(); }}
              // A mouse has already opened it by hovering: its click keeps it open. A tap or a key toggles.
              onClick={(e) => { nav.cancelClose(); nav.setOpen(e.nativeEvent.pointerType === "mouse" ? true : !nav.open); }}>
        <Menu size={22} aria-hidden="true" />
      </button>
      <Logo />
      <span className="brand-name">{APP_NAME}</span>
      {current && (
        <span className="nav-current" id="nav-current"><TabIcon id={current.id} />{current.label}</span>
      )}
    </div>
  );
}

export function NavDrawer({ who, scope, onSignOut }) {
  const t = useT();
  const nav = useNav();
  const ref = useRef(null);
  const { open, setOpen, pageTabs } = nav;

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
      <div className="nav-drawer-brand"><Logo /><strong>{APP_NAME}</strong></div>

      {pageTabs?.tabs.length > 0 && (
        <div className="nav-section">
          <span className="label">{t("nav.more")}</span>
          <ul className="nav-list" role="tablist" aria-orientation="vertical">
            {pageTabs.tabs.map((tab) => (
              <li key={tab.id}>
                <button type="button" role="tab" className="nav-item" aria-selected={pageTabs.active === tab.id}
                        aria-controls={`panel-${tab.id}`} data-tab={tab.id}
                        onClick={() => { pageTabs.onChange(tab.id); setOpen(false); }}>
                  <TabIcon id={tab.id} />{tab.label}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="nav-foot">
        <div className="who">{who}</div>
        <div className="role">{scope}</div>
        <NavLink to="/profile" className="nav-item" id="profile-link" onClick={() => setOpen(false)}>
          <UserRound size={18} aria-hidden="true" />{t("shell.profile")}
        </NavLink>
        <button type="button" className="nav-item" onClick={onSignOut}>
          <LogOut size={18} aria-hidden="true" />{t("shell.signOut")}
        </button>
      </div>
    </nav>
  );
}
