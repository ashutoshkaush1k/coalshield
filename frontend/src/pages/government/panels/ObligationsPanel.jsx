// Government / corporate / inspector: statutory compliance across the mines in scope - per
// company, by domain (four equal cards), per mine (lowest first, one full-width table), the most
// overdue items with their citations, and the evidence awaiting review (government and inspector
// accept or reject it in the drawer).
// A separate metric: the compliance score does not change. One request per polling cycle.
import { fmtNumber } from "../../../utils/format";
import { useState } from "react";
import { getObligationView } from "../../../api/obligations";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { StateFilter } from "../../../components/common/StateFilter";
import { OtherObligations } from "../../../components/obligations/OtherObligations";
import { TaskDrawer } from "../../../components/obligations/TaskDrawer";
import { TaskList } from "../../../components/obligations/TaskList";
import { useLinkedId } from "../../../hooks/useLinkedId";
import { ObligationDrawer } from "../../../components/obligations/ObligationDrawer";
import { usePolling } from "../../../hooks/usePolling";
import { useT } from "../../../i18n/t";

/** First cell: a mine (name on one line, its code beneath) or a company (code, then its name). */
function Row({ label, sub, subIsCode = false, r }) {
  return (
    <>
      <td>
        <span className="mine-cell">
          <span className="mine-name">{label}</span>
          {sub && <span className={subIsCode ? "mine-code" : "faint small"}>{sub}</span>}
        </span>
      </td>
      <td className="num">{fmtNumber(r.compliance_pct)} %</td>
      <td className="num">{fmtNumber(r.due)}</td>
      <td className="num">{fmtNumber(r.on_time)}</td>
      <td className="num">{fmtNumber(r.late_accepted)}</td>
      <td className="num">{fmtNumber(r.awaiting_review)}</td>
      <td className="num">{fmtNumber(r.overdue)}</td>
      <td className="num">{fmtNumber(r.escalated)}</td>
    </>
  );
}

/** One domain as a summary card: compliance with a progress bar, then its counts. */
function DomainCard({ d }) {
  const t = useT();
  return (
    <section className="panel-block fill span-3 stat-card" data-domain={d.domain}>
      <div className="panel-head"><div><h2>{t(`obligation.domain.${d.domain}`)}</h2></div></div>
      <div className="panel-body stack tight">
        <div>
          <span className="label">{t("obligation.gov.pct")}</span>
          <span className="tally-v">{fmtNumber(d.compliance_pct)} %</span>
          <div className="meter" style={{ marginTop: "var(--space-2)" }}><i className="progress" style={{ width: `${Math.max(0, Math.min(100, d.compliance_pct))}%` }} /></div>
        </div>
        <dl className="figure-list">
          <dt>{t("obligation.gov.due")}</dt><dd>{fmtNumber(d.due)}</dd>
          <dt>{t("obligation.gov.onTime")}</dt><dd>{fmtNumber(d.on_time)}</dd>
          <dt>{t("obligation.gov.late")}</dt><dd>{fmtNumber(d.late_accepted)}</dd>
          <dt>{t("obligation.gov.awaiting")}</dt><dd>{fmtNumber(d.awaiting_review)}</dd>
          <dt>{t("obligation.gov.overdue")}</dt><dd>{fmtNumber(d.overdue)}</dd>
          <dt>{t("obligation.gov.escalated")}</dt><dd>{fmtNumber(d.escalated)}</dd>
        </dl>
      </div>
    </section>
  );
}

function Head({ first }) {
  const t = useT();
  return (
    <thead><tr>
      <th>{first}</th><th className="num">{t("obligation.gov.pct")}</th><th className="num">{t("obligation.gov.due")}</th>
      <th className="num">{t("obligation.gov.onTime")}</th><th className="num">{t("obligation.gov.late")}</th>
      <th className="num">{t("obligation.gov.awaiting")}</th><th className="num">{t("obligation.gov.overdue")}</th><th className="num">{t("obligation.gov.escalated")}</th>
    </tr></thead>
  );
}

export function ObligationsPanel({ state, states, onStateChange }) {
  const t = useT();
  const [selected, setSelected] = useState(null);
  // An obligation named in the URL (from the search panel) opens its register entry.
  const linked = useLinkedId("obligation");
  const [token, setToken] = useState(0);
  const { data, error, loading } = usePolling(() => getObligationView(state), { deps: [state, token] });
  if (loading && !data) return <Loader label={t("obligation.loading")} />;
  if (!data?.summary) return <ErrorNotice error={error} />;
  const s = data.summary;

  return (
    <div className="stack">
      <ErrorNotice error={error} />
      <section className="panel-block" id="obligation-summary">
        <div className="panel-head wrap">
          <div>
            <h2>{t("obligation.gov.title")}</h2>
            <span className="hint">{t("obligation.gov.hint", { days: s.window_days })}</span>
          </div>
          <div className="row head-controls">
            <StateFilter states={states} value={state} onChange={onStateChange} id="obligation-state-filter" />
          </div>
        </div>
        <div className="panel-body">
          <div className="tally-set">
            <div><span className="label">{t("obligation.gov.fleet")}</span><span className="tally-v" id="statutory-pct">{fmtNumber(s.totals.compliance_pct)} %</span></div>
            <div><span className="label">{t("obligation.gov.onTime")}</span><span className="tally-v">{fmtNumber(s.totals.on_time)} / {fmtNumber(s.totals.due)}</span></div>
            <div><span className="label">{t("obligation.gov.overdue")}</span><span className="tally-v risk-high">{fmtNumber(s.open_overdue)}</span></div>
            <div><span className="label">{t("obligation.gov.awaiting")}</span><span className="tally-v">{fmtNumber(s.pending_review)}</span></div>
          </div>
        </div>
        <div className="panel-body flush scroll-x" id="obligation-by-company">
          <span className="label pad-label">{t("obligation.gov.byCompany")}</span>
          <table>
            <Head first={t("obligation.gov.company")} />
            <tbody>{s.by_company.map((c) => <tr key={c.company}><Row label={c.company} sub={c.name} r={c} /></tr>)}</tbody>
          </table>
        </div>
      </section>

      <div id="obligation-by-domain">
        <span className="section-label">{t("obligation.gov.byDomain")}</span>
        <div className="grid-12">
          {s.by_domain.map((d) => <DomainCard key={d.domain} d={d} />)}
        </div>
      </div>

      <section className="panel-block" id="obligation-by-mine">
        <div className="panel-head"><div><h2>{t("obligation.gov.byMine")}</h2></div></div>
        <div className="panel-body flush scroll-x">
          <table>
            <Head first={t("obligation.list.mine")} />
            <tbody>{s.by_mine.slice(0, 20).map((m) => <tr key={m.mine_id} data-mine={m.code}><Row label={m.name} sub={m.code} subIsCode r={m} /></tr>)}</tbody>
          </table>
        </div>
      </section>

      <section className="panel-block" id="obligation-most-overdue">
        <div className="panel-head"><div><h2>{t("obligation.gov.mostOverdue")}</h2></div></div>
        <div className="panel-body flush">
          <TaskList tasks={s.most_overdue} showMine onSelect={(task) => setSelected(task.id)} empty={t("obligation.gov.noneOverdue")} />
        </div>
      </section>

      <section className="panel-block" id="obligation-pending-review">
        <div className="panel-head"><div><h2>{t("obligation.gov.pendingReview")} <span className="tag">{s.pending_review}</span></h2></div></div>
        {s.pending_review > data.pending_review.length && (
          <p className="note pad-label" id="obligation-pending-more">{t("obligation.gov.showing", { shown: data.pending_review.length, total: s.pending_review })}</p>
        )}
        <div className="panel-body flush">
          <TaskList tasks={data.pending_review} showMine onSelect={(task) => setSelected(task.id)} empty={t("obligation.gov.noneWaiting")} />
        </div>
      </section>

      <OtherObligations />
      {linked.value && <ObligationDrawer code={linked.value} onClose={linked.clear} />}
      {selected && <TaskDrawer taskId={selected} onClose={() => setSelected(null)} onChanged={() => setToken((n) => n + 1)} />}
    </div>
  );
}
