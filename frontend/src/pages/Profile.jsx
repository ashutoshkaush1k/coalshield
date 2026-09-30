// Profile, for every role (Phase 6): who you are, what the API lets you see, and the language of
// the interface. Choosing a language saves it to the account (PATCH /v1/users/me) and switches at
// once; it follows the account to any browser. Change password: POST /v1/users/me/password.
import { useState } from "react";
import { changePassword } from "../api/auth";
import { ErrorNotice } from "../components/common/ErrorNotice";
import { PasswordInput } from "../components/common/PasswordInput";
import { PageTitle } from "../components/layout/PageTitle";
import { useToast } from "../components/overlay/ToastHost";
import { useAuth } from "../hooks/useAuth";
import { LANGUAGES } from "../i18n";
import { useT } from "../i18n/t";

// The API's minimum (auth.passwordMinLength); checked here too for an instant message.
const PASSWORD_MIN = 12;

function ChangePassword({ email }) {
  const t = useT();
  const { notify } = useToast();
  const [form, setForm] = useState({ current: "", next: "", repeat: "" });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);     // from the API
  const [problem, setProblem] = useState(null); // checked here, before sending
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  async function submit(e) {
    e.preventDefault();
    setError(null);
    setProblem(null);
    if (form.next.length < PASSWORD_MIN) return setProblem(t("profile.password.tooShort", { min: PASSWORD_MIN }));
    if (form.next !== form.repeat) return setProblem(t("profile.password.mismatch"));
    setBusy(true);
    try {
      await changePassword(form.current, form.next);
      setForm({ current: "", next: "", repeat: "" });
      notify({ title: t("profile.password.saved") });
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="panel-block" id="profile-password">
      <div className="panel-head">
        <div>
          <h2>{t("profile.password.title")}</h2>
          <span className="hint">{t("profile.password.hint", { min: PASSWORD_MIN })}</span>
        </div>
      </div>
      <div className="panel-body">
        <form className="stack tight password-form" onSubmit={submit}>
          {/* Lets password managers file the new password under this account. */}
          <input type="text" name="username" autoComplete="username" value={email ?? ""} hidden readOnly />
          <div>
            <label htmlFor="current-password">{t("profile.password.current")}</label>
            <PasswordInput id="current-password" name="current-password" autoComplete="current-password"
                           value={form.current} onChange={set("current")} required disabled={busy} />
          </div>
          <div>
            <label htmlFor="new-password">{t("profile.password.new")}</label>
            <PasswordInput id="new-password" name="new-password" autoComplete="new-password" minLength={PASSWORD_MIN}
                           value={form.next} onChange={set("next")} required disabled={busy} />
          </div>
          <div>
            <label htmlFor="repeat-password">{t("profile.password.confirm")}</label>
            <PasswordInput id="repeat-password" name="repeat-password" autoComplete="new-password" minLength={PASSWORD_MIN}
                           value={form.repeat} onChange={set("repeat")} required disabled={busy} />
          </div>
          {problem && <div className="notice error" role="alert">{problem}</div>}
          <ErrorNotice error={error} />
          <div>
            <button className="primary" type="submit" disabled={busy}>
              {busy ? t("profile.password.saving") : t("profile.password.submit")}
            </button>
          </div>
        </form>
      </div>
    </section>
  );
}

export default function Profile() {
  const t = useT();
  const { user, changeLanguage } = useAuth();
  const { notify } = useToast();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function choose(code) {
    if (code === user?.preferred_language) return;
    setBusy(true);
    setError(null);
    try {
      await changeLanguage(code);
      notify({ title: t("profile.saved") });
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  const company = user?.subsidiary_code
    ? [user.subsidiary_code, user.subsidiary_name].filter(Boolean).join(" - ")
    : null;

  return (
    <>
      <PageTitle title={t("profile.title")} subtitle={user?.email} />
      <div className="content stack">
        <section className="panel-block" id="profile-account">
          <div className="panel-head"><h2>{t("profile.account")}</h2></div>
          <div className="panel-body">
            <dl className="detail-grid">
              <dt>{t("profile.name")}</dt><dd>{user?.full_name}</dd>
              <dt>{t("profile.email")}</dt><dd className="mono">{user?.email}</dd>
              <dt>{t("profile.role")}</dt><dd>{t(`roles.${user?.role}`, { defaultValue: user?.role })}</dd>
              <dt>{t("profile.company")}</dt><dd>{company ?? t("profile.allCompanies")}</dd>
              <dt>{t("profile.area")}</dt><dd>{user?.area_name ?? t("profile.none")}</dd>
              <dt>{t("profile.mine")}</dt><dd>{user?.mine_name ? `${user.mine_name}${user.mine_code ? ` (${user.mine_code})` : ""}` : t("profile.none")}</dd>
            </dl>
          </div>
        </section>

        <section className="panel-block" id="profile-language">
          <div className="panel-head">
            <div>
              <h2>{t("profile.language")}</h2>
              <span className="hint">{t("profile.languageHint")}</span>
            </div>
          </div>
          <div className="panel-body stack tight">
            <ErrorNotice error={error} />
            <div className="lang-grid" role="radiogroup" aria-label={t("profile.language")}>
              {LANGUAGES.map((l) => (
                <label key={l.code} className={`lang-option${user?.preferred_language === l.code ? " is-active" : ""}`}>
                  <input type="radio" name="language" value={l.code} disabled={busy}
                         checked={user?.preferred_language === l.code} onChange={() => choose(l.code)} />
                  <span lang={l.code} className="lang-native">{l.native}</span>
                  <span className="faint small">{t(`language.name.${l.code}`)}</span>
                </label>
              ))}
            </div>
          </div>
        </section>

        <ChangePassword email={user?.email} />
      </div>
    </>
  );
}
