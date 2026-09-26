// Full detail for one alert or directive, including every transition with its proof.
import { assetUrl } from "../../api/client";
import { alertSource, alertText, severityLabel, statusLabel } from "../../i18n/labels";
import { useT } from "../../i18n/t";
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
  const t = useT();
  const history = alert?.history ?? [];

  return (
    <Drawer
      open={Boolean(alert)}
      onClose={onClose}
      title={alert?.is_directive ? t("alertDrawer.directive") : t("alertDrawer.alert")}
      subtitle={alert ? fmtDateTime(alert.created_at) : ""}
      footer={action}
    >
      {alert && (
        <div className="stack tight">
          <div className="row wrap">
            <span className={`sev ${alert.severity}`}>{severityLabel(alert.severity)}</span>
            <span className={`tag${alert.is_directive ? " tag-directive" : ""}`}>{alertSource(alert)}</span>
            <span className="tag">{statusLabel(alert.status)}</span>
            <span className="mono faint">{alert.code}</span>
          </div>

          <Field label={t("alertDrawer.message")}>{alertText(alert)}</Field>
          {alert.params?.raised_by_name && <Field label={t("alert.raisedBy")}>{alert.params.raised_by_name}</Field>}
          <Field label={t("alertDrawer.record")}>
            <span className="mono">{alert.entity_type} #{alert.entity_id}</span>
          </Field>

          {history.length > 0 && (
            <div>
              <span className="label">{t("alert.history")}</span>
              <div className="stack tight" style={{ marginTop: "var(--space-2)" }}>
                {history.map((h) => (
                  <div key={h.id} className="proof">
                    <div className="proof-meta">
                      {statusLabel(h.from_status)} &rarr; {statusLabel(h.to_status)} &middot; {h.user_name || "-"} &middot;{" "}
                      {fmtDateTime(h.created_at)}
                    </div>
                    {h.context?.proof_text && <p className="proof-text">{h.context.proof_text}</p>}
                    {h.context?.reason && <p className="proof-text">{t("alert.reopenedBecause", { reason: h.context.reason })}</p>}
                    {h.proof_url && (
                      <a href={assetUrl(h.proof_url)} target="_blank" rel="noreferrer">
                        <img className="proof-image" src={assetUrl(h.proof_url)} alt={t("alert.proof")} />
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
