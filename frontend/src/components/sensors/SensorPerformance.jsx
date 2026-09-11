// Mine Head sensor view, framed as performance: how is my site doing right now.
import { SensorTrendChart } from "../charts/SensorTrendChart";
import { EmptyState } from "../common/EmptyState";
import { fmtDateTime } from "../../utils/format";
import { sensorColour } from "../../utils/tokens";

// Mirrors SensorStanding.status_label on the backend so operator and authority read the
// same words for the same condition.
function statusOf(series) {
  const latest = series.points?.[series.points.length - 1];
  if (!latest) return { label: "No readings yet", tone: "ok", value: null };
  if (latest.breached) return { label: "Breached", tone: "breached", value: latest };
  if (series.threshold && latest.value >= series.threshold * 0.9) {
    return { label: "Approaching limit", tone: "near", value: latest };
  }
  return { label: "Within safe range", tone: "ok", value: latest };
}

export function SensorPerformance({ trend }) {
  if (!trend?.series?.length) return <EmptyState>No sensor readings yet.</EmptyState>;

  return (
    <div className="stack">
      {trend.series.map((series) => {
        const status = statusOf(series);
        return (
          <section key={series.sensor_type} className="panel-block">
            <div className="panel-head">
              <h2 style={{ textTransform: "capitalize" }}>{series.sensor_type}</h2>
              <span className="hint">
                Safe limit {series.threshold}{series.unit}
              </span>
              <div className="spacer" />
              <span className={`status-pill status-${status.tone}`}>{status.label}</span>
            </div>

            <div className="panel-body">
              <div className="reading-row">
                <div>
                  <span className="label">Current reading</span>
                  <div className="reading-value" style={{ color: sensorColour(series.sensor_type) }}>
                    {status.value ? `${status.value.value}` : "-"}
                    <span className="reading-unit">{series.unit}</span>
                  </div>
                  {status.value && (
                    <div className="faint small">as of {fmtDateTime(status.value.recorded_at)}</div>
                  )}
                </div>

                <div className="spacer" />

                <div>
                  <span className="label">Breaches in this window</span>
                  <div className="reading-secondary">{series.breach_count}</div>
                </div>
              </div>

              <SensorTrendChart series={series} mineId={trend.mine_id} />
            </div>
          </section>
        );
      })}
    </div>
  );
}
