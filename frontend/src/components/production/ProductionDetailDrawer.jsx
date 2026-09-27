// A mine's detailed production for a period - for a multi-mine role, only once the mine has
// answered a Call for Detailed Report covering it (the API answers 403 DETAIL_REQUEST_REQUIRED
// otherwise, shown here as the explanation). Charts, the mine's response, and every shift entry
// with its corrections.
import { useEffect, useState } from "react";
import { getProductionDetail } from "../../api/production";
import { useT } from "../../i18n/t";
import { fmtDateTime } from "../../utils/format";
import { DemoTag } from "../common/DemoTag";
import { ErrorNotice } from "../common/ErrorNotice";
import { Loader } from "../common/Loader";
import { Drawer } from "../overlay/Overlay";
import { StatusTag, anomalyLines, fmtNum, fmtPct } from "./common";
import { ProductionCharts } from "./ProductionCharts";

export function ProductionDetailDrawer({ mineId, from, to, title, onClose }) {
  const t = useT();
  const [state, setState] = useState({ loading: true });
  useEffect(() => {
    let live = true;
    setState({ loading: true });
    getProductionDetail({ mineId, from, to })
      .then((data) => live && setState({ data }))
      .catch((error) => live && setState({ error }));
    return () => { live = false; };
  }, [mineId, from, to]);
  const { data, error, loading } = state;

  return (
    <Drawer open onClose={onClose} title={t("production.detail.title", { mine: data?.mine?.name ?? title })}
            subtitle={t("production.detail.range", { from, to })}>
      {loading && <Loader label={t("production.loading")} />}
      {error?.code === "DETAIL_REQUEST_REQUIRED"
        ? (
          <div className="notice" id="detail-required">
            <strong>{t("production.detail.requiredTitle")}</strong>
            <div>{t("errors.DETAIL_REQUEST_REQUIRED")}</div>
            <div className="small faint">{t("production.detail.requiredHint", { from, to })}</div>
          </div>
        )
        : <ErrorNotice error={error} />}
      {data && (
        <div className="stack">
          {data.request && (
            <section className="proof" id="detail-response">
              <span className="label">{t("production.detail.response")} <StatusTag status={data.request.status} /></span>
              <p className="proof-text">{data.request.response_note}</p>
              <span className="proof-meta">
                {t("detailRequest.respondedAt", { when: fmtDateTime(data.request.responded_at), name: data.request.responder_name ?? "-" })}
                {" · "}
                {data.request.response_url
                  ? <a href={data.request.response_url} target="_blank" rel="noreferrer">{t("production.detail.responseFile")}</a>
                  : t("production.detail.noFile")}
              </span>
            </section>
          )}
          <div className="row"><DemoTag /></div>
          {data.charts.anomalies.length > 0 && (
            <div className="notice error">{data.charts.anomalies.flatMap(anomalyLines).map((line) => <div key={line}>{line}</div>)}</div>
          )}
          <ProductionCharts charts={data.charts} />
          <span className="label">{t("production.detail.entriesTitle")}</span>
          <EntryTable entries={data.entries} />
        </div>
      )}
    </Drawer>
  );
}

/** Shift entries with their status and, under each corrected one, its edit log. */
export function EntryTable({ entries, actions }) {
  const t = useT();
  return (
    <div className="scroll-x">
      <table className="entry-table">
        <thead>
          <tr>
            <th>{t("production.field.date")}</th><th>{t("production.field.shift")}</th>
            <th className="num">{t("production.field.coal_target_t")}</th><th className="num">{t("production.field.coal_actual_t")}</th>
            <th className="num">{t("production.entries.achievement")}</th><th className="num">{t("production.field.dispatch_t")}</th>
            <th className="num">{t("production.field.breakdown_hours")}</th><th className="num">{t("production.field.manpower_present")}</th>
            <th /><th />
          </tr>
        </thead>
        <tbody>
          {entries.map((e) => (
            <EntryRow key={e.id} entry={e} actions={actions} />
          ))}
        </tbody>
      </table>
    </div>
  );
}

function EntryRow({ entry: e, actions }) {
  const t = useT();
  const edits = e.edits ?? [];
  return (
    <>
      <tr data-entry={e.id}>
        <td className="mono">{e.date}</td>
        <td>{e.shift}</td>
        <td className="num">{fmtNum(e.coal_target_t)}</td>
        <td className="num">{fmtNum(e.coal_actual_t)}</td>
        <td className="num">{e.coal_target_t > 0 ? fmtPct(Math.round((1000 * e.coal_actual_t) / e.coal_target_t) / 10) : "-"}</td>
        <td className="num">{fmtNum(e.dispatch_t)}</td>
        <td className="num">{fmtNum(e.breakdown_hours)}</td>
        <td className="num">{fmtNum(e.manpower_present)}</td>
        <td><StatusTag status={e.status} /></td>
        <td>{actions?.(e)}</td>
      </tr>
      {edits.length > 0 && (
        <tr className="edit-log-row">
          <td colSpan={10}>
            <span className="label">{t("production.detail.edits")}</span>
            {edits.map((x) => (
              <div key={x.id} className="small">
                {t("production.detail.editLine", {
                  field: t(`production.field.${x.field}`), old: x.old_value, new: x.new_value, reason: x.reason,
                  who: x.editor_name ?? "-", when: fmtDateTime(x.edited_at),
                })}
              </div>
            ))}
          </td>
        </tr>
      )}
    </>
  );
}
