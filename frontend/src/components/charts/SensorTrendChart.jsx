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
import { chartTokens, sensorColour } from "../../utils/tokens";

// Headroom above whatever is being plotted, so a reading sitting exactly on the limit still has
// the line drawn clear of the top edge.
const HEADROOM = 1.15;

// Readings visible at once. The accumulated series is wider than this, so once a mine has
// reported more than a windowful the oldest readings scroll off the left rather than the whole
// series being squeezed into the same pixels.
const VISIBLE_POINTS = 40;

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

  // The window is the last VISIBLE_POINTS of the sequence. Each new reading advances both ends
  // by one, and useSlidingDomain eases that step, which is the scroll.
  const newest = data.length ? data[data.length - 1].x : 0;
  const oldest = data.length ? data[0].x : 0;
  const from = Math.max(oldest, newest - VISIBLE_POINTS + 1);
  // A single reading has no width to plot against, so give the axis half a step either side;
  // otherwise the domain is degenerate and the point lands on the edge.
  const domain = useSlidingDomain(
    data.length > 1 ? from : newest - 0.5,
    data.length > 1 ? newest : newest + 0.5,
  );

  // Ticks fall on sequence numbers, so they need the reading's real clock time to label with.
  const clock = useMemo(() => new Map(data.map((d) => [d.x, d.ts])), [data]);

  // Live readings arrive several to the minute, and a minutes-only label then prints the same
  // time on two neighbouring ticks - which reads as a rendering fault rather than as a sensor
  // reporting quickly. Seconds are added only when the window actually contains a collision, so
  // the seeded history, which is hours apart, keeps the shorter label.
  const tickLabel = useMemo(() => {
    const minutes = data.map((d) => Math.floor(d.ts / 60_000));
    const opts =
      new Set(minutes).size < minutes.length
        ? { hour: "2-digit", minute: "2-digit", second: "2-digit" }
        : { hour: "2-digit", minute: "2-digit" };
    return (x) => {
      const ts = clock.get(Math.round(x));
      return ts ? new Date(ts).toLocaleTimeString([], opts) : "";
    };
  }, [data, clock]);

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

          {/* Numeric, not categorical, and that is what lets the window slide: a category axis
              re-partitions its width every time a reading lands, so the entire series shuffles
              sideways in one step. Points sit on their sequence number and are labelled with
              their real clock time - see useLiveSeries for why sequence and not timestamp.
              allowDataOverflow keeps readings that have scrolled past the edge clipped, rather
              than letting them stretch the scale back out. */}
          <XAxis
            dataKey="x"
            type="number"
            domain={domain}
            allowDataOverflow
            tickFormatter={tickLabel}
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
            labelFormatter={tickLabel}
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
