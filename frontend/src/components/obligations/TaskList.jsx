// Obligation tasks as a table: obligation (code, title, citation), (mine), period, due time and
// its basis, status and escalation, the latest evidence. A row opens the task drawer.
import { useT } from "../../i18n/t";
import { EmptyState } from "../common/EmptyState";
import { fmtWhen } from "../grievances/common";
import { Citation } from "./Citation";
import { obligationTitle } from "../../i18n/labels";

const TAG = { open: "tag-ack", submitted: "tag-directive", accepted: "tag-resolved", rejected: "tag-open", overdue: "tag-open", escalated: "tag-open", waived: "tag-ack" };

export function TaskStatus({ task }) {
  const t = useT();
  return (
    <span className="row wrap-row">
      <span className={`tag ${TAG[task.status] ?? ""}`}>{t(`status.${task.status}`)}</span>
      {task.escalation_level > 0 && <span className="tag tag-open">{t("obligation.task.escalation", { level: task.escalation_level })}</span>}
    </span>
  );
}

export function TaskList({ tasks, showMine = false, onSelect, empty }) {
  const t = useT();
  if (!tasks?.length) return <EmptyState>{empty ?? t("obligation.mine.empty")}</EmptyState>;
  return (
    <div className="scroll-x">
      <table className="obligation-table">
        <thead>
          <tr>
            <th>{t("obligation.list.obligation")}</th>
            {showMine && <th>{t("obligation.list.mine")}</th>}
            <th>{t("obligation.list.period")}</th>
            <th>{t("obligation.list.due")}</th>
            <th>{t("obligation.list.status")}</th>
            <th>{t("obligation.list.evidence")}</th>
          </tr>
        </thead>
        <tbody>
          {tasks.map((task) => (
            <tr key={task.id} data-task={task.id} data-code={task.obligation?.code}
                className={task.status === "overdue" || task.status === "escalated" ? "is-flagged" : ""}
                onClick={() => onSelect?.(task)} style={{ cursor: onSelect ? "pointer" : undefined }}>
              <td>
                <strong className="code">{task.obligation?.code}</strong> {obligationTitle(task.obligation)}
                <div className="small"><Citation obligation={task.obligation} compact /></div>
              </td>
              {showMine && <td><span className="mine-cell"><span className="mine-name">{task.mine_name}</span><span className="mine-code">{task.mine_code}</span></span></td>}
              <td><span className="code">{task.period}</span></td>
              <td>
                <span className="time">{fmtWhen(task.due_at)}</span>
                <div className="faint small">{t(`obligation.dueBasis.${task.due_basis}`)}</div>
              </td>
              <td><TaskStatus task={task} /></td>
              <td className="small">
                {task.incident_id
                  ? <>
                      {t("obligation.incident.reported", { id: task.incident_id })}
                      {task.status === "accepted" && (
                        <div><span className={`tag ${task.reported_late ? "tag-open" : "tag-resolved"}`}>
                          {t(task.reported_late ? "obligation.incident.late" : "obligation.incident.onTime")}
                        </span></div>
                      )}
                    </>
                  : task.latest_submission
                    ? <>{t(`status.${task.latest_submission.status}`)} · {fmtWhen(task.latest_submission.submitted_at)}</>
                    : <span className="faint">-</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
