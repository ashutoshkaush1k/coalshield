// Breach counts split by sensor category, so "this mine's problem is mostly gas, not
// dust" is readable at a glance rather than inferred from three separate charts.
import { EmptyState } from "../common/EmptyState";
import { sensorColour } from "../../utils/tokens";
import { t } from "../../i18n/t";
import { fmtNumber } from "../../utils/format";

// Breach counts per sensor type -> per chart category (the two methane readings are "gas").
const CATEGORY_OF = { ch4: "gas", ch4_return_air: "gas", co: "gas", dust: "dust", temperature: "temperature" };
export const countsByCategory = (bySensorType) =>
  Object.entries(bySensorType ?? {}).reduce((acc, [type, n]) => {
    const c = CATEGORY_OF[type] ?? type;
    acc[c] = (acc[c] ?? 0) + (n ?? 0);
    return acc;
  }, {});

const ORDER = ["gas", "dust", "temperature"];

export function BreachBreakdown({ counts, total, compact = false }) {
  const entries = ORDER.map((s) => ({ sensor: s, count: counts?.[s] ?? 0 }));
  const sum = total ?? entries.reduce((n, e) => n + e.count, 0);

  if (!sum) return compact ? <span className="faint small">{t("sensor.noBreaches")}</span>
                           : <EmptyState>{t("sensor.noBreachesRecorded")}</EmptyState>;

  return (
    <div className={`breakdown${compact ? " is-compact" : ""}`}>
      {/* One stacked bar rather than three: the question is proportion, not magnitude. */}
      <div className="breakdown-bar" role="img"
           aria-label={entries.map((e) => `${fmtNumber(e.count, 0)} ${t(`sensor.category.${e.sensor}`)}`).join(", ")}>
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
            <span className="breakdown-count">{fmtNumber(e.count, 0)}</span>
            <span>{t(`sensor.category.${e.sensor}`)}</span>
          </span>
        ))}
      </div>
    </div>
  );
}
