// Contractors worst first (the API orders them): compliance badge, what drives it, and the
// figures behind it. Flagged rows are marked so the worst contractor is impossible to miss.
import { fmtNumber } from "../../utils/format";
import { contractorReason } from "../../i18n/contractors";
import { useT } from "../../i18n/t";
import { EmptyState } from "../common/EmptyState";
import { ComplianceBadge } from "./ComplianceBadge";

export function ContractorList({ contractors, onSelect }) {
  const t = useT();
  if (!contractors?.length) return <EmptyState>{t("contractor.empty")}</EmptyState>;

  return (
    <div className="scroll-x">
      <table className="contractor-table">
        <thead>
          <tr>
            <th>{t("contractor.name")}</th>
            <th>{t("contractor.compliance")}</th>
            <th>{t("contractor.why")}</th>
            <th className="num">{t("contractor.workers")}</th>
            <th className="num">{t("contractor.violationsPerWorker")}</th>
            <th className="num">{t("contractor.missingDocs")}</th>
          </tr>
        </thead>
        <tbody>
          {contractors.map((c) => {
            const m = c.compliance;
            return (
              <tr key={c.id} className={`clickable${m.band === "flagged" ? " is-flagged" : ""}`} onClick={() => onSelect?.(c)}>
                <td>
                  <strong>{c.name}</strong>
                  {c.status !== "active" && <span className="tag tag-open" style={{ marginLeft: 6 }}>{t(`contractor.status.${c.status}`)}</span>}
                  <div className="faint small mono">{c.registration_no} &middot; {t("contractor.contractsCount", { count: m.contracts })}</div>
                </td>
                <td><ComplianceBadge compliance={m} /></td>
                <td className="small">{m.reasons.slice(0, 2).map((r) => contractorReason(r)).join(" · ") || t("contractor.noIssues")}</td>
                <td className="num">{fmtNumber(m.active_workers)}</td>
                <td className="num">{fmtNumber(m.violations_per_worker)}</td>
                <td className="num">{fmtNumber(m.missing_documents.length)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
