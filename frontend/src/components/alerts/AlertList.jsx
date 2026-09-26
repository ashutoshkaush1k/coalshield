// Alerts feed. Rows open a detail drawer rather than expanding in place. The API sends
// {code, params}; the sentence is composed here through i18n.
import { ALERT_STATUS } from "../../api/alerts";
import { alertSource, alertText, severityLabel, statusLabel } from "../../i18n/labels";
import { useT } from "../../i18n/t";
import { EmptyState } from "../common/EmptyState";
import { fmtDateTime } from "../../utils/format";

const STATUS_TAG = { open: "tag-open", acknowledged: "tag-ack", resolved: "tag-resolved" };

export function AlertList({ alerts, showMine = false, onSelect, actionFor }) {
  const t = useT();
  if (!alerts?.length) return <EmptyState>{t("alertList.empty")}</EmptyState>;

  return (
    <div className="alert-feed">
      {alerts.map((alert) => {
        const directive = alert.is_directive;
        return (
          <div
            key={alert.id}
            className={`alert-row${directive ? " is-directive" : ""}${onSelect ? " is-clickable" : ""}`}
            onClick={onSelect ? () => onSelect(alert) : undefined}
            role={onSelect ? "button" : undefined}
            tabIndex={onSelect ? 0 : undefined}
            onKeyDown={onSelect ? (e) => e.key === "Enter" && onSelect(alert) : undefined}
          >
            <div className="row wrap" style={{ gap: "var(--space-2)" }}>
              <span className={`sev ${alert.severity}`}>{severityLabel(alert.severity)}</span>
              <span className={`tag${directive ? " tag-directive" : ""}`}>{alertSource(alert)}</span>
              {(directive || alert.status !== ALERT_STATUS.OPEN) && (
                <span className={`tag ${STATUS_TAG[alert.status] ?? ""}`}>{statusLabel(alert.status)}</span>
              )}
              {showMine && <span className="mono">{alert.mine_code}</span>}
              <div className="spacer" />
              <span className="mono">{fmtDateTime(alert.created_at)}</span>
            </div>

            <p className="alert-message">{alertText(alert)}</p>

            {/* The action stops the row's own click so pressing Resolve does not also
                open the drawer behind the modal. */}
            {actionFor && (
              <div className="alert-actions" onClick={(e) => e.stopPropagation()}>
                {actionFor(alert)}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
