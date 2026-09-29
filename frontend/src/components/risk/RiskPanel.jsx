// Phase 7 on a mine's screen: the Governance Risk Index (measured now from the mine's own records;
// a separate measure - the compliance score does not change), the predicted accident risk from the
// US-trained model (what raises it, and what it is not), and the patterns the automated detectors
// found. Data: the `risk` part of GET /v1/views/mine/{id} - no extra request.
//
// Built to be read at a glance: one large number per card with its band, bars instead of arithmetic,
// and the working (formulas, model details) in expanders. Band colour is the app's one risk scale
// (RiskMark, .hero-score); every bar is a measurement, so it is drawn in ink, never in a band colour.
import { useAuth } from "../../hooks/useAuth";
import { anomalyReasonText, anomalyWindow, categoryLabel, midSentence } from "../../i18n/labels";
import { useT } from "../../i18n/t";
import { fmtList, fmtNumber, fmtPercent, fmtWhen } from "../../utils/format";
import { riskClass } from "../../utils/risk";
import { EmptyState } from "../common/EmptyState";
import { RiskMark } from "../compliance/RiskMark";

/** `part` as a share of `whole`, 0-100, for a bar's width. */
const share = (part, whole) => (whole > 0 ? Math.max(0, Math.min(100, (100 * part) / whole)) : 0);

/** Title, subtitle and what kind of figure the card holds, so the two cards cannot be confused. */
function CardHead({ title, subtitle, kind, children }) {
  return (
    <div className="panel-head risk-card-head">
      <div>
        <h2>{title}{children}</h2>
        <div className="hint">{subtitle}</div>
      </div>
      <span className="tag risk-kind">{kind}</span>
    </div>
  );
}

/** One horizontal bar: its label and figure on a line, the bar under them. Labels wrap, never cut. */
function BarRow({ label, figure, fill, aria, detail }) {
  return (
    <li className="bar-row">
      <span className="bar-label">{label}</span>
      <span className="bar-figure">{figure}</span>
      <span className="bar-track" role="img" aria-label={aria}><i style={{ width: `${fill}%` }} /></span>
      {detail && <span className="bar-detail">{detail}</span>}
    </li>
  );
}

function InfoLine({ id, children }) {
  return (
    <p className="info-line" id={id}>
      <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
        <circle cx="8" cy="8" r="6.8" fill="none" stroke="currentColor" strokeWidth="1.4" />
        <path d="M8 7.2v4.3M8 4.7v.1" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
      </svg>
      <span>{children}</span>
    </p>
  );
}

export function GovernanceRisk({ gri }) {
  const t = useT();
  const { user } = useAuth();
  if (!gri) return null;
  const label = (c) => t(`gri.component.${c.key}`);
  // Largest first; the ones with nothing against them collapse into one line.
  const scored = gri.components.filter((c) => c.value > 0).sort((a, b) => b.value - a.value || b.cap - a.cap);
  const clear = gri.components.filter((c) => !(c.value > 0));
  return (
    <section className="panel-block risk-card fill span-6" id="governance-risk" tabIndex={-1}>
      <CardHead title={t("gri.title")} subtitle={t("gri.subtitle")} kind={t("gri.kind")} />
      <div className="panel-body stack tight">
        <div className="risk-headline">
          <span className={`hero-score risk-big ${riskClass(gri.band)}`} id="gri-value">{fmtNumber(gri.gri, 0)}</span>
          <span className="risk-big-unit">/ 100</span>
          <RiskMark level={gri.band} />
        </div>

        {scored.length > 0 && (
          <ul className="bar-list" id="gri-components">
            {scored.map((c) => (
              <BarRow key={c.key} label={label(c)} fill={share(c.value, c.cap)}
                      figure={`${fmtNumber(c.value, 0)} / ${fmtNumber(c.cap, 0)}`}
                      aria={t("gri.barAria", { label: label(c), value: fmtNumber(c.value, 0), cap: fmtNumber(c.cap, 0) })} />
            ))}
          </ul>
        )}
        {clear.length > 0 && (
          <p className="muted small" id="gri-clear">{t("gri.noIssues", { items: fmtList(clear.map((c) => midSentence(label(c)))) })}</p>
        )}

        {gri.repeat_categories.length ? (
          <div className="risk-callout" id="gri-multiplier">
            <span className="risk-callout-figure">× {fmtNumber(gri.multiplier, 1)}</span>
            <span>{t("gri.multiplierBecause")}</span>
            <span className="risk-callout-tags">
              {gri.repeat_categories.map((c) => <span key={c} className="tag">{categoryLabel(c)}</span>)}
            </span>
          </div>
        ) : <p className="muted small" id="gri-multiplier">{t("gri.noRepeat")}</p>}

        {user?.role === "mine_head" && <InfoLine id="gri-viewer-note">{t("gri.viewerNote")}</InfoLine>}

        <details className="risk-more" id="gri-how">
          <summary>{t("gri.howCalculated")}</summary>
          <ul className="calc-list">
            {gri.components.map((c) => (
              <li key={c.key}>
                <span>{label(c)}</span>
                <span className="num">{t("gri.componentLine", { count: fmtNumber(c.count, 0), points: c.points, value: fmtNumber(c.value, 0), cap: c.cap })}</span>
              </li>
            ))}
          </ul>
          <p className="small num">{t("gri.totalLine", { raw: fmtNumber(gri.raw, 0), multiplier: fmtNumber(gri.multiplier, 1), gri: fmtNumber(gri.gri, 0) })}</p>
          <p className="small muted">{t("gri.settingsNote")}</p>
        </details>
      </div>
    </section>
  );
}

export function PredictedRisk({ prediction: p }) {
  const t = useT();
  const hasFleet = p && p.fleet_percentile !== null && p.fleet_percentile !== undefined;
  const total = p ? 100 * p.probability : 0;   // the headline in percentage points: what a factor's points are part of
  return (
    <section className="panel-block risk-card fill span-6" id="predicted-risk">
      <CardHead title={t("prediction.title")} subtitle={t("prediction.subtitle")} kind={t("prediction.kind")} />
      <div className="panel-body stack tight">
        {!p ? <EmptyState>{t("prediction.none")}</EmptyState> : (
          <>
            <div>
              <div className="risk-headline">
                <span className={`hero-score risk-big ${riskClass(p.band)}`} id="prediction-value">{fmtPercent(p.probability)}</span>
                <RiskMark level={p.band} />
              </div>
              <div className="risk-caption">{t("prediction.headline")}</div>
            </div>

            {hasFleet && (
              <div className="fleet-position" id="prediction-fleet">
                <span className="small">{t("prediction.fleet", { pct: fmtNumber(p.fleet_percentile, 0) })}</span>
                <span className="pct-track" aria-hidden="true">
                  <i style={{ width: `${share(p.fleet_percentile, 100)}%` }} />
                  <b style={{ left: `${share(p.fleet_percentile, 100)}%` }} />
                </span>
              </div>
            )}

            <div className="stack tight" id="prediction-factors">
              <div>
                <span className="label">{t("prediction.factors")}</span>
                {p.factors.length > 0 && <div className="faint small">{t("prediction.factorsHint", { total: fmtPercent(p.probability) })}</div>}
              </div>
              {p.factors.length ? (
                <ul className="bar-list">
                  {p.factors.map((f) => {
                    const name = t(`prediction.feature.${f.feature}`);
                    const points = t("prediction.factorPoints", { points: fmtNumber(f.points, 1) });
                    const detail = f.fleet_percentile !== null && f.fleet_percentile !== undefined
                      ? t("prediction.factorViolationDetail", { value: fmtNumber(f.value, 1), pct: fmtNumber(f.fleet_percentile, 0) })
                      : t("prediction.factorOtherDetail", { value: fmtNumber(f.value, 1), typical: fmtNumber(f.typical, 1) });
                    return <BarRow key={f.feature} label={name} figure={points} fill={share(f.points, total)} detail={detail} aria={`${name}: ${points}`} />;
                  })}
                </ul>
              ) : <p className="muted small">{t("prediction.noFactors")}</p>}
            </div>

            <InfoLine id="prediction-disclaimer">{t("prediction.transferNote")}</InfoLine>

            <details className="risk-more" id="prediction-about">
              <summary>{t("prediction.aboutTitle")}</summary>
              <div className="stack tight small">
                <p>{t("prediction.target")}</p>
                <p>{t("prediction.about", { auc: fmtNumber(p.test_auc, 2), baseline: fmtNumber(p.baseline_auc, 2) })}</p>
                <p className="muted">{t("prediction.version", { version: p.model_version })}</p>
              </div>
            </details>

            <span className="faint small" id="prediction-computed">
              {t("prediction.computed", { when: fmtWhen(p.predicted_at), engine: t(`anomaly.engine.${p.engine}`, { defaultValue: p.engine }) })}
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

/** The index and the prediction side by side (stacked below 1280 px), the findings under them. */
export function RiskPanel({ risk }) {
  if (!risk) return null;
  return (
    <div className="risk-section" id="risk-panel">
      <div className="grid-12 risk-pair">
        <GovernanceRisk gri={risk.governance_risk} />
        <PredictedRisk prediction={risk.prediction} />
      </div>
      <Patterns patterns={risk.patterns} />
    </div>
  );
}
