// Phase 7 on a mine's screen: the Governance Risk Index (a separate measure - the compliance score
// does not change), the predicted risk from the US-trained model (with what raises it and what it
// is not), and the patterns the automated detectors found. Data: the `risk` part of
// GET /v1/views/mine/{id} - no extra request.
import { useAuth } from "../../hooks/useAuth";
import { anomalyReasonText, anomalyWindow, categoryLabel } from "../../i18n/labels";
import { useT } from "../../i18n/t";
import { fmtNumber, fmtPercent, fmtWhen } from "../../utils/format";
import { riskClass } from "../../utils/risk";
import { DemoTag } from "../common/DemoTag";
import { EmptyState } from "../common/EmptyState";

export function GovernanceRisk({ gri }) {
  const t = useT();
  const { user } = useAuth();
  if (!gri) return null;
  return (
    <section className="panel-block" id="governance-risk">
      <div className="panel-head">
        <div>
          <h2>{t("gri.title")} <DemoTag /></h2>
          <span className="hint">{t("gri.hint")}</span>
        </div>
      </div>
      <div className="panel-body stack tight">
        <div className="row wrap-row">
          <span className={`hero-score ${riskClass(gri.band)}`} id="gri-value">{fmtNumber(gri.gri, 0)}</span>
          <span className={`risk-mark ${riskClass(gri.band)}`}><span className="chip" />{t(`risk.${gri.band}`)}</span>
        </div>
        <table className="gri-table">
          <tbody>
            {gri.components.map((c) => (
              <tr key={c.key} className={c.value ? "" : "is-zero"}>
                <td>{t(`gri.component.${c.key}`)}</td>
                <td className="num mono">{t("gri.componentLine", { count: fmtNumber(c.count, 0), points: c.points, value: fmtNumber(c.value, 0), cap: c.cap })}</td>
              </tr>
            ))}
            <tr className="is-total">
              <td>{gri.repeat_categories.length
                ? t("gri.multiplier", { categories: gri.repeat_categories.map(categoryLabel).join(", ") })
                : t("gri.noRepeat")}</td>
              <td className="num mono">× {fmtNumber(gri.multiplier, 1)}</td>
            </tr>
          </tbody>
        </table>
        {user?.role === "mine_head" && <p className="note small">{t("gri.viewerNote")}</p>}
      </div>
    </section>
  );
}

export function PredictedRisk({ prediction }) {
  const t = useT();
  return (
    <section className="panel-block" id="predicted-risk">
      <div className="panel-head">
        <div>
          <h2>{t("prediction.title")}</h2>
          <span className="hint">{t("prediction.target")}</span>
        </div>
      </div>
      <div className="panel-body stack tight">
        {!prediction ? <EmptyState>{t("prediction.none")}</EmptyState> : (
          <>
            <div className="row wrap-row">
              <span className={`hero-score ${riskClass(prediction.band)}`} id="prediction-value">{fmtPercent(prediction.probability)}</span>
              <span className={`risk-mark ${riskClass(prediction.band)}`}><span className="chip" />{t(`risk.${prediction.band}`)}</span>
            </div>
            <div className="small">{t("prediction.fleet", { pct: fmtNumber(prediction.fleet_percentile, 0) })}</div>
            <span className="label">{t("prediction.factors")}</span>
            {prediction.factors.length ? (
              <ul className="factor-list">
                {prediction.factors.map((f) => (
                  <li key={f.feature}>
                    {f.fleet_percentile !== null && f.fleet_percentile !== undefined
                      ? t("prediction.factorViolation", { label: t(`prediction.feature.${f.feature}`), value: fmtNumber(f.value, 1),
                          pct: fmtNumber(f.fleet_percentile, 0), points: fmtNumber(f.points, 1) })
                      : t("prediction.factorOther", { label: t(`prediction.feature.${f.feature}`), value: fmtNumber(f.value, 1),
                          typical: fmtNumber(f.typical, 1), points: fmtNumber(f.points, 1) })}
                  </li>
                ))}
              </ul>
            ) : <p className="faint small">{t("prediction.noFactors")}</p>}
            <p className="note small" id="prediction-disclaimer">
              {t("prediction.disclaimer", { auc: fmtNumber(prediction.test_auc, 2), baseline: fmtNumber(prediction.baseline_auc, 2) })}
            </p>
            <span className="faint small">
              {t("prediction.computed", { when: fmtWhen(prediction.predicted_at), engine: t(`anomaly.engine.${prediction.engine}`, { defaultValue: prediction.engine }) })}
            </span>
          </>
        )}
      </div>
    </section>
  );
}

export function Patterns({ patterns, showMine = false, id = "patterns" }) {
  const t = useT();
  return (
    <section className="panel-block" id={id}>
      <div className="panel-head">
        <div>
          <h2>{t("anomaly.title")} {patterns?.length ? <span className="tag">{fmtNumber(patterns.length, 0)}</span> : null}</h2>
          <span className="hint">{t("anomaly.hint")}</span>
        </div>
      </div>
      <div className="panel-body flush">
        {!patterns?.length ? <EmptyState>{t("anomaly.none")}</EmptyState> : (
          <ul className="pattern-list">
            {patterns.map((p) => (
              <li key={p.id} data-detector={p.detector} data-mine={p.mine_code}>
                <div className="row wrap-row">
                  <strong>{t(`anomaly.detector.${p.detector}`)}</strong>
                  {showMine && <span>{p.mine_name} <span className="mono faint">{p.mine_code}</span></span>}
                  <span className="spacer" />
                  <span className={`tag ${p.engine === "php" ? "tag-ack" : ""}`}>{t(`anomaly.engine.${p.engine}`)}</span>
                </div>
                {p.reasons.map((r, i) => <div key={i} className="small">{anomalyReasonText(r, p)}</div>)}
                <div className="faint small">{anomalyWindow(p)} · {t("anomaly.since", { when: fmtWhen(p.first_detected_at) })}</div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}

/** The three together, for a mine's screen. */
export function RiskPanel({ risk }) {
  if (!risk) return null;
  return (
    <div className="grid three-col risk-grid" id="risk-panel">
      <GovernanceRisk gri={risk.governance_risk} />
      <PredictedRisk prediction={risk.prediction} />
      <Patterns patterns={risk.patterns} />
    </div>
  );
}
