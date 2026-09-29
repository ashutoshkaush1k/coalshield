// Search panel for government and corporate (`search.global`): a search button in the top bar, and
// Ctrl+K (Cmd+K) or "/" anywhere outside a text field. It searches mines, contractors, grievances
// (ticket number) and obligations through GET /v1/search - the API scopes every group, so a
// corporate account only ever sees its own companies. Results are grouped, up to 5 per group;
// arrows move, Enter opens (a mine's page, or the drawer on its tab), Esc closes. The input is
// debounced; the last few searches are kept in this browser only (localStorage).
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";
import { ClipboardCheck, Clock, HardHat, Factory, MessageSquareWarning, Search, X } from "lucide-react";
import { searchAll } from "../../api/search";
import { can } from "../../auth/permissions";
import { useAuth } from "../../hooks/useAuth";
import { obligationTitle } from "../../i18n/labels";
import { useT } from "../../i18n/t";
import { useHomeTabs } from "../layout/navTabs";

const DEBOUNCE_MS = 250;
const MIN_LENGTH = 2;
const RECENT_KEY = "smg.recentSearches";
const RECENT_MAX = 5;

const readRecent = () => {
  try { return JSON.parse(localStorage.getItem(RECENT_KEY) ?? "[]").filter((s) => typeof s === "string").slice(0, RECENT_MAX); } catch { return []; }
};
const saveRecent = (q) => {
  try { localStorage.setItem(RECENT_KEY, JSON.stringify([q, ...readRecent().filter((s) => s !== q)].slice(0, RECENT_MAX))); } catch { /* storage unavailable */ }
};
const editable = (el) => el && (el.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(el.tagName));

export function GlobalSearch() {
  const t = useT();
  const { user } = useAuth();
  const [open, setOpen] = useState(false);
  const allowed = can(user, "search.global");

  useEffect(() => {
    if (!allowed) return undefined;
    const onKey = (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") { e.preventDefault(); setOpen(true); }
      else if (e.key === "/" && !e.ctrlKey && !e.metaKey && !e.altKey && !editable(e.target)) { e.preventDefault(); setOpen(true); }
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [allowed]);

  if (!allowed) return null;
  return (
    <>
      <button type="button" className="search-trigger" id="search-trigger" onClick={() => setOpen(true)}
              aria-haspopup="dialog" aria-label={t("search.open")} title={t("search.shortcut")}>
        <Search size={18} aria-hidden="true" />
        <span className="search-trigger-text">{t("search.placeholderShort")}</span>
      </button>
      {open && <SearchPanel onClose={() => { setOpen(false); document.getElementById("search-trigger")?.focus(); }} />}
    </>
  );
}

function SearchPanel({ onClose }) {
  const t = useT();
  const navigate = useNavigate();
  const { home } = useHomeTabs();
  const [q, setQ] = useState("");
  const [results, setResults] = useState(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const [active, setActive] = useState(0);
  const [recent] = useState(readRecent);
  const input = useRef(null);
  const query = q.trim();

  useEffect(() => { input.current?.focus(); }, []);

  // Debounced search; an older answer never replaces a newer one.
  useEffect(() => {
    setActive(0);
    if (query.length < MIN_LENGTH) { setResults(null); setLoading(false); setFailed(false); return undefined; }
    const controller = new AbortController();
    setLoading(true);
    const timer = setTimeout(() => {
      searchAll(query, { signal: controller.signal })
        .then((data) => { setResults(data); setFailed(false); })
        .catch((err) => { if (err?.name !== "CanceledError" && err?.code !== "ERR_CANCELED") setFailed(true); })
        .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    }, DEBOUNCE_MS);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [query]);

  const go = useCallback((path) => { saveRecent(query); onClose(); navigate(path); }, [query, onClose, navigate]);

  // One flat list for the keyboard; rendered back in its groups.
  const items = useMemo(() => {
    if (query.length < MIN_LENGTH) {
      return recent.map((s) => ({ key: `recent-${s}`, group: "recent", icon: Clock, label: s, run: () => setQ(s) }));
    }
    if (!results) return [];
    return [
      ...results.mines.map((m) => ({ key: `mine-${m.id}`, group: "mines", icon: Factory, label: m.name,
        sub: `${m.code} · ${m.district}, ${m.state}${m.operator ? ` · ${m.operator}` : ""}`, run: () => go(`/gov/mines/${m.id}`) })),
      ...results.contractors.map((c) => ({ key: `contractor-${c.id}`, group: "contractors", icon: HardHat, label: c.name,
        sub: c.registration_no, run: () => go(`${home}?tab=contractors&contractor=${c.id}`) })),
      ...results.grievances.map((g) => ({ key: `grievance-${g.id}`, group: "grievances", icon: MessageSquareWarning, label: g.ticket_no,
        sub: [g.mine_name, t(`grievance.category.${g.category}`, { defaultValue: g.category })].filter(Boolean).join(" · "),
        run: () => go(`${home}?tab=grievances&grievance=${g.id}`) })),
      ...results.obligations.map((o) => ({ key: `obligation-${o.id}`, group: "obligations", icon: ClipboardCheck, label: o.code,
        sub: obligationTitle(o), run: () => go(`${home}?tab=obligations&obligation=${encodeURIComponent(o.code)}`) })),
    ];
  }, [query, results, recent, go, home, t]);

  const onKeyDown = (e) => {
    if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); onClose(); }
    else if (e.key === "ArrowDown" && items.length) { e.preventDefault(); setActive((i) => (i + 1) % items.length); }
    else if (e.key === "ArrowUp" && items.length) { e.preventDefault(); setActive((i) => (i - 1 + items.length) % items.length); }
    else if (e.key === "Enter" && items[active]) { e.preventDefault(); items[active].run(); }
  };
  useEffect(() => { document.getElementById(`search-opt-${active}`)?.scrollIntoView({ block: "nearest" }); }, [active]);

  const groups = ["recent", "mines", "contractors", "grievances", "obligations"]
    .map((g) => ({ g, rows: items.map((item, index) => ({ item, index })).filter(({ item }) => item.group === g) }))
    .filter(({ rows }) => rows.length);
  const empty = query.length >= MIN_LENGTH && results && !loading && !items.length;

  return createPortal(
    <div className="scrim is-modal search-scrim" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="search-panel" role="dialog" aria-modal="true" aria-label={t("search.title")} id="search-panel">
        <div className="search-field">
          <Search size={18} aria-hidden="true" />
          <input ref={input} type="search" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={onKeyDown}
                 placeholder={t("search.placeholder")} aria-label={t("search.placeholder")} autoComplete="off" spellCheck={false}
                 role="combobox" aria-expanded={items.length > 0} aria-controls="search-results" aria-autocomplete="list"
                 aria-activedescendant={items[active] ? `search-opt-${active}` : undefined} id="search-input" />
          <button type="button" className="search-close" onClick={onClose} aria-label={t("common.close")}><X size={18} aria-hidden="true" /></button>
        </div>
        <div className="search-body" id="search-results" role="listbox" aria-label={t("search.title")}>
          {groups.map(({ g, rows }) => (
            <div key={g} className="search-group" role="presentation">
              <div className="search-group-title" role="presentation">{t(`search.group.${g}`)}</div>
              {rows.map(({ item, index }) => {
                const Icon = item.icon;
                return (
                  <div key={item.key} id={`search-opt-${index}`} role="option" aria-selected={index === active}
                       className={`search-option${index === active ? " is-active" : ""}`} data-group={item.group}
                       onMouseMove={() => setActive(index)} onMouseDown={(e) => e.preventDefault()} onClick={item.run}>
                    <Icon size={18} aria-hidden="true" />
                    <span className="search-option-text">
                      <span className="search-option-label">{item.label}</span>
                      {item.sub && <span className="search-option-sub">{item.sub}</span>}
                    </span>
                  </div>
                );
              })}
            </div>
          ))}
          {query.length < MIN_LENGTH && !recent.length && <p className="search-hint">{t("search.hint")}</p>}
          {loading && !items.length && <p className="search-hint">{t("search.searching")}</p>}
          {empty && <p className="search-hint" id="search-empty">{t("search.noResults", { q: query })}</p>}
          {failed && <p className="search-hint">{t("search.failed")}</p>}
        </div>
        <div className="search-foot" aria-hidden="true">{t("search.keys")}</div>
      </div>
    </div>,
    document.body,
  );
}
