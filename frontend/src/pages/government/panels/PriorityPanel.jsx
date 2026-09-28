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
import { t } from "../../../i18n/t";
import { fmtNumber } from "../../../utils/format";

export function PriorityPanel({ state, states, onStateChange }) {
  const { user } = useAuth();
  const { data, error, loading } = usePolling(() => getInspectionQueue({ state }), {
    deps: [state],
  });

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
    </div>
  );
}
