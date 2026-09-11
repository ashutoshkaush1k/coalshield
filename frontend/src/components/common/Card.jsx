// Flat panel block: solid 2px edge, tonal header band, no shadow and no rounding.
export function Card({ title, subtitle, action, children, flush = false, className = "" }) {
  return (
    <section className={`panel-block ${className}`}>
      {(title || action) && (
        <header className="panel-head">
          <div>
            <h2>{title}</h2>
            {subtitle && <div className="hint">{subtitle}</div>}
          </div>
          <div className="spacer" />
          {action}
        </header>
      )}
      <div className={`panel-body${flush ? " flush" : ""}`}>{children}</div>
    </section>
  );
}
