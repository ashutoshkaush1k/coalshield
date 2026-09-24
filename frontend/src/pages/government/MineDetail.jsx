// Drill-down: a compact summary on the page, supporting detail in drawers.
//
// Previously every section stacked on one long page - compliance, alerts, three sensor
// charts, the violation log and the audit trail - so the useful part (the score and what
// to do about it) was buried above a lot of scrolling. The summary now answers "how is
// this mine and what needs doing"; everything else opens on demand.
import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { ALERT_STATUS, ALERT_TYPE, reopenAlert } from "../../api/alerts";
import { listAlerts } from "../../api/alerts";
import { listAudit } from "../../api/audit";
import { getMine } from "../../api/mines";
import { getTrend } from "../../api/sensors";
import { listViolations } from "../../api/violations";
import { AlertDetailDrawer } from "../../components/alerts/AlertDetailDrawer";
import { AlertList } from "../../components/alerts/AlertList";
import { FlagMineButton } from "../../components/alerts/FlagMineButton";
import { SensorTrendChart } from "../../components/charts/SensorTrendChart";
import { DetailDrawer } from "../../components/common/DetailDrawer";
import { EmptyState } from "../../components/common/EmptyState";
import { ErrorNotice } from "../../components/common/ErrorNotice";
import { Loader } from "../../components/common/Loader";
import { RiskMark } from "../../components/compliance/RiskMark";
import { Drawer } from "../../components/overlay/Overlay";
import { useToast } from "../../components/overlay/ToastHost";
import { Topbar } from "../../components/layout/Topbar";
import { usePolling } from "../../hooks/usePolling";
import { breachesLabel, fmtDateTime, fmtPercent, fmtScore, humanise } from "../../utils/format";
import { riskClass } from "../../utils/risk";

/**
 * One mine's full bundle. Exported so the Mine Head view composes exactly the same
 * request set instead of assembling its own - identical data, one definition.
 */
export const loadMineBundle = (mineId) =>
  Promise.all([
    getMine(mineId),
    getTrend(mineId),
    listViolations({ mine_id: mineId, limit: 25 }),
    listAlerts({ mine_id: mineId, limit: 25 }),
    listAudit({ mine_id: mineId, limit: 40 }),
  ]).then(([mine, trend, violations, alerts, audit]) => ({ mine, trend, violations, alerts, audit }));

function Stat({ label, value, tone }) {
  return (
    <div>
      <span className="label">{label}</span>
      <span className={`tally-v${tone ? ` ${tone}` : ""}`}>{value}</span>
    </div>
  );
}

export function MineDetailView({ mineId, backTo, refreshToken = 0, children }) {
  const load = () => loadMineBundle(mineId);
  const { data, error, loading, refresh } = usePolling(load, { deps: [mineId, refreshToken] });

  // Which supporting detail is open. Drawers read from `data`, so a poll landing while
  // one is open updates it in place rather than closing it.
  const [panel, setPanel] = useState(null);
  const [selectedAlert, setSelectedAlert] = useState(null);
  const [selectedRow, setSelectedRow] = useState(null);

  if (loading && !data) return <Loader label="Loading mine..." />;
  // A 403 here is the access model working, so it replaces the page rather than sitting
  // beside a half-rendered one.
  if (error && !data) {
    return (
      <>
        <Topbar title="Mine detail" />
        <div className="content">
          <ErrorNotice error={error} />
          {backTo && <p style={{ marginTop: 12 }}><Link to={backTo}>Back</Link></p>}
        </div>
      </>
    );
  }

  const { mine, trend, violations, alerts, audit } = data;
  const c = mine.compliance;
  const cls = riskClass(c.risk_level);
  const openDirectives = alerts.filter(
    (a) => a.alert_type === ALERT_TYPE.DIRECTIVE && a.status === ALERT_STATUS.OPEN,
  ).length;
  // Live alert, so a drawer opened on one reflects the latest poll instead of freezing.
  const liveAlert = selectedAlert && alerts.find((a) => a.id === selectedAlert.id);

  return (
    <>
      <Topbar title={mine.name} subtitle={`${mine.code} \u00b7 ${mine.location} \u00b7 ${mine.operator}`}>
        {backTo && <Link className="btn" to={backTo}>Back to overview</Link>}
      </Topbar>

      <div className="content stack">
        <ErrorNotice error={error} />

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
                <button type="button" onClick={() => setPanel("sensors")}>Sensor trends</button>
                <button type="button" onClick={() => setPanel("violations")}>
                  PPE violations ({violations.length})
                </button>
                <button type="button" onClick={() => setPanel("audit")}>
                  Audit trail ({audit.length})
                </button>
                <div className="spacer" />
                <FlagMineButton mineId={mine.id} onFlagged={refresh} />
              </div>
            </div>
          </section>

          <section className="panel-block">
            <div className="panel-head">
              <div>
                <h2>Alerts</h2>
                <div className="hint">
                  {openDirectives} open directive{openDirectives === 1 ? "" : "s"}
                </div>
              </div>
            </div>
            <div className="panel-body flush scroll-y">
              <AlertList alerts={alerts} onSelect={setSelectedAlert} />
            </div>
          </section>
        </div>

        {children}
      </div>

      <Drawer open={panel === "sensors"} onClose={() => setPanel(null)}
              title="Sensor trends" subtitle="Markers show readings past the safe limit">
        <div className="stack">
          {trend.series.map((series) => (
            <div key={series.sensor_type}>
              <div className="row" style={{ marginBottom: "var(--space-2)" }}>
                <strong style={{ textTransform: "capitalize" }}>{series.sensor_type}</strong>
                <span className="muted small">limit {series.threshold}{series.unit}</span>
                <div className="spacer" />
                <span className={series.breach_count ? "sev HIGH" : "tag"}>
                  {series.breach_count} breach{series.breach_count === 1 ? "" : "es"}
                </span>
              </div>
              <SensorTrendChart series={series} mineId={trend.mine_id} />
            </div>
          ))}
        </div>
      </Drawer>

      <Drawer open={panel === "violations"} onClose={() => setPanel(null)}
              title="PPE violations" subtitle={`${violations.length} most recent`} flush>
        {violations.length ? (
          <table>
            <thead>
              <tr><th>Violation</th><th className="num">Confidence</th><th>Detected</th></tr>
            </thead>
            <tbody>
              {violations.map((v) => (
                <tr key={v.id} className="clickable"
                    onClick={() => setSelectedRow({ kind: "violation", row: v })}>
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
                <tr key={entry.id} className="clickable"
                    onClick={() => setSelectedRow({ kind: "audit", row: entry })}>
                  <td>
                    <span className="mono">{entry.action}</span>
                    <div className="muted small">{entry.detail.slice(0, 60)}</div>
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

      <AlertDetailDrawer
        alert={liveAlert}
        onClose={() => setSelectedAlert(null)}
        action={
          liveAlert?.alert_type === ALERT_TYPE.DIRECTIVE &&
          liveAlert?.status === ALERT_STATUS.RESOLVED ? (
            <ReopenAction alert={liveAlert} onReopened={refresh} />
          ) : null
        }
      />

      <RowDetail selection={selectedRow} onClose={() => setSelectedRow(null)} />
    </>
  );
}

/** Detail for one violation or audit entry, opened from its table row. */
function RowDetail({ selection, onClose }) {
  if (!selection) return null;
  const { kind, row } = selection;

  const fields =
    kind === "violation"
      ? [
          { label: "Violation", value: humanise(row.violation_type) },
          { label: "Confidence", value: fmtPercent(row.confidence) },
          { label: "Source", value: row.source },
          { label: "Detected", value: fmtDateTime(row.detected_at) },
          { label: "Evidence frame", value: row.frame_ref, mono: true },
          {
            label: "Status",
            value: row.resolved ? `Resolved ${fmtDateTime(row.resolved_at)}` : "Open",
          },
        ]
      : [
          { label: "Action", value: row.action, mono: true },
          { label: "Detail", value: row.detail },
          { label: "Actor", value: row.actor },
          { label: "Recorded", value: fmtDateTime(row.created_at) },
          {
            label: "Entity",
            value: row.entity_type ? `${row.entity_type} #${row.entity_id}` : null,
            mono: true,
          },
        ];

  return (
    <DetailDrawer
      open
      onClose={onClose}
      title={kind === "violation" ? "Violation detail" : "Audit entry"}
      subtitle={kind === "violation" ? humanise(row.violation_type) : row.action}
      fields={fields}
    />
  );
}

/** Government sends a directive back when the submitted proof is not sufficient. */
function ReopenAction({ alert, onReopened }) {
  const [busy, setBusy] = useState(false);
  const { notify } = useToast();

  async function submit() {
    setBusy(true);
    try {
      await reopenAlert(alert.id, "Proof not sufficient");
      notify({ title: "Directive reopened", body: "The mine head has been asked to resubmit." });
      onReopened?.();
    } finally {
      setBusy(false);
    }
  }

  return (
    <button type="button" onClick={submit} disabled={busy}>
      {busy ? "Reopening..." : "Reopen"}
    </button>
  );
}

export default function MineDetail() {
  const { mineId } = useParams();
  return <MineDetailView mineId={mineId} backTo="/gov" />;
}
