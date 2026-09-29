// Production charts (brief Phase 4): target vs actual per day with the anomaly days marked, the
// month-to-date cumulative totals, and the split by shift. Series come from the API's `charts`
// block (ProductionService::charts).
import {
  Bar, BarChart, CartesianGrid, ComposedChart, Legend, Line, LineChart, ReferenceDot, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import { useT } from "../../i18n/t";
import { chartTokens, token } from "../../utils/tokens";
import { EmptyState } from "../common/EmptyState";
import { fmtNum } from "./common";

const colours = () => ({
  target: token("--muted", "#6b7280"),
  actual: token("--accent-ink", "#0e7490"),
});

const axisProps = (c) => ({ tick: { fontSize: 10, fill: c.axis }, tickLine: false, axisLine: false });
const tooltipStyle = (c) => ({ fontSize: 12, borderRadius: 8, border: `1px solid ${c.line}`, background: c.surface, fontVariantNumeric: "tabular-nums" });
const dayTick = (d) => (d ? d.slice(8, 10) : "");
const tonnes = (v) => `${fmtNum(v)} t`;
const compact = (v) => (v >= 1000 ? `${fmtNum(v / 1000)}k` : fmtNum(v));

function Frame({ title, height = 200, children }) {
  return (
    <div className="chart-frame">
      <span className="label">{title}</span>
      <div style={{ width: "100%", height }}><ResponsiveContainer>{children}</ResponsiveContainer></div>
    </div>
  );
}

export function TargetVsActualChart({ charts }) {
  const t = useT();
  const c = chartTokens();
  const k = colours();
  if (!charts?.daily?.length) return <EmptyState>{t("production.charts.empty")}</EmptyState>;
  const flagged = new Set((charts.anomalies ?? []).map((a) => a.date));
  return (
    <Frame title={t("production.charts.targetVsActual")}>
      <LineChart data={charts.daily} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
        <CartesianGrid stroke={c.grid} vertical={false} />
        <XAxis dataKey="date" tickFormatter={dayTick} {...axisProps(c)} minTickGap={12} />
        <YAxis tickFormatter={compact} {...axisProps(c)} width={48} />
        <Tooltip contentStyle={tooltipStyle(c)} formatter={(v, name) => [tonnes(v), name]} />
        <Legend wrapperStyle={{ fontSize: 11 }} />
        <Line type="monotone" dataKey="target_t" name={t("production.charts.target")} stroke={k.target} strokeDasharray="4 4" dot={false} isAnimationActive={false} />
        <Line type="monotone" dataKey="actual_t" name={t("production.charts.actual")} stroke={k.actual} strokeWidth={2} dot={false} isAnimationActive={false} />
        {charts.daily.filter((d) => flagged.has(d.date)).map((d) => (
          <ReferenceDot key={d.date} x={d.date} y={d.actual_t} r={6} fill={c.riskHigh} stroke={c.surface}
                        label={{ value: t("production.charts.anomalyDay"), position: "top", fontSize: 10, fill: c.riskHigh }} />
        ))}
      </LineChart>
    </Frame>
  );
}

export function CumulativeChart({ charts }) {
  const t = useT();
  const c = chartTokens();
  const k = colours();
  if (!charts?.daily?.length) return null;
  return (
    <Frame title={t("production.charts.cumulative")}>
      <ComposedChart data={charts.daily} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
        <CartesianGrid stroke={c.grid} vertical={false} />
        <XAxis dataKey="date" tickFormatter={dayTick} {...axisProps(c)} minTickGap={12} />
        <YAxis tickFormatter={compact} {...axisProps(c)} width={48} />
        <Tooltip contentStyle={tooltipStyle(c)} formatter={(v, name) => [tonnes(v), name]} />
        <Legend wrapperStyle={{ fontSize: 11 }} />
        <Bar dataKey="cumulative_actual_t" name={t("production.charts.cumActual")} fill={k.actual} isAnimationActive={false} />
        <Line type="monotone" dataKey="cumulative_target_t" name={t("production.charts.cumTarget")} stroke={c.ink} strokeWidth={2} strokeDasharray="5 3" dot={false} isAnimationActive={false} />
      </ComposedChart>
    </Frame>
  );
}

export function ShiftSplitChart({ charts }) {
  const t = useT();
  const c = chartTokens();
  const k = colours();
  if (!charts?.shifts?.length) return null;
  const data = charts.shifts.map((s) => ({ ...s, label: t("production.shiftName", { shift: s.shift }) }));
  return (
    <Frame title={t("production.charts.shiftSplit")}>
      <BarChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
        <CartesianGrid stroke={c.grid} vertical={false} />
        <XAxis dataKey="label" {...axisProps(c)} />
        <YAxis tickFormatter={compact} {...axisProps(c)} width={48} />
        <Tooltip contentStyle={tooltipStyle(c)} formatter={(v, name) => [tonnes(v), name]} />
        <Legend wrapperStyle={{ fontSize: 11 }} />
        <Bar dataKey="target_t" name={t("production.charts.target")} fill={k.target} isAnimationActive={false} />
        <Bar dataKey="actual_t" name={t("production.charts.actual")} fill={k.actual} isAnimationActive={false} />
      </BarChart>
    </Frame>
  );
}

/** The three charts side by side (stacked on a narrow screen). */
export function ProductionCharts({ charts }) {
  return (
    <div className="chart-row">
      <TargetVsActualChart charts={charts} />
      <CumulativeChart charts={charts} />
      <ShiftSplitChart charts={charts} />
    </div>
  );
}
