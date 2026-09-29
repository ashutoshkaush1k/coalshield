// One footer on every page (the signed-in shell, login and the public grievance pages): help and
// support (helpline, email, hours), how to raise or track a grievance, what to do in an emergency,
// and the copyright line. Signed in, it also warns when the automated detection is running on the
// PHP fallback (Phase 8), polled every 30 s.
//
// The helpline and the support email come from VITE_HELPLINE and VITE_SUPPORT_EMAIL (frontend/.env).
// Until a number is published the helpline shows masked (XXXXX XXXXX) and screen readers hear that
// it is to be announced; the default email is a placeholder on the reserved .example domain.
import { Link } from "react-router-dom";
import { getSystemStatus } from "../../api/system";
import { useAuth } from "../../hooks/useAuth";
import { usePolling } from "../../hooks/usePolling";
import { useT } from "../../i18n/t";
import { APP_NAME, Logo } from "../layout/Nav";

const MASKED_HELPLINE = "XXXXX XXXXX";
const HELPLINE = import.meta.env.VITE_HELPLINE || MASKED_HELPLINE;
const EMAIL = import.meta.env.VITE_SUPPORT_EMAIL || "helpdesk@smartmine.example";

function FallbackNotice() {
  const t = useT();
  const { data } = usePolling(getSystemStatus, { interval: 30000 });
  if (!data || data.detection_engine !== "php") return null;
  return (
    <div className="system-warning" role="status" id="detection-fallback">
      <strong>{t("system.fallbackTitle")}</strong>{" "}
      {data.reason === "CONFIGURED_PHP" ? t("system.fallbackConfigured") : t("system.fallbackDown")}
    </div>
  );
}

export function SiteFooter() {
  const t = useT();
  const { user } = useAuth();
  const masked = HELPLINE === MASKED_HELPLINE;
  return (
    <footer className="site-footer" id="site-footer">
      {user && <FallbackNotice />}
      <div className="footer-grid">
        <div className="footer-brand">
          <div className="footer-name"><Logo /><strong>{APP_NAME}</strong></div>
          <p>{t("login.tagline")}</p>
        </div>

        <section id="site-help" aria-labelledby="footer-help-title">
          <h2 className="footer-title" id="footer-help-title">{t("footer.help")}</h2>
          <dl className="footer-facts">
            <dt>{t("footer.helpline")}</dt>
            <dd>
              {masked
                ? <><span aria-hidden="true" className="footer-masked">{HELPLINE}</span><span className="sr-only">{t("footer.helplineSoon")}</span></>
                : <a href={`tel:${HELPLINE.replace(/[^\d+]/g, "")}`}>{HELPLINE}</a>}
            </dd>
            <dt>{t("footer.email")}</dt>
            {/* A break opportunity after the @, so a narrow column wraps the address there. */}
            <dd><a href={`mailto:${EMAIL}`}>{EMAIL.split("@")[0]}@<wbr />{EMAIL.split("@")[1]}</a></dd>
            <dt>{t("footer.hoursLabel")}</dt>
            <dd>{t("footer.hours")}</dd>
          </dl>
        </section>

        <section aria-labelledby="footer-grievance-title">
          <h2 className="footer-title" id="footer-grievance-title">{t("footer.grievances")}</h2>
          <p>{t("login.grievanceHint")}</p>
          <ul className="footer-links">
            <li><Link to="/grievance">{t("login.raiseGrievance")}</Link></li>
            <li><Link to="/grievance/track">{t("login.trackGrievance")}</Link></li>
          </ul>
        </section>

        <section aria-labelledby="footer-safety-title">
          <h2 className="footer-title" id="footer-safety-title">{t("footer.safety")}</h2>
          <p>{t("footer.emergency")}</p>
        </section>
      </div>
      <div className="footer-bottom">© {new Date().getFullYear()} {APP_NAME}</div>
    </footer>
  );
}
