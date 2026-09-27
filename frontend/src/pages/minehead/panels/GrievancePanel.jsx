// Mine head: the grievances of the own mine, most urgent first, with the detail drawer to work
// them (acknowledge, investigate, resolve with a note, assign). Sensitive grievances are routed to
// the regulator and never reach this screen - the API does not send them (AccessRule).
import { useState } from "react";
import { getGrievanceView } from "../../../api/grievances";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { GrievanceDetail } from "../../../components/grievances/GrievanceDetail";
import { GrievanceList } from "../../../components/grievances/GrievanceList";
import { usePolling } from "../../../hooks/usePolling";
import { useT } from "../../../i18n/t";

export function GrievancePanel() {
  const t = useT();
  const [selected, setSelected] = useState(null);
  const [token, setToken] = useState(0);
  const { data, error, loading } = usePolling(() => getGrievanceView(), { deps: [token] });
  if (loading && !data) return <Loader label={t("grievance.loading")} />;
  const grievances = data?.grievances ?? [];
  const open = grievances.filter((g) => g.is_open).length;

  return (
    <div className="stack">
      <ErrorNotice error={error} />
      <section className="panel-block" id="grievance-queue">
        <div className="panel-head">
          <div>
            <h2>{t("grievance.queue.title")} <span className="tag">{t("grievance.queue.open", { count: open })}</span></h2>
            <span className="hint">{t("grievance.queue.hint")}</span>
          </div>
        </div>
        <div className="panel-body flush">
          <GrievanceList grievances={grievances} onSelect={(g) => setSelected(g.id)} />
        </div>
      </section>
      {selected && <GrievanceDetail grievanceId={selected} onClose={() => setSelected(null)} onChanged={() => setToken((n) => n + 1)} />}
    </div>
  );
}
