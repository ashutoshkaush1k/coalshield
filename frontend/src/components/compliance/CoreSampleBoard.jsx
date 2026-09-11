// Mines as drill cores: one tube per mine, filled to its compliance score.
//
// Worst on the left, because the mine that needs an inspector is the one the eye should
// land on first. The score and band are set inside the core itself rather than in a
// separate badge, so the bar is never carrying meaning by colour alone.
import { useNavigate } from "react-router-dom";
import { EmptyState } from "../common/EmptyState";
import { fmtScore } from "../../utils/format";
import { riskClass, riskLabel } from "../../utils/risk";

// Below roughly a quarter depth a core cannot hold its own readout, so the number moves
// above the fill and switches to ink. It never disappears.
const INSIDE_MIN_SCORE = 26;

// Past this many cores the board scrolls sideways rather than shrinking each core until
// its score is unreadable. Compressing the design to fit an arbitrary count is how a
// board stops being legible from across a room.
const SCROLL_THRESHOLD = 10;
const CORE_MIN_WIDTH = 116;

// Band boundaries from services/compliance/risk.py, drawn across the board.
const RULES = [
  { at: 100, label: "100" },
  { at: 80, label: "80" },
  { at: 50, label: "50" },
  { at: 0, label: "0" },
];

function Readout({ score, level, inside }) {
  const cls = riskClass(level);
  return (
    <>
      <span className={`core-score${inside ? "" : ` ${cls}`}`}>{fmtScore(score)}</span>
      <span className="core-band">{riskLabel(level)}</span>
    </>
  );
}

export function CoreSampleBoard({ mines }) {
  const navigate = useNavigate();
  if (!mines?.length) return <EmptyState>No mines match this selection.</EmptyState>;

  // Worst first. The API already sorts this way; sorting here keeps the board correct
  // regardless of the order it arrives in.
  const ordered = [...mines].sort((a, b) => a.compliance.score - b.compliance.score);
  const scrolls = ordered.length > SCROLL_THRESHOLD;
  const laneStyle = scrolls ? { minWidth: ordered.length * CORE_MIN_WIDTH } : undefined;

  return (
    <div className={`board${scrolls ? " is-scrolling" : ""}`}>
      <div className="board-scroll">
        <div className="board-lane" style={laneStyle}>
          <div className="board-track">
            {RULES.map((rule) => (
              <div key={rule.at} className="board-rule" style={{ bottom: `${rule.at}%` }}>
                <span>{rule.label}</span>
              </div>
            ))}

            <div className="board-bars">
              {ordered.map((mine) => {
                const { score, risk_level: level } = mine.compliance;
                const cls = riskClass(level);
                const inside = score >= INSIDE_MIN_SCORE;
                const open = () => navigate(`/gov/mines/${mine.id}`);

                return (
                  <button
                    key={mine.id}
                    type="button"
                    className="core"
                    onClick={open}
                    aria-label={`${mine.name}, ${mine.district}, ${mine.state}. Score ${score}, ${riskLabel(level)}. Open drill-down.`}
                  >
                    {!inside && (
                      <div className="core-readout-above">
                        <Readout score={score} level={level} inside={false} />
                      </div>
                    )}
                    <div className="core-tube">
                      <div className={`core-fill ${cls}`}
                           style={{ height: `${Math.max(2, Math.min(100, score))}%` }}>
                        {inside && <Readout score={score} level={level} inside />}
                      </div>
                    </div>
                  </button>
                );
              })}
            </div>
          </div>

          <div className="board-foot">
            {ordered.map((mine) => (
              <div key={mine.id} className="core-foot">
                <div className="core-name">{mine.name}</div>
                <div className="core-code">{mine.code}</div>
                {/* District, not just state: on a national board the point is where the
                    worst risk actually sits, and a state name is too coarse for that. */}
                <div className="core-place">{mine.district}, {mine.state}</div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
