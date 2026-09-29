// Drill-down: a compact summary on the page, supporting detail in drawers.
//
// The summary answers "how is this mine and what needs doing": score, band, the arithmetic,
// alerts and directives. Sensors, violations, corrective actions, incidents and the audit trail
// open on demand (components/records/MineRecords.jsx).
import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { ALERT_STATUS, isOpenDirective, reopenAlert } from "../../api/alerts";
import { can } from "../../auth/permissions";
import { AlertDetailDrawer } from "../../components/alerts/AlertDetailDrawer";
import { AlertList } from "../../components/alerts/AlertList";
import { FlagMineButton } from "../../components/alerts/FlagMineButton";
import { ErrorNotice } from "../../components/common/ErrorNotice";
import { Loader } from "../../components/common/Loader";
import { RiskMark } from "../../components/compliance/RiskMark";
import { RiskPanel } from "../../components/risk/RiskPanel";
import { PageTitle } from "../../components/layout/PageTitle";
import { useToast } from "../../components/overlay/ToastHost";
import { MineRecords } from "../../components/records/MineRecords";
import { useAuth } from "../../hooks/useAuth";
import { usePolling } from "../../hooks/usePolling";
import { useT } from "../../i18n/t";
import { getMineView } from "../../api/views";
import { breachesHint, breachesLabel, fmtScore, fmtNumber } from "../../utils/format";
import { riskClass } from "../../utils/risk";

/**
 * One mine's full bundle, in one request (GET /v1/views/mine/{id}). Exported so the Mine Head
 * view uses exactly the same data - one definition.
 */
export const loadMineBundle = (mineId) =>
  getMineView(mineId).then(({ corrective_actions: correctiveActions, ...parts }) => ({ ...parts, correctiveActions }));

function Stat({ label, value, tone, hint }) {
  return (
    <div>
      <span className="label" title={hint ?? undefined}>{label}</span>
      <span className={`tally-v${tone ? ` ${tone}` : ""}`}>{typeof value === "number" ? fmtNumber(value) : value}</span>
    </div>
  );
}

/**
 * Score, band, tallies and the formula - the same block on both dashboards. `gri` (Phase 7) puts
 * the Governance Risk Index beside the score; it is a separate measure and changes nothing above.
 */
export function ComplianceSummary({ mine, gri = null }) {
  const t = useT();
  const c = mine.compliance;
  const cls = riskClass(c.risk_level);
  return (
    <>
      <div className="stat-row mine-stats">
        <div>
          <span className="label">{t("mine.score")}</span>
          <div className={`hero-score ${cls}`}>{fmtScore(c.score)}</div>
          <div style={{ marginTop: "var(--space-3)" }}>
            <RiskMark level={c.risk_level} />
          </div>
        </div>
        {gri && (
          <div>
            <span className="label">{t("gri.short")}</span>
            <div className={`hero-score ${riskClass(gri.band)}`} id="gri-beside-score">{fmtNumber(gri.gri, 0)}</div>
            <div style={{ marginTop: "var(--space-3)" }}>
              {/* A real link: it scrolls to the index card below (clear of the sticky header) and moves focus there. */}
              <a href="#governance-risk" className="text-link small" id="gri-explain-link"
                 onClick={(e) => {
                   const card = document.getElementById("governance-risk");
                   if (!card) return;
                   e.preventDefault();
                   card.scrollIntoView({ behavior: "smooth", block: "start" });
                   card.focus({ preventScroll: true });
                 }}>{t("gri.explain")}</a>
            </div>
          </div>
        )}
        <Stat label={t("mine.openViolations")} value={c.violation_count} />
        <Stat label={breachesLabel(c.breach_window_hours)} hint={breachesHint(c.breach_window_hours)} value={c.breach_count} />
        <Stat label={t("mine.openAlerts")} value={mine.open_alerts} tone={mine.open_alerts ? "risk-high" : undefined} />
      </div>
      <div className="meter" style={{ marginTop: "var(--space-5)" }}>
        <i className={cls} style={{ width: `${Math.max(0, Math.min(100, c.score))}%` }} />
      </div>
      <details className="risk-more score-how" id="score-how">
        <summary>{t("mine.howCalculated")}</summary>
        <div className="formula">
          100 - ({c.violation_count} {t("mine.violationsShort")} x {c.weight_ppe}) - ({c.breach_count} {t("mine.breachesShort")} x{" "}
          {c.weight_env}) = {fmtScore(c.score)}
        </div>
      </details>
    </>
  );
}

export const mineSubtitle = (mine) => `${mine.code} · ${mine.district}, ${mine.state} · ${mine.operator_name}`;

export function MineDetailView({ mineId, backTo, refreshToken = 0, children }) {
  const t = useT();
  const { user } = useAuth();
  const load = () => loadMineBundle(mineId);
  const { data, error, loading, refresh } = usePolling(load, { deps: [mineId, refreshToken] });
  const [selectedAlert, setSelectedAlert] = useState(null);

  if (loading && !data) return <Loader label={t("mine.loading")} />;
  // Out of scope is a 404 like a missing mine, so it replaces the page.
  if (error && !data) {
    return (
      <>
        <PageTitle title={t("mine.detail")} />
        <div className="content">
          <ErrorNotice error={error} />
          {backTo && <p style={{ marginTop: 12 }}><Link to={backTo}>{t("mine.back")}</Link></p>}
        </div>
      </>
    );
  }

  const { mine, alerts } = data;
  const openDirectives = alerts.filter(isOpenDirective).length;
  const liveAlert = selectedAlert && alerts.find((a) => a.id === selectedAlert.id);

  return (
    <>
      <PageTitle title={mine.name} subtitle={mineSubtitle(mine)}>
        {backTo && <Link className="btn" to={backTo}>{t("mine.backToOverview")}</Link>}
        {can(user, "directive.create") && <FlagMineButton mineId={mine.id} onFlagged={refresh} />}
      </PageTitle>

      <div className="content stack">
        <ErrorNotice error={error} />

        <div className="grid-12">
          <section className="panel-block fill span-8">
            <div className="panel-body">
              <ComplianceSummary mine={mine} gri={data.risk?.governance_risk} />
              <MineRecords bundle={data} onChanged={refresh} />
            </div>
          </section>

          <section className="panel-block fill span-4">
            <div className="panel-head">
              <div>
                <h2>{t("mine.alerts")}</h2>
                <div className="hint">{t("mine.openDirectives", { count: openDirectives })}</div>
              </div>
            </div>
            <div className="panel-body flush alerts-body">
              <AlertList alerts={alerts} onSelect={setSelectedAlert} />
            </div>
          </section>
        </div>

        <RiskPanel risk={data.risk} />

        {children}
      </div>

      <AlertDetailDrawer
        alert={liveAlert}
        onClose={() => setSelectedAlert(null)}
        action={
          liveAlert?.is_directive && liveAlert?.status === ALERT_STATUS.RESOLVED && can(user, "directive.reopen")
            ? <ReopenAction alert={liveAlert} onReopened={refresh} />
            : null
        }
      />
    </>
  );
}

/** Government sends a directive back when the submitted proof is not sufficient. */
function ReopenAction({ alert, onReopened }) {
  const t = useT();
  const [busy, setBusy] = useState(false);
  const { notify } = useToast();

  async function submit() {
    setBusy(true);
    try {
      await reopenAlert(alert.id, t("mine.reopenReason"));
      notify({ title: t("mine.reopened"), body: t("mine.reopenedBody") });
      onReopened?.();
    } catch (err) {
      notify({ title: t("mine.reopenFailed"), body: err.message, tone: "error" });
    } finally {
      setBusy(false);
    }
  }

  return (
    <button type="button" onClick={submit} disabled={busy}>
      {busy ? t("mine.reopening") : t("mine.reopen")}
    </button>
  );
}

export default function MineDetail() {
  const { mineId } = useParams();
  return <MineDetailView mineId={mineId} backTo="/gov" />;
}
