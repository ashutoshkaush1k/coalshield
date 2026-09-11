// Mine Head sensor view: how is my own site performing right now.
import { getTrend } from "../../../api/sensors";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { BreachBreakdown } from "../../../components/sensors/BreachBreakdown";
import { SensorPerformance } from "../../../components/sensors/SensorPerformance";
import { usePolling } from "../../../hooks/usePolling";

export function SensorPerformancePanel({ mineId }) {
  // Reuses the existing per-mine trend endpoint and the shared polling hook, so the
  // simulator's live readings show up here exactly as they do on the Overview board.
  const { data, error, loading } = usePolling(() => getTrend(mineId, 40), { deps: [mineId] });

  if (loading && !data) return <Loader label="Reading your sensors..." />;

  const counts = Object.fromEntries(
    (data?.series ?? []).map((s) => [s.sensor_type, s.breach_count])
  );

  return (
    <div className="stack">
      <ErrorNotice error={error} />

      {data && (
        <>
          <section className="panel-block">
            <div className="panel-head">
              <h2>Where your breaches are coming from</h2>
              <span className="hint">Breaches in this window, by sensor type</span>
            </div>
            <div className="panel-body">
              <BreachBreakdown counts={counts} />
            </div>
          </section>

          <SensorPerformance trend={data} />
        </>
      )}
    </div>
  );
}
