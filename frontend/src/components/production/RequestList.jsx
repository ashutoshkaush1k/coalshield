// Calls for detailed report as a table: mine (for multi-mine roles), period, reason, deadline,
// status and the action that fits the viewer - respond (mine head), or view / close (requester).
import { useT } from "../../i18n/t";
import { fmtDateTime } from "../../utils/format";
import { EmptyState } from "../common/EmptyState";
import { StatusTag } from "./common";

export function RequestList({ requests, showMine = false, actions }) {
  const t = useT();
  if (!requests?.length) return <EmptyState>{t("detailRequest.empty")}</EmptyState>;
  return (
    <div className="scroll-x">
      <table>
        <thead>
          <tr>
            {showMine && <th>{t("detailRequest.mine")}</th>}
            <th>{t("detailRequest.period")}</th>
            <th>{t("detailRequest.reason")}</th>
            <th>{t("detailRequest.due")}</th>
            <th>{t("production.gov.request")}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {requests.map((r) => (
            <tr key={r.id} className={r.status === "overdue" || r.status === "escalated" ? "is-flagged" : ""} data-request={r.id}>
              {showMine && <td><strong>{r.mine_name}</strong> <span className="mono faint">{r.mine_code}</span></td>}
              <td className="mono">{r.date_from} - {r.date_to}</td>
              <td>
                {r.reason}
                <div className="faint small">{t("detailRequest.requestedBy", { name: r.requester_name ?? "-" })}</div>
                {r.response_note && (
                  <div className="small">
                    {r.response_note}
                    <span className="faint"> &middot; {t("detailRequest.respondedAt", { when: fmtDateTime(r.responded_at), name: r.responder_name ?? "-" })}</span>
                  </div>
                )}
              </td>
              <td className="time">{fmtDateTime(r.due_at)}</td>
              <td><StatusTag status={r.status} /></td>
              <td>{actions?.(r)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
