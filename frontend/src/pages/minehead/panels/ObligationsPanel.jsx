// Mine head: the mine's statutory obligation register - statutory compliance (a separate measure;
// the compliance score is unchanged), then due soon, overdue, open, submitted and recently accepted
// tasks, each with its citation. One request per polling cycle (GET /v1/views/obligations).
import { fmtNumber } from "../../../utils/format";
import { useState } from "react";
import { getObligationView } from "../../../api/obligations";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { OtherObligations } from "../../../components/obligations/OtherObligations";
import { TaskDrawer } from "../../../components/obligations/TaskDrawer";
import { TaskList } from "../../../components/obligations/TaskList";
import { usePolling } from "../../../hooks/usePolling";
import { useT } from "../../../i18n/t";

const SECTIONS = ["due_soon", "overdue", "open", "submitted", "accepted"];
const TITLE = { due_soon: "dueSoon", overdue: "overdue", open: "open", submitted: "submitted", accepted: "accepted" };

export function ObligationsPanel() {
  const t = useT();
  const [selected, setSelected] = useState(null);
  const [token, setToken] = useState(0);
  const { data, error, loading } = usePolling(() => getObligationView(), { deps: [token] });
  if (loading && !data) return <Loader label={t("obligation.loading")} />;
  if (!data) return <ErrorNotice error={error} />;
  const s = data.summary.totals;

  return (
    <div className="stack">
      <ErrorNotice error={error} />
      <section className="panel-block" id="obligation-summary">
        <div className="panel-head">
          <div>
            <h2>{t("obligation.mine.title")}</h2>
            <span className="hint">{t("obligation.mine.hint")}</span>
          </div>
        </div>
        <div className="panel-body">
          <div className="tally-set">
            <div><span className="label">{t("obligation.mine.compliance")}</span><span className="tally-v" id="statutory-pct">{fmtNumber(s.compliance_pct)} %</span></div>
            <div><span className="label">{t("obligation.gov.onTime")}</span><span className="tally-v">{fmtNumber(s.on_time)} / {fmtNumber(s.due)}</span></div>
            <div><span className="label">{t("obligation.gov.overdue")}</span><span className="tally-v risk-high">{fmtNumber(data.overdue.length)}</span></div>
            <div><span className="label">{t("obligation.gov.awaiting")}</span><span className="tally-v">{fmtNumber(data.submitted.length)}</span></div>
          </div>
          <p className="note">{t("obligation.mine.complianceHint", { days: data.summary.window_days })}</p>
        </div>
      </section>
      {SECTIONS.map((key) => (
        <section className="panel-block" key={key} id={`obligations-${key}`}>
          <div className="panel-head"><h2>{t(`obligation.mine.${TITLE[key]}`)} <span className="tag">{data[key].length}</span></h2></div>
          <div className="panel-body flush">
            <TaskList tasks={data[key]} onSelect={(task) => setSelected(task.id)} />
          </div>
        </section>
      ))}
      <OtherObligations />
      {selected && <TaskDrawer taskId={selected} onClose={() => setSelected(null)} onChanged={() => setToken((n) => n + 1)} />}
    </div>
  );
}
