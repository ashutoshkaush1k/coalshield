// Government sensor view: cross-mine risk, not per-mine performance.
import { getFleetSensors } from "../../../api/sensors";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { StateFilter } from "../../../components/common/StateFilter";
import { BreachBreakdown } from "../../../components/sensors/BreachBreakdown";
import { FleetRiskTable } from "../../../components/sensors/FleetRiskTable";
import { usePolling } from "../../../hooks/usePolling";

export function SensorRiskPanel({ state, states, onStateChange }) {
  // Same polling hook the Overview board uses, so the simulator's readings land here
  // without a second update mechanism.
  const { data, error, loading } = usePolling(() => getFleetSensors(state), {
    deps: [state],
  });

  if (loading && !data) return <Loader label="Reading current sensor status..." />;

  const fleetCounts = (data?.mines ?? []).reduce((acc, mine) => {
    for (const s of mine.sensors) acc[s.sensor_type] = (acc[s.sensor_type] ?? 0) + s.open_breaches;
    return acc;
  }, {});

  return (
    <div className="stack">
      <ErrorNotice error={error} context="The fleet sensor view is available to Government accounts only." />

      {data && (
        <>
          <section className="panel-block">
            <div className="panel-head">
              <div>
                <h2>Where {state ? state : "the fleet"} is breaching</h2>
                <span className="hint">
                  Open breaches across {state ? `${state}'s` : "all"} mines, by sensor type
                </span>
              </div>
              <div className="spacer" />
              <StateFilter states={states} value={state} onChange={onStateChange}
                           id="sensors-state-filter" />
            </div>
            <div className="panel-body">
              <BreachBreakdown counts={fleetCounts} />
            </div>
          </section>

          <section className="panel-block">
            <div className="panel-head">
              <h2>Current sensor status by mine</h2>
              <span className="hint">Latest reading per sensor. Select a mine for its full detail.</span>
            </div>
            <div className="panel-body">
              <FleetRiskTable fleet={data} />
            </div>
          </section>
        </>
      )}
    </div>
  );
}
