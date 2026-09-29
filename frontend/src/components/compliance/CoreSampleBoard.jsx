// Compliance by mine: one bar per mine, filled to its compliance score, lowest on the left.
//
// Every bar has the same track - same width, full 0-100 height, on the same gridlines - so only the
// fill height differs, in solid band colour. The score and band sit just above the fill, in the same
// place and size on every bar and always inside the track, so no bar carries meaning by colour
// alone. Names, codes and places sit in the bar's own column, so they line up under it. More bars
// than fit keep their width and the chart scrolls sideways inside the card.
import { useNavigate } from "react-router-dom";
import { EmptyState } from "../common/EmptyState";
import { fmtScore } from "../../utils/format";
import { riskClass, riskLabel } from "../../utils/risk";
import { t } from "../../i18n/t";

// Band boundaries from the compliance rules, drawn across the chart.
const RULES = [100, 80, 50, 0];

export function CoreSampleBoard({ mines }) {
  const navigate = useNavigate();
  if (!mines?.length) return <EmptyState>{t("board.noMines")}</EmptyState>;

  // Lowest first. The API already sorts this way; sorting here keeps the chart correct
  // regardless of the order it arrives in.
  const ordered = [...mines].sort((a, b) => a.compliance.score - b.compliance.score);

  return (
    <div className="board">
      <div className="board-axis" aria-hidden="true">
        {RULES.map((at) => <span key={at} style={{ bottom: `${at}%` }}>{at}</span>)}
      </div>
      <div className="board-scroll">
        <div className="board-lane">
          <div className="board-rules" aria-hidden="true">
            {RULES.map((at) => <div key={at} className="board-rule" style={{ bottom: `${at}%` }} />)}
          </div>
          {ordered.map((mine) => {
            const { score, risk_level: level } = mine.compliance;
            const cls = riskClass(level);
            const pct = Math.max(0, Math.min(100, score));
            return (
              <div key={mine.id} className="core-col">
                <button type="button" className="core" onClick={() => navigate(`/gov/mines/${mine.id}`)}
                        aria-label={t("board.coreAria", { name: mine.name, district: mine.district, state: mine.state, score: fmtScore(score), band: riskLabel(level) })}>
                  <span className={`core-fill ${cls}`} style={{ height: `${Math.max(1, pct)}%` }} />
                  <span className={`core-readout ${cls}`} style={{ "--score": pct }}>
                    <span className="core-score">{fmtScore(score)}</span>
                    <span className="core-band">{riskLabel(level)}</span>
                  </span>
                </button>
                <div className="core-foot">
                  <div className="core-name">{mine.name}</div>
                  <div className="core-code">{mine.code}</div>
                  {/* District, not just state: the point is where the worst risk actually sits. */}
                  <div className="core-place">{mine.district}, {mine.state}</div>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}
