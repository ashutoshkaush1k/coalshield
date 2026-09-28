// The small language switcher of the signed-out pages (login, raise and track a grievance). The
// choice is kept in this browser until someone signs in; after that the account's saved language
// wins (Profile page). Languages are listed in their own script.
import { useTranslation } from "react-i18next";
import { LANGUAGES, setLanguage } from "../../i18n";

export function LanguageSwitcher({ id = "language-switcher" }) {
  const { t, i18n } = useTranslation();
  return (
    <div className="lang-switch">
      <label htmlFor={id} className="sr-only">{t("language.label")}</label>
      <select id={id} value={i18n.language} onChange={(e) => setLanguage(e.target.value, { remember: true })}
              aria-label={t("language.label")}>
        {LANGUAGES.map((l) => <option key={l.code} value={l.code} lang={l.code}>{l.native}</option>)}
      </select>
    </div>
  );
}
