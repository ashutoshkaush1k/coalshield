// A page's title row, at the top of its content (the header, drawer and footer belong to AppShell):
// title and subtitle on the left, the page's own actions (e.g. "Back to overview") on the right.
export function PageTitle({ title, subtitle, children }) {
  return (
    <div className="page-title">
      <div className="page-title-text">
        <h1>{title}</h1>
        {subtitle && <div className="sub">{subtitle}</div>}
      </div>
      {children && <div className="page-title-actions">{children}</div>}
    </div>
  );
}
