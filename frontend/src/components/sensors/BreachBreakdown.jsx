// Breach counts split by sensor category, so "this mine's problem is mostly gas, not
// dust" is readable at a glance rather than inferred from three separate charts.
import { EmptyState } from "../common/EmptyState";
import { sensorColour } from "../../utils/tokens";

const ORDER = ["gas", "dust", "temperature"];

export function BreachBreakdown({ counts, total, compact = false }) {
  const entries = ORDER.map((s) => ({ sensor: s, count: counts?.[s] ?? 0 }));
  const sum = total ?? entries.reduce((n, e) => n + e.count, 0);

  if (!sum) return compact ? <span className="faint small">No breaches</span>
                           : <EmptyState>No breaches recorded.</EmptyState>;

  return (
    <div className={`breakdown${compact ? " is-compact" : ""}`}>
      {/* One stacked bar rather than three: the question is proportion, not magnitude. */}
      <div className="breakdown-bar" role="img"
           aria-label={entries.map((e) => `${e.count} ${e.sensor}`).join(", ")}>
        {entries.filter((e) => e.count > 0).map((e) => (
          <div
            key={e.sensor}
            className="breakdown-seg"
            style={{ width: `${(e.count / sum) * 100}%`, background: sensorColour(e.sensor) }}
          />
        ))}
      </div>

      <div className="breakdown-key">
        {entries.map((e) => (
          <span key={e.sensor} className={`breakdown-item${e.count === 0 ? " is-zero" : ""}`}>
            <span className="breakdown-swatch" style={{ background: sensorColour(e.sensor) }} />
            <span className="breakdown-count">{e.count}</span>
            <span>{e.sensor}</span>
          </span>
        ))}
      </div>
    </div>
  );
}
