// Annotated frame with detected PPE violations, and what the detection cost the mine.
import { assetUrl } from "../../api/client";
import { RiskMark } from "../compliance/RiskMark";
import { EmptyState } from "../common/EmptyState";
import { fmtNumber, fmtPercent } from "../../utils/format";
import { violationTypeLabel } from "../../i18n/labels";
import { riskClass, riskLabel } from "../../utils/risk";
import { t } from "../../i18n/t";

/**
 * What this run changed, stated explicitly.
 *
 * Two separate rows, because they are two different facts and the old panel conflated
 * them: how many open violations the mine has, and what its score is. The risk band is
 * read from each side's own compliance record rather than inferred, so a -6 move that
 * stays inside one band correctly shows the same band twice instead of looking broken.
 */
function ChangeRow({ label, before, after, delta, tone }) {
  const moved = before !== after;
  return (
    <div className="change-row">
      <span className="label">{label}</span>
      <span className="change-values">
        <span className="change-before">{before}</span>
        <span className="move-arrow" aria-hidden="true">&rarr;</span>
        <span className={`change-after${tone ? ` ${tone}` : ""}`}>{after}</span>
        {moved && delta != null && <span className="change-delta">{delta}</span>}
      </span>
    </div>
  );
}

function ScoreMove({ before, after, delta, riskChanged, resolvedCount }) {
  const violationDelta = after.violation_count - before.violation_count;

  return (
    <div className="score-move">
      <ChangeRow
        label={t("vision.openViolations")}
        before={fmtNumber(before.violation_count, 0)}
        after={fmtNumber(after.violation_count, 0)}
        delta={violationDelta > 0 ? `+${violationDelta}` : violationDelta < 0 ? `${violationDelta}` : null}
      />

      <ChangeRow
        label={t("vision.complianceScore")}
        before={before.score}
        after={after.score}
        delta={delta > 0 ? `+${delta}` : delta < 0 ? `${delta}` : null}
        tone={riskClass(after.risk_level)}
      />

      <div className="change-row">
        <span className="label">{t("vision.riskBand")}</span>
        <span className="change-values">
          <RiskMark level={before.risk_level} />
          <span className="move-arrow" aria-hidden="true">&rarr;</span>
          <RiskMark level={after.risk_level} />
        </span>
      </div>

      {riskChanged && (
        <div className="notice error" style={{ marginTop: "var(--space-3)" }}>
          {t("vision.bandChanged", { from: riskLabel(before.risk_level), to: riskLabel(after.risk_level) })}
        </div>
      )}

      {resolvedCount > 0 && (
        <div className="notice info" style={{ marginTop: "var(--space-3)" }}>
          {t("vision.cleanReinspection", { n: fmtNumber(resolvedCount, 0) })}
        </div>
      )}
    </div>
  );
}

export function DetectionPreview({ result }) {
  if (!result) return null;

  const { detections, violations, score_before, score_after, score_delta, risk_changed } = result;
  const image = assetUrl(result.annotated_url);

  return (
    <div className="stack">
      <ScoreMove
        before={score_before}
        after={score_after}
        delta={score_delta}
        riskChanged={risk_changed}
        resolvedCount={result.resolved_count}
      />

      {result.resolution && (
        <p className="note">{t(`vision.resolution.${result.resolution.code}`, {
          ...result.resolution.params,
          labels: (result.resolution.params?.labels ?? []).join(", "),
        })}</p>
      )}

      {image ? (
        // Boxes are drawn server-side by the CV module, so what is shown here is exactly the
        // evidence stored against the violation - not a second rendering that could disagree.
        <figure className="annotated">
          <img src={image} alt={t("vision.annotatedAlt")} />
          <figcaption className="small faint">
            {t("vision.detectedCaption", { n: fmtNumber(detections.length, 0) })} <span className="mono">{result.backend}</span>
          </figcaption>
        </figure>
      ) : (
        <EmptyState>{t("vision.noFrame")}</EmptyState>
      )}

      <div>
        <h3 style={{ marginBottom: 6 }}>
          {t("vision.recorded", { n: fmtNumber(violations.length, 0) })}
        </h3>
        {violations.length ? (
          <table>
            <thead>
              <tr><th>{t("vision.colViolation")}</th><th className="num">{t("vision.colConfidence")}</th><th>{t("vision.colSource")}</th></tr>
            </thead>
            <tbody>
              {violations.map((v) => (
                <tr key={v.id}>
                  <td><strong>{violationTypeLabel(v.violation_type)}</strong></td>
                  <td className="num">{fmtPercent(v.confidence)}</td>
                  <td className="small muted">{t(`violationSource.${v.source}`, { defaultValue: v.source })}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="notice info">
            {t("vision.noViolations")}
          </div>
        )}
      </div>

      {detections.length > 0 && (
        <details>
          <summary className="small muted" style={{ cursor: "pointer" }}>
            {t("vision.rawOutput", { n: fmtNumber(detections.length, 0) })}
          </summary>
          <table style={{ marginTop: 6 }}>
            <thead>
              <tr><th>{t("vision.colClass")}</th><th>{t("vision.colMapped")}</th><th className="num">{t("vision.colConfidence")}</th></tr>
            </thead>
            <tbody>
              {detections.map((d, i) => (
                <tr key={i}>
                  <td className="mono">{d.raw_label}</td>
                  <td className="small muted">{d.label ? violationTypeLabel(d.label) : <span className="faint">{t("vision.ignored")}</span>}</td>
                  <td className="num">{fmtPercent(d.confidence)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </details>
      )}
    </div>
  );
}
