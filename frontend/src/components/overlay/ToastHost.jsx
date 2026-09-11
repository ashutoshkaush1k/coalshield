// Small acknowledgement for actions that would otherwise complete silently.
import { createContext, useCallback, useContext, useMemo, useState } from "react";

const ToastContext = createContext(null);
const DEFAULT_MS = 6000;

export function ToastHost({ children }) {
  const [toasts, setToasts] = useState([]);

  const dismiss = useCallback((id) => {
    setToasts((current) => current.filter((t) => t.id !== id));
  }, []);

  const notify = useCallback(
    ({ title, body, tone = "ok", ms = DEFAULT_MS }) => {
      const id = Date.now() + Math.random();
      setToasts((current) => [...current, { id, title, body, tone }]);
      // Long enough to read a generated directive message, short enough not to linger.
      setTimeout(() => dismiss(id), ms);
      return id;
    },
    [dismiss],
  );

  const value = useMemo(() => ({ notify, dismiss }), [notify, dismiss]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="toast-stack" role="status" aria-live="polite">
        {toasts.map((toast) => (
          <div key={toast.id} className={`toast${toast.tone === "error" ? " is-error" : ""}`}>
            <span className="toast-dot" />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div className="toast-title">{toast.title}</div>
              {toast.body && <div className="toast-body">{toast.body}</div>}
            </div>
            <button className="overlay-close" type="button"
                    onClick={() => dismiss(toast.id)} aria-label="Dismiss">
              &times;
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error("useToast must be used inside <ToastHost>");
  return ctx;
}
