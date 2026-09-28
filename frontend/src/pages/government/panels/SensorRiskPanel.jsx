// Government sensor view: cross-mine risk, not per-mine performance.
import { getFleetSensors } from "../../../api/sensors";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { StateFilter } from "../../../components/common/StateFilter";
import { BreachBreakdown, countsByCategory } from "../../../components/sensors/BreachBreakdown";
import { FleetRiskTable } from "../../../components/sensors/FleetRiskTable";
import { usePolling } from "../../../hooks/usePolling";
import { t } from "../../../i18n/t";

export function SensorRiskPanel({ state, states, onStateChange }) {
  // Same polling hook the Overview board uses, so the simulator's readings land here
  // without a second update mechanism.
  const { data, error, loading } = usePolling(() => getFleetSensors(state), {
    deps: [state],
  });

  if (loading && !data) return <Loader label={t("sensor.risk.loading")} />;

  const fleetCounts = countsByCategory((data?.mines ?? []).reduce((acc, mine) => {
    for (const s of mine.sensors) acc[s.sensor_type] = (acc[s.sensor_type] ?? 0) + s.open_breaches;
    return acc;
  }, {}));

  return (
    <div className="stack">
      <ErrorNotice error={error} context={t("sensor.risk.singleMine")} />

      {data && (
        <>
          <section className="panel-block">
            <div className="panel-head">
              <div>
                <h2>{state ? t("sensor.risk.titleState", { state }) : t("sensor.risk.titleFleet")}</h2>
                <span className="hint">{state ? t("sensor.risk.hintState", { state }) : t("sensor.risk.hintFleet")}</span>
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
              <h2>{t("sensor.risk.byMine")}</h2>
              <span className="hint">{t("sensor.risk.byMineHint")}</span>
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
