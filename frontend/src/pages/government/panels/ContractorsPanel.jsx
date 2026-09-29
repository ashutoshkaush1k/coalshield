// Government / corporate: read-only contractor view - the per-mine summary (count, compliance %,
// flagged, blacklisted), the flagged contractors worst first, and every contractor in scope.
import { fmtNumber } from "../../../utils/format";
import { useState } from "react";
import { listContractors } from "../../../api/contractors";
import { EmptyState } from "../../../components/common/EmptyState";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { StateFilter } from "../../../components/common/StateFilter";
import { ContractorDetail } from "../../../components/contractors/ContractorDetail";
import { ContractorList } from "../../../components/contractors/ContractorList";
import { useLinkedId } from "../../../hooks/useLinkedId";
import { usePolling } from "../../../hooks/usePolling";
import { contractorReason } from "../../../i18n/contractors";
import { useT } from "../../../i18n/t";

/** `data` is GET /v1/contractors/summary, delivered with the overview (GET /v1/views/overview). */
export function ContractorSummaryCard({ data, onOpen }) {
  const t = useT();
  if (!data) return null;
  return (
    <section className="panel-block">
      <div className="panel-head">
        <div>
          <h2>{t("contractor.summaryTitle")}</h2>
          <span className="hint">{t("contractor.summaryHint", { contractors: data.contractor_count, mines: data.mine_count })}</span>
        </div>
        <div className="spacer" />
        {onOpen && <button type="button" onClick={onOpen}>{t("contractor.openAll")}</button>}
      </div>
      <div className="panel-body">
        <div className="tally-set">
          <div><span className="label">{t("contractor.flagged")}</span><span className="tally-v risk-high">{fmtNumber(data.flagged)}</span></div>
          <div><span className="label">{t("contractor.blacklisted")}</span><span className="tally-v">{fmtNumber(data.blacklisted)}</span></div>
          <div><span className="label">{t("contractor.minesWithFlags")}</span><span className="tally-v">{data.mines.filter((m) => m.flagged).length}</span></div>
        </div>
        {data.flagged_contractors.length > 0 && (
          <ol className="flagged-list">
            {data.flagged_contractors.slice(0, 3).map((f) => (
              <li key={f.contractor_id}>
                <strong>{f.name}</strong> &middot; {f.mines.map((m) => m.name).join(", ")}
                {" "}&middot; <span className="risk-high">{f.score}</span>
                <div className="small muted">{f.reasons.slice(0, 2).map(contractorReason).join(" · ")}</div>
              </li>
            ))}
          </ol>
        )}
      </div>
    </section>
  );
}

/** `summary` comes with the overview poll; only the contractor list is fetched here. */
export function ContractorsPanel({ summary, state, states, onStateChange }) {
  const t = useT();
  const [selected, setSelected] = useState(null);
  // A contractor named in the URL (from the search panel) opens its detail.
  const linked = useLinkedId("contractor");
  const shown = selected ?? (linked.value ? Number(linked.value) : null);
  const list = usePolling(() => listContractors({ per_page: 200 }), { interval: 30000 });
  if (list.loading && !list.data) return <Loader label={t("contractor.loading")} />;
  const s = summary;

  return (
    <div className="stack">
      <ErrorNotice error={list.error} />
      <ContractorSummaryCard data={summary} />
      <section className="panel-block">
        <div className="panel-head">
          <div>
            <h2>{t("contractor.perMine")}</h2>
            <span className="hint">{t("contractor.perMineHint")}</span>
          </div>
          <div className="spacer" />
          <StateFilter states={states} value={state} onChange={onStateChange} id="contractor-state-filter" />
        </div>
        <div className="panel-body flush scroll-x">
          {s?.mines?.length ? (
            <table>
              <thead><tr><th>{t("contractor.mine")}</th><th className="num">{t("contractor.count")}</th><th className="num">{t("contractor.compliancePct")}</th>
                <th className="num">{t("contractor.flagged")}</th><th className="num">{t("contractor.blacklisted")}</th></tr></thead>
              <tbody>
                {s.mines.map((m) => (
                  <tr key={m.mine_id} className={m.flagged ? "is-flagged" : ""}>
                    <td><strong>{m.name}</strong> <span className="mono faint">{m.code}</span><div className="faint small">{m.state}</div></td>
                    <td className="num">{fmtNumber(m.contractors)}</td>
                    <td className="num">{fmtNumber(m.compliance_pct)}%</td>
                    <td className={`num${m.flagged ? " risk-high" : ""}`}>{m.flagged}</td>
                    <td className="num">{fmtNumber(m.blacklisted)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : <EmptyState>{t("contractor.empty")}</EmptyState>}
        </div>
      </section>
      <section className="panel-block">
        <div className="panel-head">
          <div><h2>{t("contractor.allTitle")}</h2><span className="hint">{t("contractor.allHint")}</span></div>
        </div>
        <div className="panel-body flush">
          <ContractorList contractors={list.data} onSelect={(c) => setSelected(c.id)} />
        </div>
      </section>
      {shown && <ContractorDetail contractorId={shown} onClose={() => { setSelected(null); linked.clear(); }} />}
    </div>
  );
}
