// Profile, for every role (Phase 6): who you are, what the API lets you see, and the language of
// the interface. Choosing a language saves it to the account (PATCH /v1/users/me) and switches at
// once; it follows the account to any browser.
import { useState } from "react";
import { ErrorNotice } from "../components/common/ErrorNotice";
import { Masthead } from "../components/layout/Masthead";
import { useToast } from "../components/overlay/ToastHost";
import { useAuth } from "../hooks/useAuth";
import { LANGUAGES } from "../i18n";
import { useT } from "../i18n/t";

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
      <Masthead title={t("profile.title")} subtitle={user?.email} />
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
            {user?.preferred_language !== "en" && <p className="note" id="translation-draft">{t("profile.draftNote")}</p>}
          </div>
        </section>
      </div>
    </>
  );
}
