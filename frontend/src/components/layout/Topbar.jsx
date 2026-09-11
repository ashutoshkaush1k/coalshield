// Masthead without tabs, for pages that are not tabbed (the drill-down).
export function Topbar({ title, subtitle, children }) {
  return (
    <header className="masthead">
      <div className="masthead-title">
        <h1>{title}</h1>
        {subtitle && <div className="sub">{subtitle}</div>}
      </div>
      <div className="spacer" />
      {/* Only rendered when there is something to put here, so the flex gap does not
          leave a hole on the right of the bar. */}
      {children && <div className="masthead-meta">{children}</div>}
    </header>
  );
}
