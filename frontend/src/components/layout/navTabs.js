// The one list of tabs per role, for the shared app shell (AppShell: top bar and drawer) and the
// home pages that render the panels. The active tab lives in the home page's URL (?tab=), so a tab
// chosen on any signed-in page - the profile, a mine's detail - opens that tab on the user's home,
// and the highlight follows it. Switching tabs on the home page changes only the query string: the
// page is not remounted and nothing refetches.
import { useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { homeFor, isMineHead } from "../../auth/roles";
import { useAuth } from "../../hooks/useAuth";
import { t } from "../../i18n/t";

const LABEL_KEYS = {
  overview: "tabs.overview", ranking: "tabs.ranking", sensors: "tabs.sensors", trends: "tabs.trends",
  production: "production.tabLabel", contractors: "contractor.tabLabel", grievances: "grievance.tabLabel",
  obligations: "obligation.tabLabel", map: "map.tabLabel",
};

// Five on the bar; the rest in the drawer, in this order. Mine head never gets Risk Ranking:
// cross-mine ranking is authority-only (the API answers 403 for that role).
const MULTI_MINE = {
  tabs: ["overview", "ranking", "sensors", "trends", "production", "contractors", "grievances", "obligations", "map"],
  bar: ["overview", "ranking", "obligations", "production", "map"],
};
// Old tab values that still open their tab: the Priority Queue became Risk Ranking.
const ALIASES = { priority: "ranking" };
const MINE_HEAD = {
  tabs: ["overview", "sensors", "trends", "production", "contractors", "grievances", "obligations", "map"],
  bar: ["overview", "production", "obligations", "contractors", "grievances"],
};

/** The signed-in user's tabs (labels in the UI language), which of them are on the bar, and the active one. */
export function useHomeTabs() {
  const { user } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const set = isMineHead(user) ? MINE_HEAD : MULTI_MINE;
  const home = homeFor(user);
  const tabs = set.tabs.map((id) => ({ id, label: t(LABEL_KEYS[id]) }));
  const onHome = location.pathname === home;
  const asked = ALIASES[params.get("tab")] ?? params.get("tab");
  const active = onHome ? (set.tabs.includes(asked) ? asked : "overview") : null;
  const goTo = (id) => navigate(id === "overview" ? home : `${home}?tab=${id}`);
  return {
    home, onHome, active, goTo,
    barTabs: set.bar.map((id) => tabs.find((tab) => tab.id === id)),
    drawerTabs: tabs.filter((tab) => !set.bar.includes(tab.id)),
  };
}
