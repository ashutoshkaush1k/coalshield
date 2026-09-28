// Ranked inspection list, worst first.
//
// The ranking itself comes from GET /v1/inspections/priority (ordered by the Governance Risk Index
// since Phase 7) and is not recomputed here - this is presentation only.
import { useNavigate } from "react-router-dom";
import { EmptyState } from "../common/EmptyState";
import { RiskMark } from "../compliance/RiskMark";
import { fmtScore } from "../../utils/format";
import { riskClass } from "../../utils/risk";
import { reasonText } from "../../i18n/labels";
import { t } from "../../i18n/t";

export function PriorityQueue({ candidates }) {
  const navigate = useNavigate();
  if (!candidates?.length) return <EmptyState>{t("priority.empty")}</EmptyState>;

  return (
    <div className="queue">
      {candidates.map((c) => (
        <button
          key={c.mine_id}
          type="button"
          className={`queue-row${c.rank === 1 ? " is-top" : ""}`}
          onClick={() => navigate(`/gov/mines/${c.mine_id}`)}
          aria-label={t("priority.rowAria", { rank: c.rank, name: c.name, score: fmtScore(c.compliance.score),
            gri: fmtScore(c.governance_risk?.gri) })}
        >
          <span className="queue-rank">{c.rank}</span>

          <span>
            <span className="queue-name">{c.name}</span>
            <span className="queue-place"> {c.district}, {c.state}</span>
            {/* The single most useful line from the ranking's own reasoning. */}
            <span className="queue-reason">{reasonText(c.reasons?.[0])}</span>
          </span>

          <span className="queue-right">
            <span className="queue-figures">
              {c.governance_risk && (
                <span className="queue-pair" title={t("gri.title")}>
                  <span className="label">{t("gri.short")}</span>
                  <span className={`queue-score ${riskClass(c.governance_risk.band)}`}>{fmtScore(c.governance_risk.gri)}</span>
                </span>
              )}
              <span className="queue-pair">
                <span className="label">{t("mine.scoreShort")}</span>
                <span className={`queue-score ${riskClass(c.compliance.risk_level)}`}>
                  {fmtScore(c.compliance.score)}
                </span>
              </span>
            </span>
            <RiskMark level={c.compliance.risk_level} />
          </span>
        </button>
      ))}
    </div>
  );
}
