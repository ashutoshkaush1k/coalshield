// Mine head: the contractors working at this mine, worst first, with their compliance, and the
// tools to register them, manage contracts, workers and monthly documents.
import { useState } from "react";
import { listContractors } from "../../../api/contractors";
import { can } from "../../../auth/permissions";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { ContractorDetail } from "../../../components/contractors/ContractorDetail";
import { RegisterContractorButton } from "../../../components/contractors/ContractorForms";
import { ContractorList } from "../../../components/contractors/ContractorList";
import { useAuth } from "../../../hooks/useAuth";
import { usePolling } from "../../../hooks/usePolling";
import { useT } from "../../../i18n/t";

export function ContractorsPanel({ mineId }) {
  const t = useT();
  const { user } = useAuth();
  const [selected, setSelected] = useState(null);
  const [token, setToken] = useState(0);
  const { data, error, loading } = usePolling(() => listContractors({ mine_id: mineId }), { deps: [mineId, token], interval: 15000 });
  if (loading && !data) return <Loader label={t("contractor.loading")} />;
  const flagged = (data ?? []).filter((c) => c.compliance.band === "flagged").length;

  return (
    <div className="stack">
      <ErrorNotice error={error} />
      <section className="panel-block">
        <div className="panel-head">
          <div>
            <h2>{t("contractor.titleMine")}</h2>
            <span className="hint">{t("contractor.hintMine", { count: data?.length ?? 0, flagged })}</span>
          </div>
          <div className="spacer" />
          {can(user, "contractor.manage") && <RegisterContractorButton onCreated={() => setToken((n) => n + 1)} />}
        </div>
        <div className="panel-body flush">
          <ContractorList contractors={data} onSelect={(c) => setSelected(c.id)} />
        </div>
        <div className="panel-body"><p className="note">{t("contractor.scoreNote")}</p></div>
      </section>
      {selected && <ContractorDetail contractorId={selected} onClose={() => setSelected(null)} onChanged={() => setToken((n) => n + 1)} />}
    </div>
  );
}
