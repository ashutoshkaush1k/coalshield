// Gas/dust/temperature time series with threshold lines.
import {
  CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import { EmptyState } from "../common/EmptyState";
import { fmtTime } from "../../utils/format";
import { chartTokens, sensorColour, token } from "../../utils/tokens";

export function SensorTrendChart({ series }) {
  if (!series?.points?.length) return <EmptyState>No readings yet.</EmptyState>;

  const t = chartTokens();
  const colour = sensorColour(series.sensor_type);
  const data = series.points.map((p) => ({
    t: fmtTime(p.recorded_at),
    value: p.value,
    breached: p.breached,
  }));

  return (
    <div style={{ width: "100%", height: 190 }}>
      <ResponsiveContainer>
        <LineChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: -18 }}>
          {/* Horizontal rules only. Vertical grid lines add ink without adding information. */}
          <CartesianGrid stroke={t.grid} vertical={false} />
          <XAxis dataKey="t" tick={{ fontSize: 10, fill: t.axis }} tickLine={false}
                 axisLine={false} minTickGap={24} />
          <YAxis tick={{ fontSize: 10, fill: t.axis }} tickLine={false} axisLine={false} width={44} />
          <Tooltip
            contentStyle={{
              fontSize: 12, borderRadius: 8, border: `1px solid ${t.line}`,
              background: t.surface, fontVariantNumeric: "tabular-nums",
            }}
            formatter={(v, _n, item) => [
              `${v} ${series.unit}${item?.payload?.breached ? "  (breach)" : ""}`,
              series.sensor_type,
            ]}
          />

          {/* The threshold is the point of the chart: a reading means nothing to a viewer
              without the limit it is being measured against. Risk colour is used here, and
              only here, because crossing this line is what "bad" means. */}
          <ReferenceLine
            y={series.threshold}
            stroke={t.riskHigh}
            strokeDasharray="4 4"
            label={{
              value: `limit ${series.threshold}${series.unit}`,
              position: "insideTopRight", fontSize: 10, fill: t.riskHigh,
            }}
          />

          <Line
            type="monotone"
            dataKey="value"
            stroke={colour}
            strokeWidth={2}
            // Only breaches get a marker. Dotting every compliant reading would bury the
            // exceptions in noise.
            //
            // The marker is filled in the sensor's own colour rather than a generic red,
            // so a breach carries its category: on a page showing three charts, or a
            // combined view, you can tell a gas exceedance from a dust one without
            // reading the axis. The dark ring is what makes it read as an exception.
            dot={(props) =>
              props.payload.breached ? (
                <circle key={props.key} cx={props.cx} cy={props.cy} r={4.5}
                        fill={colour} stroke={t.ink} strokeWidth={2} />
              ) : null
            }
            activeDot={{ r: 4 }}
            // A 5-second polling dashboard that re-animates on every refresh looks broken.
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
