// Small shared pieces of the grievance screens.
import { useT } from "../../i18n/t";

const STATUS_TAG = {
  received: "tag-directive", acknowledged: "tag-ack", under_investigation: "tag-directive",
  resolved: "tag-resolved", closed: "tag-resolved", reopened: "tag-open",
};

export function GrievanceStatus({ status }) {
  const t = useT();
  return <span className={`tag ${STATUS_TAG[status] ?? ""}`}>{t(`status.${status}`)}</span>;
}

/** The language a grievance was written in - its text is always shown as submitted. */
export function LanguageTag({ language }) {
  const t = useT();
  return <span className="tag lang-tag" title={t("grievance.languageTag", { language: t(`grievance.language.${language}`) })}>{t(`grievance.language.${language}`)}</span>;
}

/** Overdue / escalated / sensitive markers for a grievance row. */
export function GrievanceFlags({ g }) {
  const t = useT();
  return (
    <>
      {g.is_overdue && <span className="tag tag-open">{t("grievance.queue.overdue")}</span>}
      {g.escalation_level > 0 && <span className="tag tag-open">{t("grievance.queue.escalated", { level: g.escalation_level })}</span>}
      {g.is_sensitive && <span className="tag tag-directive">{t("grievance.list.filterSensitive")}</span>}
    </>
  );
}

export const fmtWhen = (iso) =>
  iso ? new Date(iso).toLocaleString("en-IN", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "-";
