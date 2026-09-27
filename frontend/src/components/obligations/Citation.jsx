// An obligation's citation: act and section / rule always visible; the verbatim quote on hover
// (title) and on expand, with the source file and page. Unverified obligations say so.
import { useT } from "../../i18n/t";

export function Citation({ obligation, compact = false }) {
  const t = useT();
  const c = obligation?.citation;
  if (!c) return null;
  const head = [c.instrument, c.clause].filter(Boolean).join(", ");
  return (
    <span className="citation" title={c.quote ?? ""}>
      <span className="citation-head">{head || "-"}</span>
      {!c.verified && <span className="tag tag-open">{t("obligation.citation.unverified")}</span>}
      {!compact && c.quote && (
        <details className="citation-quote">
          <summary>{t("obligation.citation.showQuote")}</summary>
          <blockquote>“{c.quote}”</blockquote>
          {c.source_file && <span className="faint small">{t("obligation.citation.source", { file: c.source_file, page: c.page ?? "-" })}</span>}
        </details>
      )}
    </span>
  );
}
