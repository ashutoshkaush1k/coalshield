// Score plus risk level for a single mine.
import { RiskMark } from "./RiskMark";
import { fmtScore } from "../../utils/format";
import { riskClass } from "../../utils/risk";

export function ScoreCard({ compliance }) {
  if (!compliance) return null;
  const cls = riskClass(compliance.risk_level);

  return (
    <div className="stack tight">
      <div className="row" style={{ alignItems: "flex-end", gap: "var(--space-3)" }}>
        <div className={`hero-score ${cls}`}>{fmtScore(compliance.score)}</div>
        <div className="faint" style={{ paddingBottom: 5, fontSize: "var(--text-sm)" }}>/ 100</div>
        <div className="spacer" />
        <RiskMark level={compliance.risk_level} />
      </div>

      <div className="meter" style={{ marginTop: "var(--space-2)" }}>
        <i className={cls} style={{ width: `${Math.max(0, Math.min(100, compliance.score))}%` }} />
      </div>

      {/* The arithmetic, shown rather than hidden: a judge can check the number against
          the formula without leaving the screen. */}
      <div className="formula" style={{ marginTop: "var(--space-2)" }}>
        100 &minus; ({compliance.violation_count} PPE &times; {compliance.weight_ppe}) &minus; (
        {compliance.breach_count} breaches &times; {compliance.weight_env}) ={" "}
        <strong>{fmtScore(compliance.score)}</strong>
      </div>
    </div>
  );
}
