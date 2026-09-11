// Full detail for one alert or directive, including every resolution attempt.
import { ALERT_STATUS, ALERT_TYPE } from "../../api/alerts";
import { assetUrl } from "../../api/client";
import { Drawer } from "../overlay/Overlay";
import { fmtDateTime } from "../../utils/format";

function Field({ label, children }) {
  return (
    <div className="detail-field">
      <span className="label">{label}</span>
      <div className="detail-value">{children}</div>
    </div>
  );
}

export function AlertDetailDrawer({ alert, onClose, action }) {
  const directive = alert?.alert_type === ALERT_TYPE.DIRECTIVE;

  return (
    <Drawer
      open={Boolean(alert)}
      onClose={onClose}
      title={directive ? "Directive" : "System alert"}
      subtitle={alert ? fmtDateTime(alert.created_at) : ""}
      footer={action}
    >
      {alert && (
        <div className="stack tight">
          <div className="row wrap">
            <span className={`sev ${alert.severity}`}>{alert.severity}</span>
            {directive ? (
              <>
                <span className="tag tag-directive">From DGMS</span>
                <span className={`tag ${alert.status === ALERT_STATUS.RESOLVED ? "tag-resolved" : "tag-open"}`}>
                  {alert.status === ALERT_STATUS.RESOLVED ? "Resolved" : "Open"}
                </span>
              </>
            ) : (
              <span className="tag">{alert.source}</span>
            )}
          </div>

          <Field label="Message">{alert.message}</Field>
          {alert.raised_by && <Field label="Raised by">{alert.raised_by}</Field>}
          {alert.reference_id && (
            <Field label="Related record"><span className="mono">#{alert.reference_id}</span></Field>
          )}

          {alert.resolutions?.length > 0 && (
            <div>
              <span className="label">Resolution history</span>
              <div className="stack tight" style={{ marginTop: "var(--space-2)" }}>
                {alert.resolutions.map((r, i) => (
                  <div key={r.id} className="proof">
                    <div className="proof-meta">
                      {i === 0 ? "Latest submission" : "Earlier attempt"} &middot; {r.created_by} &middot;{" "}
                      {fmtDateTime(r.resolved_at || r.created_at)}
                    </div>
                    <p className="proof-text">{r.description}</p>
                    {r.proof_image_url && (
                      <a href={assetUrl(r.proof_image_url)} target="_blank" rel="noreferrer">
                        <img className="proof-image" src={assetUrl(r.proof_image_url)}
                             alt="Evidence submitted with the resolution" />
                      </a>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </Drawer>
  );
}
