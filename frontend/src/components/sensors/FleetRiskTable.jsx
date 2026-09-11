// Government sensor view, framed as risk: which mines are breaching right now.
//
// Deliberately not the same question as the Overview board. A score reflects everything a
// mine has ever accrued; this answers "who needs a call in the next hour".
import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { EmptyState } from "../common/EmptyState";
import { BreachBreakdown } from "./BreachBreakdown";
import { fmtDateTime } from "../../utils/format";
import { sensorColour } from "../../utils/tokens";

const SEVERITY_RANK = { HIGH: 0, MEDIUM: 1, LOW: 2, OK: 3 };
const SENSORS = ["gas", "dust", "temperature"];

const SORTS = {
  severity: { label: "Breach severity", fn: (a, b) => SEVERITY_RANK[a.worst_severity] - SEVERITY_RANK[b.worst_severity] || b.breaching_now - a.breaching_now },
  history: { label: "Breach history", fn: (a, b) => b.total_open_breaches - a.total_open_breaches },
  name: { label: "Mine name", fn: (a, b) => a.name.localeCompare(b.name) },
};

function Cell({ sensor }) {
  const tone = sensor.breached ? "breached" : sensor.status_label === "Approaching limit" ? "near" : "ok";
  return (
    <td>
      <div className={`sensor-cell sensor-${tone}`}>
        <span className="sensor-value" style={{ color: sensorColour(sensor.sensor_type) }}>
          {sensor.value == null ? "-" : sensor.value}
          <span className="sensor-unit">{sensor.unit}</span>
        </span>
        <span className="sensor-status">{sensor.status_label}</span>
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

  if (!fleet?.mines?.length) return <EmptyState>No mines visible.</EmptyState>;

  return (
    <div className="stack tight">
      <div className="row wrap filter-bar">
        <div>
          <label htmlFor="fleet-sort">Sort by</label>
          <select id="fleet-sort" value={sortKey} onChange={(e) => setSortKey(e.target.value)}>
            {Object.entries(SORTS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor="fleet-filter">Show</label>
          <select id="fleet-filter" value={filter} onChange={(e) => setFilter(e.target.value)}>
            <option value="all">All mines</option>
            <option value="breaching">Breaching now</option>
            {SENSORS.map((s) => <option key={s} value={s}>Breaching {s}</option>)}
          </select>
        </div>
        <div className="spacer" />
        <span className="faint small">
          {fleet.breaching_mines} of {fleet.mine_count} mines breaching right now
        </span>
      </div>

      {rows.length === 0 ? (
        <EmptyState>No mines match that filter.</EmptyState>
      ) : (
        <div className="scroll-x">
          <table>
            <thead>
              <tr>
                <th>Mine</th>
                <th>Gas</th>
                <th>Dust</th>
                <th>Temperature</th>
                <th>Open breaches by type</th>
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
                      {latest && <div className="faint small">updated {fmtDateTime(latest)}</div>}
                    </td>
                    {SENSORS.map((s) => (
                      <Cell key={s} sensor={mine.sensors.find((x) => x.sensor_type === s)} />
                    ))}
                    <td style={{ minWidth: 200 }}>
                      <BreachBreakdown counts={byType} total={mine.total_open_breaches} compact />
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
