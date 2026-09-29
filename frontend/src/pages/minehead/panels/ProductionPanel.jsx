// Mine head: daily production (brief Phase 4). The month's charts (target vs actual, cumulative,
// shift split), the shift entries with their status and corrections, a form per shift (draft,
// submit, correct with a reason), and the inbox of calls for detailed report with the response
// form. One request per polling cycle (GET /v1/views/production).
import { useState } from "react";
import { getProductionView } from "../../../api/production";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { RespondButton } from "../../../components/production/DetailRequestForms";
import { EntryForm } from "../../../components/production/EntryForm";
import { EntryTable } from "../../../components/production/ProductionDetailDrawer";
import { ProductionCharts } from "../../../components/production/ProductionCharts";
import { RequestList } from "../../../components/production/RequestList";
import { anomalyLines, monthLabel } from "../../../components/production/common";
import { usePolling } from "../../../hooks/usePolling";
import { useT } from "../../../i18n/t";

const AWAITING = ["pending", "overdue", "escalated"];

export function ProductionPanel() {
  const t = useT();
  const [month, setMonth] = useState(null);   // null: the current month, as the API decides
  const [token, setToken] = useState(0);
  const [editing, setEditing] = useState(null); // {entry} or {entry: null} for a new one
  const { data, error, loading } = usePolling(() => getProductionView(month), { deps: [month, token] });
  const refresh = () => setToken((n) => n + 1);
  if (loading && !data) return <Loader label={t("production.loading")} />;
  if (!data) return <ErrorNotice error={error} />;

  const awaiting = data.requests.filter((r) => AWAITING.includes(r.status));
  const others = data.requests.filter((r) => !AWAITING.includes(r.status));

  return (
    <div className="stack">
      <ErrorNotice error={error} />

      <section className="panel-block" id="production-inbox">
        <div className="panel-head">
          <div>
            <h2>{t("detailRequest.inboxTitle")}</h2>
            <span className="hint">{t("detailRequest.inboxHint")}</span>
          </div>
        </div>
        <div className="panel-body flush">
          <RequestList requests={[...awaiting, ...others]}
                       actions={(r) => (AWAITING.includes(r.status) ? <RespondButton request={r} onDone={refresh} /> : null)} />
        </div>
      </section>

      <section className="panel-block">
        <div className="panel-head wrap">
          <div>
            <h2>{t("production.tabLabel")}: {monthLabel(data.month)}</h2>
            <span className="hint">{t("production.entry.hint")}</span>
          </div>
          <div className="row head-controls">
            <label htmlFor="production-month" className="label">{t("production.entries.month")}</label>
            <input id="production-month" type="month" value={data.month} max={data.today.slice(0, 7)}
                   onChange={(e) => e.target.value && setMonth(e.target.value)} />
            <button className="primary" type="button" id="production-new" onClick={() => setEditing({ entry: null })}>{t("production.entry.new")}</button>
          </div>
        </div>
        <div className="panel-body">
          {data.charts.anomalies.length > 0 && (
            <div className="notice error">{data.charts.anomalies.flatMap(anomalyLines).map((line) => <div key={line}>{line}</div>)}</div>
          )}
          <ProductionCharts charts={data.charts} />
          <p className="note">{t("production.anomaly.rule")}</p>
        </div>
      </section>

      <section className="panel-block">
        <div className="panel-head">
          <div><h2>{t("production.entries.title")}</h2></div>
        </div>
        <div className="panel-body flush">
          {data.entries.length
            ? <EntryTable entries={data.entries} actions={(e) => (
                <button type="button" className="small-btn" onClick={() => setEditing({ entry: e })}>
                  {e.status === "draft" ? t("production.entry.editDraft") : t("production.entry.correct")}
                </button>
              )} />
            : <p className="empty">{t("production.entries.empty")}</p>}
        </div>
      </section>

      <EntryForm open={editing !== null} entry={editing?.entry ?? null} date={data.today}
                 onClose={() => setEditing(null)} onSaved={refresh} />
    </div>
  );
}
