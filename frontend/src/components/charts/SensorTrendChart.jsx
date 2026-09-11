// One sensor's readings over time, with its safe limit - and it is live.
//
// "Live" here means the chart grows. A poll appends whatever readings are new and the window
// eases along to follow them; it never rebuilds the path, never re-runs an entry animation, and
// never re-flows the axis under the viewer. See useLiveSeries for why that distinction matters.
import { useMemo } from "react";
import {
  CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import { EmptyState } from "../common/EmptyState";
import { useLiveSeries, useSlidingDomain } from "../../hooks/useLiveSeries";
import { fmtTime } from "../../utils/format";
import { chartTokens, sensorColour } from "../../utils/tokens";

// Headroom above whatever is being plotted, so a reading sitting exactly on the limit still has
// the line drawn clear of the top edge.
const HEADROOM = 1.15;

/** Round up to a readable axis top, so the y scale settles on the same number between polls. */
function niceCeil(value) {
  if (!Number.isFinite(value) || value <= 0) return 1;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  return Math.ceil(value / (magnitude / 2)) * (magnitude / 2);
}

export function SensorTrendChart({ series, mineId = null }) {
  // Accumulated across polls, not re-derived from the latest response.
  const data = useLiveSeries(series?.points, {
    resetKey: `${mineId ?? ""}:${series?.sensor_type ?? ""}`,
  });

  const [first, last] = [data[0]?.ts, data[data.length - 1]?.ts];
  // A single reading has no width to plot against, so give the axis a minute of span to work
  // with; otherwise the domain is degenerate and the point lands on the edge.
  const span = data.length > 1 ? [first, last] : [first - 30_000, first + 30_000];
  const domain = useSlidingDomain(span[0], span[1]);

  // Pinned to the data's own ceiling rather than left to Recharts, which re-picks the scale each
  // time the numbers move. A y axis that rescales every five seconds makes a steady sensor look
  // like a volatile one.
  const yMax = useMemo(() => {
    const peak = data.reduce((m, d) => Math.max(m, d.value), series?.threshold ?? 0);
    return niceCeil(Math.max(peak, series?.threshold ?? 0) * HEADROOM);
  }, [data, series?.threshold]);

  if (!data.length) return <EmptyState>No readings yet.</EmptyState>;

  const t = chartTokens();
  const colour = sensorColour(series.sensor_type);

  return (
    <div style={{ width: "100%", height: 190 }}>
      <ResponsiveContainer>
        <LineChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: -18 }}>
          {/* Horizontal rules only. Vertical grid lines add ink without adding information,
              and on a scrolling window they would slide about and draw the eye. */}
          <CartesianGrid stroke={t.grid} vertical={false} />

          {/* A real time axis, not a row of formatted labels. Positioning points by their
              timestamp is what lets the window slide continuously - on a category axis every
              new reading re-partitions the width and the whole series shuffles sideways.
              allowDataOverflow keeps readings that have scrolled past the edge clipped, rather
              than letting them stretch the scale back out. */}
          <XAxis
            dataKey="ts"
            type="number"
            scale="time"
            domain={domain}
            allowDataOverflow
            tickFormatter={(ts) => fmtTime(new Date(ts).toISOString())}
            tick={{ fontSize: 10, fill: t.axis }}
            tickLine={false}
            axisLine={false}
            minTickGap={28}
          />
          <YAxis
            domain={[0, yMax]}
            allowDataOverflow
            tick={{ fontSize: 10, fill: t.axis }}
            tickLine={false}
            axisLine={false}
            width={44}
          />
          <Tooltip
            contentStyle={{
              fontSize: 12, borderRadius: 8, border: `1px solid ${t.line}`,
              background: t.surface, fontVariantNumeric: "tabular-nums",
            }}
            labelFormatter={(ts) => fmtTime(new Date(ts).toISOString())}
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
            // Stays off, and that is the whole design. Recharts' line animation is an entry
            // transition: on a data change it re-runs from scratch, so a five-second poll would
            // redraw the series on a loop. The movement you see comes from the domain easing
            // underneath a path that is never itself re-animated.
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
