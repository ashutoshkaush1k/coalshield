// Government sensor view, framed as risk: which mines are breaching right now.
//
// Deliberately not the same question as the Overview board. A score reflects everything a
// mine has ever accrued; this answers "who needs a call in the next hour".
import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { EmptyState } from "../common/EmptyState";
import { BreachBreakdown, countsByCategory } from "./BreachBreakdown";
import { fmtDateTime, fmtNumber, fmtReading } from "../../utils/format";
import { sensorColour } from "../../utils/tokens";
import { sensorLabel } from "../../i18n/labels";
import { t } from "../../i18n/t";

// Severities come lower case from the API. Columns: every sensor type in rules.yaml.
const SEVERITY_RANK = { high: 0, medium: 1, low: 2, ok: 3 };
const SENSORS = ["ch4", "ch4_return_air", "dust", "temperature", "co", "humidity"];

const SORTS = {
  severity: { label: "sensor.fleet.sortSeverity", fn: (a, b) => SEVERITY_RANK[a.worst_severity] - SEVERITY_RANK[b.worst_severity] || b.breaching_now - a.breaching_now },
  history: { label: "sensor.fleet.sortHistory", fn: (a, b) => b.total_open_breaches - a.total_open_breaches },
  name: { label: "sensor.fleet.sortName", fn: (a, b) => a.name.localeCompare(b.name) },
};

function Cell({ sensor }) {
  if (!sensor) {
    return <td><div className="sensor-cell sensor-ok"><span className="sensor-status faint">{t("sensor.status.NOT_FITTED")}</span></div></td>;
  }
  const tone = sensor.breached ? "breached" : sensor.status_code === "APPROACHING_LIMIT" ? "near" : "ok";
  return (
    <td>
      <div className={`sensor-cell sensor-${tone}`}>
        <span className="sensor-value" style={{ color: sensorColour(sensor.sensor_type) }}>
          {sensor.value == null ? "-" : fmtReading(sensor.value)}
          <span className="sensor-unit">{sensor.unit}</span>
        </span>
        <span className="sensor-status">{t(`sensor.status.${sensor.status_code}`)}</span>
      </div>
    </td>
  );
}

export function FleetRiskTable({ fleet }) {
  const navigate = useNavigate();
  const [sortKey, setSortKey] = useState("severity");
  const [filter, setFilter] = useState("all");

  const rows = useMemo(() => {
    const mines = fleet?.mines ?? [];
    const filtered =
      filter === "breaching"
        ? mines.filter((m) => m.breaching_now > 0)
        : filter === "all"
          ? mines
          : mines.filter((m) => m.sensors.some((s) => s.sensor_type === filter && s.breached));
    return [...filtered].sort(SORTS[sortKey].fn);
  }, [fleet, sortKey, filter]);

  if (!fleet?.mines?.length) return <EmptyState>{t("sensor.fleet.noMines")}</EmptyState>;

  return (
    <div className="stack tight">
      <div className="row wrap filter-bar">
        <div>
          <label htmlFor="fleet-sort">{t("sensor.fleet.sortBy")}</label>
          <select id="fleet-sort" value={sortKey} onChange={(e) => setSortKey(e.target.value)}>
            {Object.entries(SORTS).map(([k, v]) => <option key={k} value={k}>{t(v.label)}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor="fleet-filter">{t("sensor.fleet.show")}</label>
          <select id="fleet-filter" value={filter} onChange={(e) => setFilter(e.target.value)}>
            <option value="all">{t("sensor.fleet.allMines")}</option>
            <option value="breaching">{t("sensor.fleet.breachingNow")}</option>
            {SENSORS.slice(0, 4).map((s) => <option key={s} value={s}>{t("sensor.fleet.breachingSensor", { sensor: sensorLabel(s) })}</option>)}
          </select>
        </div>
        <div className="spacer" />
        <span className="faint small">
          {t("sensor.fleet.breachingCount", { n: fmtNumber(fleet.breaching_mines, 0), total: fmtNumber(fleet.mine_count, 0) })}
        </span>
      </div>

      {rows.length === 0 ? (
        <EmptyState>{t("sensor.fleet.noMatch")}</EmptyState>
      ) : (
        <div className="scroll-x">
          <table>
            <thead>
              <tr>
                <th>{t("sensor.fleet.mine")}</th>
                {SENSORS.map((s) => <th key={s}>{sensorLabel(s)}</th>)}
                <th>{t("sensor.fleet.openByType")}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((mine) => {
                const byType = Object.fromEntries(
                  mine.sensors.map((s) => [s.sensor_type, s.open_breaches])
                );
                const latest = mine.sensors.map((s) => s.recorded_at).filter(Boolean).sort().pop();
                return (
                  <tr key={mine.mine_id} className="clickable"
                      onClick={() => navigate(`/gov/mines/${mine.mine_id}`)}>
                    <td>
                      <div className="fleet-mine">{mine.name}</div>
                      <div className="mono faint">{mine.code}</div>
                      {latest && <div className="faint small">{t("sensor.fleet.updated", { when: fmtDateTime(latest) })}</div>}
                    </td>
                    {SENSORS.map((s) => (
                      <Cell key={s} sensor={mine.sensors.find((x) => x.sensor_type === s)} />
                    ))}
                    <td style={{ minWidth: 200 }}>
                      <BreachBreakdown counts={countsByCategory(byType)} total={mine.total_open_breaches} compact />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
