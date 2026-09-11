// Ranked inspection list, worst first.
//
// The ranking itself comes from GET /api/v1/inspections and is not recomputed here -
// this is presentation only.
import { useNavigate } from "react-router-dom";
import { EmptyState } from "../common/EmptyState";
import { RiskMark } from "../compliance/RiskMark";
import { fmtScore } from "../../utils/format";
import { riskClass } from "../../utils/risk";

export function PriorityQueue({ candidates }) {
  const navigate = useNavigate();
  if (!candidates?.length) return <EmptyState>Nothing queued for inspection.</EmptyState>;

  return (
    <div className="queue">
      {candidates.map((c) => (
        <button
          key={c.mine_id}
          type="button"
          className={`queue-row${c.rank === 1 ? " is-top" : ""}`}
          onClick={() => navigate(`/gov/mines/${c.mine_id}`)}
          aria-label={`Rank ${c.rank}, ${c.name}, score ${c.compliance.score}. Open drill-down.`}
        >
          <span className="queue-rank">{c.rank}</span>

          <span>
            <span className="queue-name">{c.name}</span>
            <span className="queue-place"> {c.location}</span>
            {/* The single most useful line from the ranking's own reasoning. */}
            <span className="queue-reason">{c.reasons?.[0]}</span>
          </span>

          <span className="queue-right">
            <span className={`queue-score ${riskClass(c.compliance.risk_level)}`}>
              {fmtScore(c.compliance.score)}
            </span>
            <RiskMark level={c.compliance.risk_level} />
          </span>
        </button>
      ))}
    </div>
  );
}
