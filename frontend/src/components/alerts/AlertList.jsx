// Alerts feed. Rows open a detail drawer rather than expanding in place.
import { ALERT_STATUS, ALERT_TYPE } from "../../api/alerts";
import { EmptyState } from "../common/EmptyState";
import { fmtDateTime } from "../../utils/format";

export function AlertList({ alerts, showMine = false, mineLookup = {}, onSelect, actionFor }) {
  if (!alerts?.length) return <EmptyState>No active alerts.</EmptyState>;

  return (
    <div className="alert-feed">
      {alerts.map((alert) => {
        const directive = alert.alert_type === ALERT_TYPE.DIRECTIVE;
        const resolved = alert.status === ALERT_STATUS.RESOLVED;

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
              <span className={`sev ${alert.severity}`}>{alert.severity}</span>
              {directive && <span className="tag tag-directive">From DGMS</span>}
              {directive && (
                <span className={`tag ${resolved ? "tag-resolved" : "tag-open"}`}>
                  {resolved ? "Resolved" : "Open"}
                </span>
              )}
              {!directive && <span className="tag">{alert.source}</span>}
              {showMine && <span className="mono">{mineLookup[alert.mine_id] || alert.mine_id}</span>}
              <div className="spacer" />
              <span className="mono">{fmtDateTime(alert.created_at)}</span>
            </div>

            <p className="alert-message">{alert.message}</p>

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
