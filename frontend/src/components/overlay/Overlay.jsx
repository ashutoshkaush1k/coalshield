// Drawer and Modal share one scrim, one escape/outside-click contract, one lock.
//
// Drawer: slides from the right, for browsing detail without losing the page behind it.
// Modal:  centres, for a single focused action.
import { useEffect } from "react";
import { createPortal } from "react-dom";

function useOverlay(open, onClose) {
  useEffect(() => {
    if (!open) return undefined;

    const onKey = (e) => e.key === "Escape" && onClose?.();
    document.addEventListener("keydown", onKey);

    // Stop the page behind from scrolling under the scrim, and restore whatever the
    // document already had rather than assuming it was scrollable.
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = previous;
    };
  }, [open, onClose]);
}

function Shell({ open, onClose, variant, className = "", children }) {
  useOverlay(open, onClose);
  if (!open) return null;

  return createPortal(
    <div
      className={`scrim${variant === "modal" ? " is-modal" : ""}`}
      // Only a click that starts and ends on the scrim closes it, so dragging a text
      // selection out of the panel does not dismiss the thing you were reading.
      onMouseDown={(e) => { if (e.target === e.currentTarget) onClose?.(); }}
    >
      <div
        className={`${variant === "modal" ? "modal" : "drawer"} ${className}`}
        role="dialog"
        aria-modal="true"
      >
        {children}
      </div>
    </div>,
    document.body,
  );
}

function Head({ title, subtitle, onClose, action }) {
  return (
    <header className="overlay-head">
      <div style={{ flex: 1, minWidth: 0 }}>
        <h2>{title}</h2>
        {subtitle && <div className="hint">{subtitle}</div>}
      </div>
      {action}
      <button className="overlay-close" type="button" onClick={onClose} aria-label="Close">
        &times;
      </button>
    </header>
  );
}

export function Drawer({ open, onClose, title, subtitle, action, flush = false, children, footer }) {
  return (
    <Shell open={open} onClose={onClose} variant="drawer">
      <Head title={title} subtitle={subtitle} onClose={onClose} action={action} />
      <div className={`overlay-body${flush ? " flush" : ""}`}>{children}</div>
      {footer && <div className="overlay-foot">{footer}</div>}
    </Shell>
  );
}

export function Modal({ open, onClose, title, subtitle, children, footer, wide = false }) {
  return (
    <Shell open={open} onClose={onClose} variant="modal" className={wide ? "is-wide" : ""}>
      <Head title={title} subtitle={subtitle} onClose={onClose} />
      <div className="overlay-body">{children}</div>
      {footer && <div className="overlay-foot">{footer}</div>}
    </Shell>
  );
}
