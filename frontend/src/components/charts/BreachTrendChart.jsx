// Breach frequency over time, split by sensor category - "where is risk accelerating,
// and what kind of risk", as distinct from the static scores on the Overview board.
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { BREACH_CATEGORIES } from "../../api/sensors";
import { EmptyState } from "../common/EmptyState";
import { chartTokens, token } from "../../utils/tokens";

/**
 * Segment colours are the same per-sensor identities the line charts use, so gas means
 * the same colour wherever it appears.
 *
 * No risk colour here: a breach count is a measurement, not a band, and red in
 * particular must keep meaning HIGH risk wherever it shows up.
 */
const CATEGORY_TOKEN = {
  gas: ["--sensor-gas", "#6366f1"],
  dust: ["--sensor-dust", "#0ea5e9"],
  temperature: ["--sensor-temperature", "#f97316"],
};

const LABEL = { gas: "Gas", dust: "Dust", temperature: "Temperature" };

export const categoryColour = (category) => {
  const [name, fallback] = CATEGORY_TOKEN[category] ?? ["--muted", "#6b7280"];
  return token(name, fallback);
};

export function BreachLegend() {
  return (
    <div className="chart-legend">
      {BREACH_CATEGORIES.map((category) => (
        <span key={category} className="chart-legend-item">
          <span className="chart-legend-swatch" style={{ background: categoryColour(category) }} />
          {LABEL[category]}
        </span>
      ))}
    </div>
  );
}

/** Breakdown by category plus the total, so the parts and the bar height reconcile. */
function CategoryTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null;
  const bucket = payload[0].payload;

  return (
    <div className="chart-tooltip">
      <div className="chart-tooltip-title">{label}</div>
      <table>
        <tbody>
          {BREACH_CATEGORIES.map((category) => (
            <tr key={category} className={bucket[category] ? "" : "is-zero"}>
              <td>
                <span className="chart-legend-swatch"
                      style={{ background: categoryColour(category) }} />
                {LABEL[category]}
              </td>
              <td className="num">{bucket[category] ?? 0}</td>
            </tr>
          ))}
          <tr className="is-total">
            <td>Total</td>
            <td className="num">
              {bucket.breaches} breach{bucket.breaches === 1 ? "" : "es"}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

export function BreachTrendChart({ buckets, height = 260 }) {
  if (!buckets?.length) return <EmptyState>No sensor readings in this window yet.</EmptyState>;

  const { ink, muted: faint, grid, surface } = chartTokens();

  return (
    <div style={{ width: "100%", height }}>
      <ResponsiveContainer>
        <BarChart data={buckets} margin={{ top: 8, right: 8, bottom: 0, left: -20 }}>
          <CartesianGrid stroke={grid} vertical={false} />
          <XAxis dataKey="label" tick={{ fontSize: 11, fill: faint }} tickLine={false}
                 axisLine={false} minTickGap={16} />
          <YAxis tick={{ fontSize: 11, fill: faint }} tickLine={false} axisLine={false}
                 width={44} allowDecimals={false} />
          <Tooltip
            cursor={{ fill: grid, fillOpacity: 0.5 }}
            wrapperStyle={{ outline: "none" }}
            content={<CategoryTooltip />}
          />

          {/* One stackId, so the segments sum to the same bar height the single
              aggregated bar had. The hairline between segments is the surface colour,
              which keeps adjacent tones readable without adding a fourth colour. */}
          {BREACH_CATEGORIES.map((category) => (
            <Bar
              key={category}
              dataKey={category}
              stackId="breaches"
              fill={categoryColour(category)}
              stroke={surface}
              strokeWidth={1}
              isAnimationActive={false}
              maxBarSize={46}
            />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
