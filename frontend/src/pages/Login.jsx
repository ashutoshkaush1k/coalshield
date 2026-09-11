// Single login page with the two account types described in PRD Section 3.
import { useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { Card } from "../components/common/Card";
import { ErrorNotice } from "../components/common/ErrorNotice";
import { useAuth } from "../hooks/useAuth";
import { homeFor } from "../auth/roles";

// One form for both roles: the account decides the scope, not the login screen. Quick-fill
// buttons exist so nobody types a password on stage.
const DEMO = [
  { label: "Government - all mines", email: "gov@dgms.gov.in" },
  { label: "Mine Head - Talcher (high risk)", email: "head.od-tlc-05@coalmine.in" },
  { label: "Mine Head - Jharia (low risk)", email: "head.jh-dhn-01@coalmine.in" },
];

export default function Login() {
  const [email, setEmail] = useState("gov@dgms.gov.in");
  const [password, setPassword] = useState("demo123");
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const { signIn } = useAuth();
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
        <div className="brandline">
          <h1>Smart Mine Governance</h1>
          <span>AI compliance monitoring for coal mines</span>
        </div>

        <Card>
          <form onSubmit={submit} className="stack">
            <div>
              <label htmlFor="email">Email</label>
              <input id="email" type="email" value={email} autoComplete="username"
                     onChange={(e) => setEmail(e.target.value)} required />
            </div>
            <div>
              <label htmlFor="password">Password</label>
              <input id="password" type="password" value={password} autoComplete="current-password"
                     onChange={(e) => setPassword(e.target.value)} required />
            </div>

            <ErrorNotice error={error} context="Check the email and password." />

            <button className="primary" type="submit" disabled={busy}>
              {busy ? "Signing in..." : "Sign in"}
            </button>
          </form>

          <div className="demo-accounts">
            <span className="label">Demo accounts, password demo123</span>
            {DEMO.map((account) => (
              <button key={account.email} type="button"
                      onClick={() => { setEmail(account.email); setPassword("demo123"); }}>
                {account.label}
              </button>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}
