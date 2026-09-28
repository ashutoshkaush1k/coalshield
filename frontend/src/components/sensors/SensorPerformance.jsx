// Mine Head sensor view, framed as performance: how is my site doing right now.
import { SensorTrendChart } from "../charts/SensorTrendChart";
import { EmptyState } from "../common/EmptyState";
import { fmtDateTime, fmtNumber, fmtReading } from "../../utils/format";
import { sensorColour } from "../../utils/tokens";
import { sensorLabel } from "../../i18n/labels";
import { t } from "../../i18n/t";

// Mirrors SensorStanding.status_label on the backend so operator and authority read the
// same words for the same condition.
function statusOf(series) {
  const latest = series.points?.[series.points.length - 1];
  const label = (code) => t(`sensor.status.${code}`);
  if (!latest) return { label: label("NO_READINGS"), tone: "ok", value: null };
  if (series.threshold == null) return { label: label("NO_LEGAL_LIMIT"), tone: "ok", value: latest };
  if (latest.breached) return { label: label("BREACHED"), tone: "breached", value: latest };
  if (latest.value >= series.threshold * 0.9) return { label: label("APPROACHING_LIMIT"), tone: "near", value: latest };
  return { label: label("WITHIN_RANGE"), tone: "ok", value: latest };
}

export function SensorPerformance({ trend }) {
  if (!trend?.series?.length) return <EmptyState>{t("sensor.perf.noReadings")}</EmptyState>;

  return (
    <div className="stack">
      {trend.series.map((series) => {
        const status = statusOf(series);
        return (
          <section key={series.sensor_type} className="panel-block">
            <div className="panel-head">
              <h2>{sensorLabel(series.sensor_type)}</h2>
              <span className="hint">
                {series.threshold != null
                  ? t("sensor.limit", { limit: fmtReading(series.threshold), unit: series.unit, obligation: series.obligation })
                  : t("sensor.noLimit")}
                {series.compare === "rolling_8h_mean" ? ` · ${t("sensor.compare.rolling_8h_mean")}` : ""}
              </span>
              <div className="spacer" />
              <span className={`status-pill status-${status.tone}`}>{status.label}</span>
            </div>

            <div className="panel-body">
              <div className="reading-row">
                <div>
                  <span className="label">{t("sensor.perf.current")}</span>
                  <div className="reading-value" style={{ color: sensorColour(series.sensor_type) }}>
                    {status.value ? fmtReading(status.value.value) : "-"}
                    <span className="reading-unit">{series.unit}</span>
                  </div>
                  {status.value && (
                    <div className="faint small">{t("sensor.perf.asOf", { when: fmtDateTime(status.value.recorded_at) })}</div>
                  )}
                </div>

                <div className="spacer" />

                <div>
                  <span className="label">{t("sensor.perf.breachesWindow")}</span>
                  <div className="reading-secondary">{fmtNumber(series.breach_count, 0)}</div>
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
