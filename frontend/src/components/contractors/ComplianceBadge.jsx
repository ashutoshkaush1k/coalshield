// A contractor's compliance: score and band as a labelled chip (never colour alone).
import { useT } from "../../i18n/t";

const TONE = { compliant: "risk-low", watch: "risk-medium", flagged: "risk-high" };

export function ComplianceBadge({ compliance }) {
  const t = useT();
  if (!compliance) return null;
  return (
    <span className={`risk-mark ${TONE[compliance.band] ?? ""}`}>
      <span className="chip" aria-hidden="true" />
      {compliance.score} &middot; {t(`contractor.band.${compliance.band}`)}
    </span>
  );
}
