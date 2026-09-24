// One mine's standing: score, band, alerts, violations - no cross-mine comparison.
//
// Detail opens in drawers and actions open in modals, so the page stays a summary the
// operator can read at a glance rather than a long scroll.
import { useState } from "react";
import { ALERT_STATUS, ALERT_TYPE } from "../../../api/alerts";
import { AlertDetailDrawer } from "../../../components/alerts/AlertDetailDrawer";
import { AlertList } from "../../../components/alerts/AlertList";
import { ResolveDirectiveForm } from "../../../components/alerts/ResolveDirectiveForm";
import { DetailDrawer } from "../../../components/common/DetailDrawer";
import { EmptyState } from "../../../components/common/EmptyState";
import { RiskMark } from "../../../components/compliance/RiskMark";
import { Drawer } from "../../../components/overlay/Overlay";
import { UploadPanel } from "../../../components/vision/UploadPanel";
import { breachesLabel, fmtDateTime, fmtPercent, fmtScore, humanise } from "../../../utils/format";
import { riskClass } from "../../../utils/risk";

function Stat({ label, value, tone }) {
  return (
    <div>
      <span className="label">{label}</span>
      <span className={`tally-v${tone ? ` ${tone}` : ""}`}>{value}</span>
    </div>
  );
}

export function MineOverviewPanel({ bundle, mineId, onAnalysed, onChanged }) {
  const { mine, violations, alerts, audit } = bundle;
  const c = mine.compliance;
  const cls = riskClass(c.risk_level);
  const [panel, setPanel] = useState(null);
  const [selectedAlert, setSelectedAlert] = useState(null);
  const [selectedRow, setSelectedRow] = useState(null);

  const openDirectives = alerts.filter(
    (a) => a.alert_type === ALERT_TYPE.DIRECTIVE && a.status === ALERT_STATUS.OPEN,
  ).length;
  // Read from the live list so a drawer left open updates with the next poll.
  const liveAlert = selectedAlert && alerts.find((a) => a.id === selectedAlert.id);

  return (
    <div className="stack">
      <div className="grid split">
        <section className="panel-block">
          <div className="panel-body">
            <div className="hero-figure">
              <div>
                <span className="label">Compliance score</span>
                <div className={`hero-score ${cls}`}>{fmtScore(c.score)}</div>
                <div style={{ marginTop: "var(--space-3)" }}>
                  <RiskMark level={c.risk_level} />
                </div>
              </div>
              <div className="spacer" />
              <div className="tally-set">
                <Stat label="Violations" value={c.violation_count} />
                <Stat label={breachesLabel(c.breach_window_hours)} value={c.breach_count} />
                <Stat label="Alerts" value={mine.open_alerts}
                      tone={mine.open_alerts ? "risk-high" : undefined} />
              </div>
            </div>

            <div className="meter" style={{ marginTop: "var(--space-5)" }}>
              <i className={cls} style={{ width: `${Math.max(0, Math.min(100, c.score))}%` }} />
            </div>

            <div className="formula" style={{ marginTop: "var(--space-3)" }}>
              100 - ({c.violation_count} PPE x {c.weight_ppe}) - ({c.breach_count} breaches x{" "}
              {c.weight_env}) = {fmtScore(c.score)}
            </div>

            <div className="row wrap" style={{ marginTop: "var(--space-5)" }}>
              <button type="button" onClick={() => setPanel("violations")}>
                PPE violations ({violations.length})
              </button>
              <button type="button" onClick={() => setPanel("audit")}>
                Audit trail ({audit.length})
              </button>
              <div className="spacer" />
              <UploadPanel mineId={mineId} onAnalysed={onAnalysed} />
            </div>
          </div>
        </section>

        <section className="panel-block">
          <div className="panel-head">
            <div>
              <h2>Alerts and directives</h2>
              <div className="hint">
                {openDirectives} open directive{openDirectives === 1 ? "" : "s"} from DGMS
              </div>
            </div>
          </div>
          <div className="panel-body flush scroll-y">
            <AlertList
              alerts={alerts}
              onSelect={setSelectedAlert}
              actionFor={(alert) =>
                alert.alert_type === ALERT_TYPE.DIRECTIVE &&
                alert.status === ALERT_STATUS.OPEN ? (
                  <ResolveDirectiveForm alert={alert} onResolved={() => onChanged?.()} />
                ) : null
              }
            />
          </div>
        </section>
      </div>

      <Drawer open={panel === "violations"} onClose={() => setPanel(null)}
              title="PPE violations" subtitle={`${violations.length} most recent`} flush>
        {violations.length ? (
          <table>
            <thead>
              <tr><th>Violation</th><th className="num">Confidence</th><th>Detected</th></tr>
            </thead>
            <tbody>
              {violations.map((v) => (
                <tr key={v.id} className="clickable" onClick={() => setSelectedRow(v)}>
                  <td><strong>{humanise(v.violation_type)}</strong></td>
                  <td className="num">{fmtPercent(v.confidence)}</td>
                  <td className="mono">{fmtDateTime(v.detected_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <EmptyState>No PPE violations recorded.</EmptyState>
        )}
      </Drawer>

      <Drawer open={panel === "audit"} onClose={() => setPanel(null)}
              title="Audit trail" subtitle="Every scored event, timestamped" flush>
        {audit.length ? (
          <table>
            <thead><tr><th>Action</th><th>When</th></tr></thead>
            <tbody>
              {audit.map((entry) => (
                <tr key={entry.id}>
                  <td>
                    <span className="mono">{entry.action}</span>
                    <div className="muted small">{entry.detail}</div>
                  </td>
                  <td className="mono">{fmtDateTime(entry.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <EmptyState>No audit entries yet.</EmptyState>
        )}
      </Drawer>

      <AlertDetailDrawer alert={liveAlert} onClose={() => setSelectedAlert(null)} />

      <DetailDrawer
        open={Boolean(selectedRow)}
        onClose={() => setSelectedRow(null)}
        title="Violation detail"
        subtitle={selectedRow ? humanise(selectedRow.violation_type) : ""}
        fields={
          selectedRow
            ? [
                { label: "Violation", value: humanise(selectedRow.violation_type) },
                { label: "Confidence", value: fmtPercent(selectedRow.confidence) },
                { label: "Source", value: selectedRow.source },
                { label: "Detected", value: fmtDateTime(selectedRow.detected_at) },
                { label: "Evidence frame", value: selectedRow.frame_ref, mono: true },
                {
                  label: "Status",
                  value: selectedRow.resolved
                    ? `Resolved ${fmtDateTime(selectedRow.resolved_at)}`
                    : "Open",
                },
              ]
            : []
        }
      />
    </div>
  );
}
