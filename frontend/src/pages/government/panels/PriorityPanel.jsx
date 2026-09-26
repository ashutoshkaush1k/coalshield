// Ranked inspection queue. Ranking comes from the API; this is presentation only.
import { getInspectionQueue } from "../../../api/inspections";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { StateFilter } from "../../../components/common/StateFilter";
import { DemoTag } from "../../../components/common/DemoTag";
import { Loader } from "../../../components/common/Loader";
import { PriorityQueue } from "../../../components/inspections/PriorityQueue";
import { usePolling } from "../../../hooks/usePolling";
import { useAuth } from "../../../hooks/useAuth";
import { scopeWhere } from "../../../i18n/labels";

export function PriorityPanel({ state, states, onStateChange }) {
  const { user } = useAuth();
  const { data, error, loading } = usePolling(() => getInspectionQueue({ state }), {
    deps: [state],
  });

  if (loading && !data) return <Loader label="Ranking mines..." />;

  return (
    <div className="stack">
      <ErrorNotice error={error} context="The inspection queue compares mines, so it is not available to a single-mine account." />

      {data && (
        <section className="panel-block">
          <div className="panel-head">
            <div>
              <h2>
                {data.mine_count} mines ranked{state ? ` in ${state}` : ` ${scopeWhere(user)}`} <DemoTag />
              </h2>
              <span className="hint">
                Urgency combines current score with the rise in events over the last{" "}
                {data.trend_window_hours} hours
              </span>
            </div>
            <div className="spacer" />
            <StateFilter states={states} value={state} onChange={onStateChange}
                         id="priority-state-filter" />
          </div>
          <div className="panel-body flush">
            <PriorityQueue candidates={data.candidates} />
          </div>
        </section>
      )}
    </div>
  );
}
