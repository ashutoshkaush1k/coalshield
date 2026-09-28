// Ranked inspection queue (ordered by the Governance Risk Index, Phase 7) and the patterns the
// detectors found across the mines in scope. One request: GET /v1/views/priority.
import { getPriorityView } from "../../../api/views";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { StateFilter } from "../../../components/common/StateFilter";
import { DemoTag } from "../../../components/common/DemoTag";
import { Loader } from "../../../components/common/Loader";
import { PriorityQueue } from "../../../components/inspections/PriorityQueue";
import { Patterns } from "../../../components/risk/RiskPanel";
import { usePolling } from "../../../hooks/usePolling";
import { useAuth } from "../../../hooks/useAuth";
import { scopeWhere } from "../../../i18n/labels";
import { t } from "../../../i18n/t";
import { fmtNumber } from "../../../utils/format";

export function PriorityPanel({ state, states, onStateChange }) {
  const { user } = useAuth();
  const { data: view, error, loading } = usePolling(() => getPriorityView(state), {
    deps: [state],
  });
  const data = view?.queue;

  if (loading && !data) return <Loader label={t("priority.loading")} />;

  return (
    <div className="stack">
      <ErrorNotice error={error} context={t("priority.singleMine")} />

      {data && (
        <section className="panel-block">
          <div className="panel-head">
            <div>
              <h2>
                {state
                  ? t("priority.rankedState", { n: fmtNumber(data.mine_count, 0), state })
                  : t("priority.rankedScope", { n: fmtNumber(data.mine_count, 0), where: scopeWhere(user) })} <DemoTag />
              </h2>
              <span className="hint">
                {t("priority.hint", { hours: fmtNumber(data.trend_window_hours, 0) })}
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

      {view && <Patterns patterns={view.patterns} showMine id="fleet-patterns" />}
    </div>
  );
}
