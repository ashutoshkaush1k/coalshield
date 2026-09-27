// Grievances as a table: ticket, (mine), category, language, status with overdue / escalated /
// sensitive markers, response due, assignee. A row opens the detail drawer.
import { useT } from "../../i18n/t";
import { EmptyState } from "../common/EmptyState";
import { GrievanceFlags, GrievanceStatus, LanguageTag, fmtWhen } from "./common";

export function GrievanceList({ grievances, showMine = false, onSelect, empty }) {
  const t = useT();
  if (!grievances?.length) return <EmptyState>{empty ?? t("grievance.queue.empty")}</EmptyState>;
  return (
    <div className="scroll-x">
      <table className="grievance-table">
        <thead>
          <tr>
            <th>{t("grievance.list.ticket")}</th>
            {showMine && <th>{t("grievance.list.mine")}</th>}
            <th>{t("grievance.list.category")}</th>
            <th>{t("grievance.list.language")}</th>
            <th>{t("grievance.list.status")}</th>
            <th>{t("grievance.list.due")}</th>
            <th>{t("grievance.list.assignee")}</th>
          </tr>
        </thead>
        <tbody>
          {grievances.map((g) => (
            <tr key={g.id} data-grievance={g.ticket_no} className={g.is_overdue || g.escalation_level > 0 ? "is-flagged" : ""}
                onClick={() => onSelect?.(g)} style={{ cursor: onSelect ? "pointer" : undefined }}>
              <td className="mono"><strong>{g.ticket_no}</strong><div className="faint small">{fmtWhen(g.created_at)}</div></td>
              {showMine && <td>{g.mine_name} <span className="mono faint">{g.mine_code}</span></td>}
              <td>{t(`grievance.category.${g.category}`)}</td>
              <td><LanguageTag language={g.language} /></td>
              <td><div className="row wrap-row"><GrievanceStatus status={g.status} /><GrievanceFlags g={g} /></div></td>
              <td className="mono">{fmtWhen(g.sla_due_at)}</td>
              <td className="small">{g.assignee_name ?? "-"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
