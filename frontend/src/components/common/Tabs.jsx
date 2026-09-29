// Tab strip for the masthead. Underlined-on-active, never pills.
//
// Tab state is local to the page, not a route: switching a tab must not reload or
// re-enter the router, and the routes themselves are unchanged.
//
// Every tab has an icon (lucide-react, MIT) beside its label; below 768 px the bar shows the icon
// only, with the label as its tooltip and accessible name.
import {
  Activity, ClipboardCheck, Factory, HardHat, LayoutDashboard, ListOrdered, Map, MessageSquareWarning, TrendingUp,
} from "lucide-react";

const ICONS = {
  overview: LayoutDashboard, priority: ListOrdered, sensors: Activity, trends: TrendingUp, production: Factory,
  contractors: HardHat, grievances: MessageSquareWarning, obligations: ClipboardCheck, map: Map,
};

export function TabIcon({ id, size = 18 }) {
  const Icon = ICONS[id];
  return Icon ? <Icon size={size} aria-hidden="true" className="tab-icon" /> : null;
}

/** `controls`: the panels are on this page (the home page), so each tab names the one it shows. */
export function Tabs({ tabs, active, onChange, controls = true }) {
  return (
    <div className="tabs" role="tablist">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          role="tab"
          type="button"
          className="tab"
          title={tab.label}
          aria-label={tab.label}
          aria-selected={active === tab.id}
          aria-controls={controls ? `panel-${tab.id}` : undefined}
          data-tab={tab.id}
          onClick={() => onChange(tab.id)}
        >
          <TabIcon id={tab.id} />
          <span className="tab-text">{tab.label}</span>
        </button>
      ))}
    </div>
  );
}

export function TabPanel({ id, active, children }) {
  if (active !== id) return null;
  // Keyed so React remounts on switch and the entry animation replays.
  return (
    <div className="panel" id={`panel-${id}`} role="tabpanel" key={id}>
      {children}
    </div>
  );
}
