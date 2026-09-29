// Government / corporate / inspector: production across the mines in scope, numbers only (brief
// Phase 4) - the day's and the month-to-date target, actual and achievement, the anomaly flag,
// and the latest "Call for Detailed Report" per mine with a button to raise one. A mine's detail
// opens only for a period its answered request covers. One request per polling cycle
// (GET /v1/views/production-overview).
import { useState } from "react";
import { getProductionOverview } from "../../../api/production";
import { can } from "../../../auth/permissions";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { StateFilter } from "../../../components/common/StateFilter";
import { CallForReportButton, CloseRequestButton } from "../../../components/production/DetailRequestForms";
import { ProductionDetailDrawer } from "../../../components/production/ProductionDetailDrawer";
import { RequestList } from "../../../components/production/RequestList";
import { StatusTag, anomalyLines, fmtNum, fmtPct } from "../../../components/production/common";
import { useAuth } from "../../../hooks/useAuth";
import { usePolling } from "../../../hooks/usePolling";
import { useT } from "../../../i18n/t";

const FULFILLED = ["submitted", "closed"];

const shiftDate = (date, days) => {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
};

/** For a flagged mine: the flagged days with three days either side, and the reasons as the request's reason. */
function anomalyDefaults(m, date) {
  if (!m.anomaly.flagged) return undefined;
  const days = m.anomaly.days.map((d) => d.date).sort();
  const yesterday = shiftDate(date, -1);
  const to = shiftDate(days[days.length - 1], 3);
  return {
    from: shiftDate(days[0], -3),
    to: to > yesterday ? yesterday : to,
    reason: m.anomaly.days.flatMap(anomalyLines).join("; "),
  };
}

export function ProductionPanel({ state, states, onStateChange }) {
  const t = useT();
  const { user } = useAuth();
  const [date, setDate] = useState(null);
  const [token, setToken] = useState(0);
  const [detail, setDetail] = useState(null);   // {mineId, from, to, title}
  const { data, error, loading } = usePolling(() => getProductionOverview({ state, date }), { deps: [state, date, token] });
  const refresh = () => setToken((n) => n + 1);
  if (loading && !data) return <Loader label={t("production.loading")} />;
  if (!data) return <ErrorNotice error={error} />;
  const s = data.summary;
  const mayRequest = can(user, "detailRequest.create");
  const openDetail = (r) => setDetail({ mineId: r.mine_id, from: r.date_from, to: r.date_to, title: r.mine_name ?? r.name });

  return (
    <div className="stack">
      <ErrorNotice error={error} />
      <section className="panel-block">
        <div className="panel-head wrap">
          <div>
            <h2>{t("production.gov.title")}</h2>
            <span className="hint">{t("production.gov.hint")}</span>
          </div>
          <div className="row head-controls">
            <label htmlFor="production-date" className="label">{t("production.gov.date")}</label>
            <input id="production-date" type="date" value={s.date} onChange={(e) => e.target.value && setDate(e.target.value)} />
            <StateFilter states={states} value={state} onChange={onStateChange} id="production-state-filter" />
          </div>
        </div>
        <div className="panel-body">
          <div className="tally-set">
            <div><span className="label">{t("production.gov.mtd")} - {t("production.gov.actual")}</span><span className="tally-v">{fmtNum(s.totals.mtd.actual_t)}</span></div>
            <div><span className="label">{t("production.gov.mtd")} - {t("production.gov.achievement")}</span><span className="tally-v">{fmtPct(s.totals.mtd.achievement_pct)}</span></div>
            <div><span className="label">{t("production.anomaly.flag")}</span><span className={`tally-v ${s.flagged ? "risk-high" : ""}`}>{s.flagged}</span></div>
          </div>
          <p className="note">{t("production.gov.flaggedCount", { count: s.flagged })}. {t("production.anomaly.rule")}</p>
        </div>
        <div className="panel-body flush scroll-x">
          <table className="production-table">
            <thead>
              <tr>
                <th rowSpan={2}>{t("detailRequest.mine")}</th>
                <th colSpan={3} className="num">{t("production.gov.today")} {s.date}</th>
                <th colSpan={3} className="num">{t("production.gov.mtd")}</th>
                <th rowSpan={2}>{t("production.anomaly.flag")}</th>
                <th rowSpan={2}>{t("production.gov.request")}</th>
              </tr>
              <tr>
                <th className="num">{t("production.gov.target")}</th><th className="num">{t("production.gov.actual")}</th><th className="num">%</th>
                <th className="num">{t("production.gov.target")}</th><th className="num">{t("production.gov.actual")}</th><th className="num">%</th>
              </tr>
            </thead>
            <tbody>
              {s.mines.map((m) => (
                <tr key={m.mine_id} className={m.anomaly.flagged ? "is-flagged" : ""} data-mine={m.code}>
                  <td><strong>{m.name}</strong> <span className="mono faint">{m.code}</span><div className="faint small">{m.state}</div></td>
                  {m.today.target_t === null
                    ? <td colSpan={3} className="faint small">{t("production.gov.notReported")}</td>
                    : <>
                        <td className="num">{fmtNum(m.today.target_t)}</td>
                        <td className="num">{fmtNum(m.today.actual_t)}</td>
                        <td className="num">{fmtPct(m.today.achievement_pct)}</td>
                      </>}
                  <td className="num">{fmtNum(m.mtd.target_t)}</td>
                  <td className="num">{fmtNum(m.mtd.actual_t)}</td>
                  <td className="num">{fmtPct(m.mtd.achievement_pct)}</td>
                  <td>
                    {m.anomaly.flagged
                      ? <span className="risk-mark risk-high" title={m.anomaly.days.flatMap(anomalyLines).join("\n")}>
                          <span className="chip" aria-hidden="true" />{t("production.anomaly.flagged")}
                          <div className="small">{m.anomaly.days.map((d) => d.date).join(", ")}</div>
                        </span>
                      : <span className="faint">{t("production.anomaly.none")}</span>}
                  </td>
                  <td>
                    <div className="row">
                      {m.request && <StatusTag status={m.request.status} />}
                      {/* Offered on every row: an unanswered range explains itself (403 DETAIL_REQUEST_REQUIRED). */}
                      <button type="button" className="small-btn" onClick={() => openDetail(
                        m.request && FULFILLED.includes(m.request.status)
                          ? { ...m.request, mine_id: m.mine_id, name: m.name }
                          : { mine_id: m.mine_id, name: m.name, date_from: s.month_start, date_to: s.date },
                      )}>
                        {t("production.gov.openDetail")}
                      </button>
                      {mayRequest && (
                        <CallForReportButton mine={m} onCreated={refresh} defaults={anomalyDefaults(m, s.date)} />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel-block" id="production-requests">
        <div className="panel-head">
          <div>
            <h2>{t("detailRequest.listTitle")}</h2>
            <span className="hint">{t("detailRequest.listHint")}</span>
          </div>
        </div>
        <div className="panel-body flush">
          <RequestList requests={data.requests} showMine actions={(r) => (
            <div className="row">
              {FULFILLED.includes(r.status) && <button type="button" className="small-btn" onClick={() => openDetail(r)}>{t("production.gov.openDetail")}</button>}
              {r.status === "submitted" && mayRequest && <CloseRequestButton request={r} onDone={refresh} />}
            </div>
          )} />
        </div>
      </section>

      {detail && <ProductionDetailDrawer {...detail} onClose={() => setDetail(null)} />}
    </div>
  );
}
