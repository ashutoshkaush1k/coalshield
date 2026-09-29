// One contractor in a drawer, in tabs: overview (why the score), contracts, workers, documents
// (missing and uploaded), linked violations, alerts. Management actions appear only with the
// contractor.manage permission; the API enforces it again.
import { useState } from "react";
import { getContractor, verifyDocument } from "../../api/contractors";
import { assetUrl } from "../../api/client";
import { can } from "../../auth/permissions";
import { useAuth } from "../../hooks/useAuth";
import { usePolling } from "../../hooks/usePolling";
import { contractorReason, docTypeLabel, workTypeLabel } from "../../i18n/contractors";
import { categoryLabel, statusLabel, violationTypeLabel } from "../../i18n/labels";
import { useT } from "../../i18n/t";
import { fmtDateTime } from "../../utils/format";
import { AlertList } from "../alerts/AlertList";
import { EmptyState } from "../common/EmptyState";
import { ErrorNotice } from "../common/ErrorNotice";
import { Loader } from "../common/Loader";
import { Drawer } from "../overlay/Overlay";
import { useToast } from "../overlay/ToastHost";
import { ComplianceBadge } from "./ComplianceBadge";
import { AddContractButton, AddWorkerButton, ChangeStatusButton, UploadDocumentButton } from "./ContractorForms";

const TABS = ["overview", "contracts", "workers", "documents", "violations", "alerts"];

export function ContractorDetail({ contractorId, onClose, onChanged }) {
  const t = useT();
  const { user } = useAuth();
  const manage = can(user, "contractor.manage");
  const [tab, setTab] = useState("overview");
  const [token, setToken] = useState(0);
  const { data, error, loading } = usePolling(() => getContractor(contractorId), { deps: [contractorId, token], interval: 15000 });
  const changed = () => { setToken((n) => n + 1); onChanged?.(); };

  return (
    <Drawer open onClose={onClose} title={data?.name ?? t("contractor.title")}
            subtitle={data ? `${data.registration_no} · ${t(`contractor.status.${data.status}`)}` : ""}
            action={data && <ComplianceBadge compliance={data.compliance} />}
            footer={data && manage ? (
              <div className="row wrap" style={{ gap: "var(--space-2)" }}>
                <ChangeStatusButton contractor={data} onDone={changed} />
                <AddContractButton contractor={data} onDone={changed} />
                <AddWorkerButton contracts={data.contracts} onDone={changed} />
                <UploadDocumentButton contracts={data.contracts} onDone={changed} />
              </div>
            ) : null}>
      {loading && !data && <Loader label={t("contractor.loading")} />}
      <ErrorNotice error={error} />
      {data && (
        <div className="stack tight">
          <div className="tabs inline-tabs" role="tablist">
            {TABS.map((id) => (
              <button key={id} role="tab" type="button" className="tab" aria-selected={tab === id} onClick={() => setTab(id)}>
                {t(`contractor.tab.${id}`)}{counts(data)[id] != null ? ` (${counts(data)[id]})` : ""}
              </button>
            ))}
          </div>
          {tab === "overview" && <Overview data={data} />}
          {tab === "contracts" && <Contracts data={data} />}
          {tab === "workers" && <Workers data={data} />}
          {tab === "documents" && <Documents data={data} manage={manage} onChanged={changed} />}
          {tab === "violations" && <Violations data={data} />}
          {tab === "alerts" && <AlertList alerts={data.alerts} />}
        </div>
      )}
    </Drawer>
  );
}

const counts = (d) => ({
  contracts: d.contracts.length, workers: d.workers.filter((w) => w.active).length,
  documents: d.compliance.missing_documents.length, violations: d.violations.length, alerts: d.alerts.length,
});

function Overview({ data }) {
  const t = useT();
  const m = data.compliance;
  return (
    <div className="stack tight">
      {m.band === "flagged" && <div className="notice error"><strong>{t("contractor.flaggedNotice")}</strong></div>}
      <div>
        <span className="label">{t("contractor.why")}</span>
        {m.reasons.length ? (
          <ul className="change-list">{m.reasons.map((r) => <li key={r.code}>{contractorReason(r)}</li>)}</ul>
        ) : <p className="faint small">{t("contractor.noIssues")}</p>}
      </div>
      <div>
        <span className="label">{t("contractor.scoreFormula")}</span>
        <div className="formula">
          100 - {m.penalties.violations} ({t("contractor.pen.violations")}) - {m.penalties.documents} ({t("contractor.pen.documents")})
          {" "}- {m.penalties.licence} ({t("contractor.pen.licence")}) - {m.penalties.workers} ({t("contractor.pen.workers")})
          {" "}- {m.penalties.cap} ({t("contractor.pen.cap")}) = {m.score}
        </div>
      </div>
      <div className="form-grid">
        <div className="detail-field"><span className="label">{t("contractor.licence")}</span>
          <div className="detail-value">{data.labour_licence_no} &middot; {t(`contractor.licenceState.${m.licence.state}`, { date: m.licence.valid_to })}
            <div className="faint small">{t("contractor.licenceBasis")}</div></div></div>
        <div className="detail-field"><span className="label">{t("contractor.codes")}</span>
          <div className="detail-value mono">{data.epf_code} &middot; {data.esi_code}</div></div>
        <div className="detail-field"><span className="label">{t("contractor.contact")}</span><div className="detail-value mono">{data.contact}</div></div>
        <div className="detail-field"><span className="label">{t("contractor.workers")}</span>
          <div className="detail-value">{m.active_workers} &middot; {t("contractor.vtExpiredCount", { count: m.workers_vt_expired })} &middot; {t("contractor.medicalOverdueCount", { count: m.workers_medical_overdue })}</div></div>
      </div>
      {data.history?.length > 0 && (
        <div>
          <span className="label">{t("alert.history")}</span>
          <ul className="change-list">
            {data.history.map((h) => <li key={h.id}>{fmtDateTime(h.created_at)} &middot; {t(`contractor.status.${h.from_status}`)} &rarr; {t(`contractor.status.${h.to_status}`)} &middot; {h.user_name}: {h.context?.reason}</li>)}
          </ul>
        </div>
      )}
    </div>
  );
}

function Contracts({ data }) {
  const t = useT();
  const active = (id) => data.workers.filter((w) => w.contract_id === id && w.active).length;
  if (!data.contracts.length) return <EmptyState>{t("contractor.noContracts")}</EmptyState>;
  return (
    <table>
      <thead><tr><th>{t("contractor.workOrder")}</th><th>{t("contractor.workTypeLabel")}</th><th>{t("contractor.period2")}</th><th className="num">{t("contractor.workersCap")}</th></tr></thead>
      <tbody>
        {data.contracts.map((c) => (
          <tr key={c.id}>
            <td><span className="mono">{c.work_order_no}</span><div className="faint small">{c.mine_name}{c.is_active ? "" : ` · ${t("contractor.ended")}`}</div></td>
            <td>{workTypeLabel(c.work_type)}</td>
            <td className="mono small">{c.start_date} &rarr; {c.end_date}</td>
            <td className={`num${active(c.id) > c.max_workers ? " risk-high" : ""}`}>{active(c.id)} / {c.max_workers}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function Workers({ data }) {
  const t = useT();
  const order = (c) => data.contracts.find((x) => x.id === c)?.work_order_no ?? `#${c}`;
  if (!data.workers.length) return <EmptyState>{t("contractor.noWorkers")}</EmptyState>;
  return (
    <table>
      <thead><tr><th>{t("contractor.workerName")}</th><th>{t("contractor.vtValidTo")}</th><th>{t("contractor.medicalDate")}</th></tr></thead>
      <tbody>
        {data.workers.map((w) => (
          <tr key={w.id} className={w.active ? "" : "faint"}>
            <td><strong>{w.name}</strong><div className="faint small mono">{w.worker_code} &middot; {order(w.contract_id)}{w.active ? "" : ` · ${t("contractor.inactive")}`}</div></td>
            <td className="mono small">{w.vt_cert_valid_to}{w.active && w.vt_expired && <span className="tag tag-open" style={{ marginLeft: 4 }}>{t("contractor.expiredSaf04")}</span>}</td>
            <td className="mono small">{w.medical_exam_date}{w.active && w.medical_overdue && <span className="tag tag-open" style={{ marginLeft: 4 }}>{t("contractor.overdueHlt01")}</span>}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function Documents({ data, manage, onChanged }) {
  const t = useT();
  const { notify } = useToast();
  const order = (c) => data.contracts.find((x) => x.id === c)?.work_order_no ?? `#${c}`;
  const missing = data.compliance.missing_documents;
  async function verify(doc) {
    try {
      await verifyDocument(doc.id);
      notify({ title: t("contractor.verified") });
      onChanged?.();
    } catch (err) {
      notify({ title: t("contractor.verifyFailed"), body: err.message, tone: "error" });
    }
  }
  return (
    <div className="stack tight">
      <div>
        <span className="label">{t("contractor.missingTitle", { count: missing.length })}</span>
        {missing.length ? (
          <table>
            <thead><tr><th>{t("contractor.period")}</th><th>{t("contractor.docTypeLabel")}</th><th>{t("contractor.contract")}</th><th /></tr></thead>
            <tbody>
              {missing.map((m) => (
                <tr key={`${m.contract_id}-${m.period}-${m.doc_type}`} className="is-flagged">
                  <td className="mono">{m.period}</td><td>{docTypeLabel(m.doc_type)}</td><td className="mono small">{order(m.contract_id)}</td>
                  <td>{manage && <UploadDocumentButton contracts={data.contracts} preset={m} onDone={onChanged} label={t("contractor.uploadNow")} />}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : <p className="faint small">{t("contractor.noMissing")}</p>}
        <p className="faint small">{t("contractor.dueRule")}</p>
      </div>
      <div>
        <span className="label">{t("contractor.uploadedTitle", { count: data.documents.length })}</span>
        <table>
          <thead><tr><th>{t("contractor.period")}</th><th>{t("contractor.docTypeLabel")}</th><th>{t("contractor.contract")}</th><th>{t("contractor.verifiedLabel")}</th></tr></thead>
          <tbody>
            {data.documents.map((d) => (
              <tr key={d.id}>
                <td className="mono">{d.period}</td>
                <td>{d.url ? <a href={assetUrl(d.url)} target="_blank" rel="noreferrer">{docTypeLabel(d.doc_type)}</a> : docTypeLabel(d.doc_type)}</td>
                <td className="mono small">{order(d.contract_id)}</td>
                <td>{d.verified ? <span className="tag tag-resolved">{t("contractor.verifiedYes")}</span>
                  : manage ? <button type="button" className="small-btn" onClick={() => verify(d)}>{t("contractor.verify")}</button>
                    : <span className="tag">{t("contractor.verifiedNo")}</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Violations({ data }) {
  const t = useT();
  if (!data.violations.length) return <EmptyState>{t("violation.empty")}</EmptyState>;
  return (
    <table>
      <thead><tr><th>{t("violation.type")}</th><th>{t("violation.category")}</th><th>{t("violation.status")}</th><th>{t("violation.detected")}</th></tr></thead>
      <tbody>
        {data.violations.map((v) => (
          <tr key={v.id}>
            <td><strong>{violationTypeLabel(v.violation_type)}</strong></td>
            <td>{categoryLabel(v.category)}</td>
            <td><span className={`tag ${v.resolved ? "tag-resolved" : "tag-open"}`}>{statusLabel(v.resolved ? "resolved" : "open")}</span></td>
            <td className="time">{fmtDateTime(v.detected_at)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
