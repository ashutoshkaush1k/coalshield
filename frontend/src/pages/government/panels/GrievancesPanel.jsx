// Government / corporate / inspector: grievance analytics for the mines in scope - totals,
// average time to resolution, SLA breaches, breach clusters (flagged mines first), by category,
// by mine and by language - the escalated queue, and every grievance with filters. One request
// per polling cycle (GET /v1/views/grievances).
import { fmtNumber } from "../../../utils/format";
import { useMemo, useState } from "react";
import { getGrievanceView } from "../../../api/grievances";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { StateFilter } from "../../../components/common/StateFilter";
import { GrievanceDetail } from "../../../components/grievances/GrievanceDetail";
import { GrievanceList } from "../../../components/grievances/GrievanceList";
import { useLinkedId } from "../../../hooks/useLinkedId";
import { usePolling } from "../../../hooks/usePolling";
import { useT } from "../../../i18n/t";

const FILTERS = {
  all: () => true,
  open: (g) => g.is_open,
  escalated: (g) => g.escalation_level > 0,
  sensitive: (g) => g.is_sensitive,
};

export function GrievancesPanel({ state, states, onStateChange }) {
  const t = useT();
  const [selected, setSelected] = useState(null);
  // A grievance named in the URL (from the search panel) opens its detail.
  const linked = useLinkedId("grievance");
  const openId = selected ?? (linked.value ? Number(linked.value) : null);
  const [filter, setFilter] = useState("all");
  const [token, setToken] = useState(0);
  const { data, error, loading } = usePolling(() => getGrievanceView(state), { deps: [state, token] });
  const shown = useMemo(() => (data?.grievances ?? []).filter(FILTERS[filter]), [data, filter]);
  if (loading && !data) return <Loader label={t("grievance.loading")} />;
  if (!data?.stats) return <ErrorNotice error={error} />;
  const s = data.stats;
  const hours = (h) => (h === null ? "-" : h >= 48 ? t("grievance.stats.days", { value: fmtNumber(h / 24, 1) }) : t("grievance.stats.hours", { value: fmtNumber(h, 0) }));

  return (
    <div className="stack">
      <ErrorNotice error={error} />
      <section className="panel-block" id="grievance-stats">
        <div className="panel-head wrap">
          <div>
            <h2>{t("grievance.stats.title")}</h2>
            <span className="hint">{t("grievance.stats.hint")}</span>
          </div>
          <div className="row head-controls">
            <StateFilter states={states} value={state} onChange={onStateChange} id="grievance-state-filter" />
          </div>
        </div>
        <div className="panel-body">
          <div className="tally-set">
            <div><span className="label">{t("grievance.stats.total")}</span><span className="tally-v">{fmtNumber(s.totals.total)}</span></div>
            <div><span className="label">{t("grievance.stats.open")}</span><span className="tally-v">{fmtNumber(s.totals.open)}</span></div>
            <div><span className="label">{t("grievance.stats.breaches")}</span><span className="tally-v risk-high">{fmtNumber(s.totals.sla_breaches)}</span></div>
            <div><span className="label">{t("grievance.stats.breachRate")}</span><span className="tally-v">{fmtNumber(s.totals.breach_rate_pct)} %</span></div>
            <div><span className="label">{t("grievance.stats.avgResolution")}</span><span className="tally-v">{hours(s.totals.avg_resolution_hours)}</span></div>
            <div><span className="label">{t("grievance.stats.escalatedOpen")}</span><span className="tally-v risk-high">{fmtNumber(s.totals.escalated_open)}</span></div>
          </div>
        </div>
        <div className="panel-body" id="grievance-clusters">
          <span className="label">{t("grievance.stats.clustersTitle")}</span>
          {s.clusters.length === 0 ? <p className="faint">{t("grievance.stats.noClusters")}</p> : (
            <ol className="flagged-list">
              {s.clusters.map((c) => (
                <li key={c.mine_id}>
                  <strong>{c.name}</strong> <span className="mono faint">{c.code}</span>
                  {" · "}<span className="risk-high">{t("grievance.stats.clusterLine", { count: c.breaches, from: c.from, to: c.to })}</span>
                </li>
              ))}
            </ol>
          )}
          <p className="note">{t("grievance.stats.clusterRule", { min: s.cluster_rule.min_breaches, days: s.cluster_rule.window_days })}</p>
        </div>
      </section>

      <div className="grid two-col">
        <section className="panel-block">
          <div className="panel-head"><h2>{t("grievance.stats.byCategory")}</h2></div>
          <div className="panel-body flush scroll-x">
            <table>
              <thead><tr><th>{t("grievance.list.category")}</th><th className="num">{t("grievance.stats.total")}</th><th className="num">{t("grievance.stats.open")}</th>
                <th className="num">{t("grievance.stats.breaches")}</th><th className="num">{t("grievance.stats.avgResolution")}</th></tr></thead>
              <tbody>
                {s.by_category.map((c) => (
                  <tr key={c.category}><td>{t(`grievance.category.${c.category}`)}</td><td className="num">{fmtNumber(c.total)}</td><td className="num">{fmtNumber(c.open)}</td>
                    <td className="num">{fmtNumber(c.sla_breaches)}</td><td className="num">{hours(c.avg_resolution_hours)}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
        <section className="panel-block">
          <div className="panel-head"><h2>{t("grievance.stats.byLanguage")}</h2></div>
          <div className="panel-body flush scroll-x">
            <table>
              <tbody>
                {Object.entries(s.by_language).sort((a, b) => b[1] - a[1]).map(([lang, n]) => (
                  <tr key={lang}><td>{t(`grievance.language.${lang}`)}</td><td className="num">{fmtNumber(n)}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section className="panel-block" id="grievance-by-mine">
        <div className="panel-head"><h2>{t("grievance.stats.byMine")}</h2></div>
        <div className="panel-body flush scroll-x">
          <table>
            <thead><tr><th>{t("grievance.list.mine")}</th><th className="num">{t("grievance.stats.total")}</th><th className="num">{t("grievance.stats.open")}</th>
              <th className="num">{t("grievance.stats.breaches")}</th><th className="num">{t("grievance.stats.escalatedOpen")}</th>
              <th className="num">{t("grievance.stats.avgResolution")}</th><th>{t("grievance.stats.cluster")}</th></tr></thead>
            <tbody>
              {s.by_mine.slice(0, 25).map((m) => (
                <tr key={m.mine_id} data-mine={m.code} className={m.cluster ? "is-flagged" : ""}>
                  <td><strong>{m.name}</strong> <span className="mono faint">{m.code}</span><div className="faint small">{m.state}</div></td>
                  <td className="num">{fmtNumber(m.total)}</td><td className="num">{fmtNumber(m.open)}</td><td className="num">{fmtNumber(m.sla_breaches)}</td>
                  <td className="num">{fmtNumber(m.escalated_open)}</td><td className="num">{hours(m.avg_resolution_hours)}</td>
                  <td>{m.cluster ? <span className="risk-mark risk-high"><span className="chip" aria-hidden="true" />{t("grievance.stats.clusterLine", { count: m.cluster.breaches, from: m.cluster.from, to: m.cluster.to })}</span> : <span className="faint">-</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel-block" id="grievance-escalated">
        <div className="panel-head"><h2>{t("grievance.stats.escalatedTitle")}</h2></div>
        <div className="panel-body flush">
          <GrievanceList grievances={data.escalated} showMine onSelect={(g) => setSelected(g.id)} empty={t("grievance.stats.escalatedEmpty")} />
        </div>
      </section>

      <section className="panel-block" id="grievance-all">
        <div className="panel-head wrap">
          <div><h2>{t("grievance.stats.allTitle")}</h2></div>
          <div className="row head-controls" role="group">
            {Object.keys(FILTERS).map((f) => (
              <button key={f} type="button" className={`small-btn ${filter === f ? "primary" : ""}`} onClick={() => setFilter(f)} id={`grievance-filter-${f}`}>
                {t(`grievance.list.filter${f[0].toUpperCase()}${f.slice(1)}`)}
              </button>
            ))}
          </div>
        </div>
        <div className="panel-body flush">
          <GrievanceList grievances={shown} showMine onSelect={(g) => setSelected(g.id)} />
        </div>
      </section>
      {openId && <GrievanceDetail grievanceId={openId} onClose={() => { setSelected(null); linked.clear(); }} onChanged={() => setToken((n) => n + 1)} />}
    </div>
  );
}
