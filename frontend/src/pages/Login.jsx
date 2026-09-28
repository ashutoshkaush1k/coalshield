// Single login page with the two account types described in PRD Section 3.
import { useState } from "react";
import { useLocation, useNavigate, Link } from "react-router-dom";
import { Card } from "../components/common/Card";
import { ErrorNotice } from "../components/common/ErrorNotice";
import { DemoFooter } from "../components/common/DemoFooter";
import { useAuth } from "../hooks/useAuth";
import { homeFor } from "../auth/roles";
import { useT } from "../i18n/t";
import { LanguageSwitcher } from "../components/common/LanguageSwitcher";

// One form for both roles: the account decides the scope, not the login screen. Quick-fill
// buttons exist so nobody types a password on stage.
// Mine names from data/reference/mines_real.csv (the five demo mines keep their codes).
const DEMO = [
  { key: "government", email: "gov@dgms.gov.in" },
  { key: "corporate", email: "corporate.secl@coalmine.in" },
  { key: "headHigh", email: "head.od-tlc-05@coalmine.in" },
  { key: "headLow", email: "head.jh-dhn-01@coalmine.in" },
];

export default function Login() {
  const [email, setEmail] = useState("gov@dgms.gov.in");
  const [password, setPassword] = useState("demo123");
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const { signIn } = useAuth();
  const t = useT();
  const navigate = useNavigate();
  const location = useLocation();

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const user = await signIn(email, password);
      navigate(location.state?.from?.pathname || homeFor(user), { replace: true });
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="login-wrap">
      <div className="login-card stack">
        <LanguageSwitcher id="login-language" />
        <div className="brandline">
          <h1>Smart Mine Governance</h1>
          <span>{t("login.tagline")}</span>
        </div>

        <Card>
          <form onSubmit={submit} className="stack">
            <div>
              <label htmlFor="email">{t("login.email")}</label>
              <input id="email" type="email" value={email} autoComplete="username"
                     onChange={(e) => setEmail(e.target.value)} required />
            </div>
            <div>
              <label htmlFor="password">{t("login.password")}</label>
              <input id="password" type="password" value={password} autoComplete="current-password"
                     onChange={(e) => setPassword(e.target.value)} required />
            </div>

            <ErrorNotice error={error} context={t("login.checkCredentials")} />

            <button className="primary" type="submit" disabled={busy}>
              {busy ? t("login.signingIn") : t("login.signIn")}
            </button>
          </form>

          <div className="demo-accounts">
            <span className="label">{t("login.demoAccounts", { password: "demo123" })}</span>
            {DEMO.map((account) => (
              <button key={account.email} type="button"
                      onClick={() => { setEmail(account.email); setPassword("demo123"); }}>
                {t(`login.demo.${account.key}`)}
              </button>
            ))}
          </div>
        </Card>
        <div className="public-links stack tight" id="grievance-links">
          <span className="faint small">{t("login.grievanceHint")}</span>
          <div className="row wrap-row">
            <Link className="btn" to="/grievance">{t("login.raiseGrievance")}</Link>
            <Link className="btn" to="/grievance/track">{t("login.trackGrievance")}</Link>
          </div>
        </div>
        <DemoFooter />
      </div>
    </div>
  );
}
