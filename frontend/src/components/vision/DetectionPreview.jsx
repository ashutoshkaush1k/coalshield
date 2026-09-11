// Annotated frame with detected PPE violations, and what the detection cost the mine.
import { assetUrl } from "../../api/client";
import { RiskMark } from "../compliance/RiskMark";
import { EmptyState } from "../common/EmptyState";
import { fmtPercent, humanise } from "../../utils/format";
import { riskClass } from "../../utils/risk";

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
        label="Open violations"
        before={before.violation_count}
        after={after.violation_count}
        delta={violationDelta > 0 ? `+${violationDelta}` : violationDelta < 0 ? `${violationDelta}` : null}
      />

      <ChangeRow
        label="Compliance score"
        before={before.score}
        after={after.score}
        delta={delta > 0 ? `+${delta}` : delta < 0 ? `${delta}` : null}
        tone={riskClass(after.risk_level)}
      />

      <div className="change-row">
        <span className="label">Risk band</span>
        <span className="change-values">
          <RiskMark level={before.risk_level} />
          <span className="move-arrow" aria-hidden="true">&rarr;</span>
          <RiskMark level={after.risk_level} />
        </span>
      </div>

      {riskChanged && (
        <div className="notice error" style={{ marginTop: "var(--space-3)" }}>
          Risk band changed from {before.risk_level} to {after.risk_level}. This mine has moved
          band on the authority dashboard.
        </div>
      )}

      {resolvedCount > 0 && (
        <div className="notice info" style={{ marginTop: "var(--space-3)" }}>
          Clean re-inspection accepted. {resolvedCount} previously open violation
          {resolvedCount === 1 ? " was" : "s were"} marked resolved and no longer count against
          this score. They stay in the violation log and audit trail.
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

      {image ? (
        // Boxes are drawn server-side by the CV module, so what is shown here is exactly the
        // evidence stored against the violation - not a second rendering that could disagree.
        <figure className="annotated">
          <img src={image} alt="Annotated frame with detected PPE violations" />
          <figcaption className="small faint">
            {detections.length} object{detections.length === 1 ? "" : "s"} detected, red boxes are
            violations. Model backend <span className="mono">{result.backend}</span>
          </figcaption>
        </figure>
      ) : (
        <EmptyState>No annotated frame returned for this input.</EmptyState>
      )}

      <div>
        <h3 style={{ marginBottom: 6 }}>
          Violations recorded ({violations.length})
        </h3>
        {violations.length ? (
          <table>
            <thead>
              <tr><th>Violation</th><th className="num">Confidence</th><th>Alerts</th></tr>
            </thead>
            <tbody>
              {violations.map((v) => (
                <tr key={v.id}>
                  <td><strong>{humanise(v.violation_type)}</strong></td>
                  <td className="num">{fmtPercent(v.confidence)}</td>
                  <td className="small muted">{v.source}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="notice info">
            No PPE violations detected in this frame. The compliance score is unchanged.
          </div>
        )}
      </div>

      {detections.length > 0 && (
        <details>
          <summary className="small muted" style={{ cursor: "pointer" }}>
            Raw model output ({detections.length} detections)
          </summary>
          <table style={{ marginTop: 6 }}>
            <thead>
              <tr><th>Class</th><th>Mapped to</th><th className="num">Confidence</th></tr>
            </thead>
            <tbody>
              {detections.map((d, i) => (
                <tr key={i}>
                  <td className="mono">{d.raw_label}</td>
                  <td className="small muted">{d.label || <span className="faint">ignored</span>}</td>
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
