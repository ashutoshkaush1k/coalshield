// Risk band as a dot plus the words on a pale tint.
//
// The label is not optional: colour alone fails on a projector and for red-green colour
// blindness, which is the one failure this dashboard cannot afford.
import { riskClass, riskLabel } from "../../utils/risk";

export function RiskMark({ level, className = "" }) {
  return (
    <span className={`risk-mark ${riskClass(level)} ${className}`}>
      <span className="chip" aria-hidden="true" />
      {riskLabel(level)}
    </span>
  );
}
