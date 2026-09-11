// Tab strip for the masthead. Underlined-on-active, never pills.
//
// Tab state is local to the page, not a route: switching a tab must not reload or
// re-enter the router, and the routes themselves are unchanged.
export function Tabs({ tabs, active, onChange }) {
  return (
    <div className="tabs" role="tablist">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          role="tab"
          type="button"
          className="tab"
          aria-selected={active === tab.id}
          aria-controls={`panel-${tab.id}`}
          onClick={() => onChange(tab.id)}
        >
          {tab.label}
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
