// "Continue as admin (demo)": the login page's button and its "Demo access" popup, the popup shown
// when a demo session has run out, and the top bar's "Demo access" badge. The API decides whether
// the demo login exists (GET /v1/auth/demo, DEMO_LOGIN_ENABLED) - no rebuild is needed to switch it
// off - and no password is ever sent from here.
import { useEffect, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { demoAvailable } from "../../api/auth";
import { homeFor } from "../../auth/roles";
import { useAuth } from "../../hooks/useAuth";
import { useT } from "../../i18n/t";
import { ErrorNotice } from "../common/ErrorNotice";
import { Modal } from "../overlay/Overlay";

/** After this long without an answer the free server is waking up: say so instead of waiting silently. */
const WAKING_AFTER_MS = 2500;

/** Signs in the demo account; `waking` turns true while the server is slow to answer. */
function useDemoSignIn(onDone) {
  const { signInDemo } = useAuth();
  const [busy, setBusy] = useState(false);
  const [waking, setWaking] = useState(false);
  const [error, setError] = useState(null);
  const timer = useRef(null);
  useEffect(() => () => window.clearTimeout(timer.current), []);
  async function start() {
    setBusy(true);
    setError(null);
    timer.current = window.setTimeout(() => setWaking(true), WAKING_AFTER_MS);
    try {
      onDone(await signInDemo());
    } catch (err) {
      setError(err);
    } finally {
      window.clearTimeout(timer.current);
      setBusy(false);
      setWaking(false);
    }
  }
  return { start, busy, waking, error };
}

function Waking({ show }) {
  const t = useT();
  if (!show) return null;
  return (
    <p className="demo-waking" role="status">
      <span className="wake-spinner" aria-hidden="true" />{t("demo.waking")}
    </p>
  );
}

/** Login page: "Continue as admin (demo)" under Sign in, when the API offers it. */
export function DemoLoginButton() {
  const t = useT();
  const navigate = useNavigate();
  const [available, setAvailable] = useState(false);
  const [open, setOpen] = useState(false);
  const { start, busy, waking, error } = useDemoSignIn((user) => navigate(homeFor(user), { replace: true }));

  useEffect(() => {
    let alive = true;
    demoAvailable().then((ok) => { if (alive) setAvailable(ok); });
    return () => { alive = false; };
  }, []);

  if (!available) return null;
  return (
    <>
      <div className="demo-login">
        <span className="demo-login-or" aria-hidden="true">{t("demo.or")}</span>
        <button type="button" className="demo-login-btn" id="demo-login" onClick={() => setOpen(true)}>
          {t("demo.button")}
        </button>
      </div>
      <Modal
        open={open}
        onClose={() => { if (!busy) setOpen(false); }}
        title={t("demo.title")}
        footer={
          <>
            <button type="button" onClick={() => setOpen(false)} disabled={busy}>{t("common.cancel")}</button>
            <button type="button" className="primary" id="demo-continue" onClick={start} disabled={busy} autoFocus>
              {busy ? t("demo.opening") : t("demo.continue")}
            </button>
          </>
        }
      >
        <div className="stack tight">
          <p className="demo-text">{t("demo.text")}</p>
          <Waking show={waking} />
          <ErrorNotice error={error} />
        </div>
      </Modal>
    </>
  );
}

/** Anywhere: a demo session has run out (30 minutes) - offer a new one instead of an error. */
export function DemoEndedModal() {
  const t = useT();
  const navigate = useNavigate();
  const location = useLocation();
  const { demoEnded, signOut } = useAuth();
  const { start, busy, waking, error } = useDemoSignIn((user) => {
    if (location.pathname === "/login") navigate(homeFor(user), { replace: true });
  });
  const toSignIn = () => { signOut(); navigate("/login", { replace: true }); };
  return (
    <Modal
      open={demoEnded}
      onClose={() => { if (!busy) toSignIn(); }}
      title={t("demo.endedTitle")}
      footer={
        <>
          <button type="button" onClick={toSignIn} disabled={busy}>
            {t("demo.toSignIn")}
          </button>
          <button type="button" className="primary" id="demo-restart" onClick={start} disabled={busy} autoFocus>
            {busy ? t("demo.opening") : t("demo.button")}
          </button>
        </>
      }
    >
      <div className="stack tight">
        <p className="demo-text">{t("demo.endedText")}</p>
        <Waking show={waking} />
        <ErrorNotice error={error} />
      </div>
    </Modal>
  );
}

/** Top bar: "Demo access" while signed in as the demo account. */
export function DemoBadge() {
  const t = useT();
  const { user } = useAuth();
  if (!user?.is_demo) return null;
  return <span className="demo-badge" id="demo-badge" title={t("demo.badgeHint")}>{t("demo.badge")}</span>;
}
