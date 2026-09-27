// Mine-head forms for contractors: register (with the first contract), add a contract, add a
// worker, upload a monthly document, change status. Each is a modal; errors come back as codes
// and are shown through ErrorNotice.
import { useState } from "react";
import {
  DOC_TYPES, WORK_TYPES, addWorker, changeContractorStatus, createContract, createContractor, uploadDocument,
} from "../../api/contractors";
import { docTypeLabel, workTypeLabel } from "../../i18n/contractors";
import { useT } from "../../i18n/t";
import { Field, FormModal, useSubmit } from "../common/forms";

const today = () => new Date().toISOString().slice(0, 10);
const lastMonth = () => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - 1); return d.toISOString().slice(0, 7); };

function ContractFields({ value, onChange, idPrefix }) {
  const t = useT();
  const set = (k) => (e) => onChange({ ...value, [k]: e.target.value });
  return (
    <div className="form-grid">
      <Field id={`${idPrefix}-wt`} label={t("contractor.workTypeLabel")}>
        <select id={`${idPrefix}-wt`} value={value.work_type} onChange={set("work_type")}>
          {WORK_TYPES.map((w) => <option key={w} value={w}>{workTypeLabel(w)}</option>)}
        </select>
      </Field>
      <Field id={`${idPrefix}-wo`} label={t("contractor.workOrder")}>
        <input id={`${idPrefix}-wo`} value={value.work_order_no} onChange={set("work_order_no")} required />
      </Field>
      <Field id={`${idPrefix}-val`} label={t("contractor.value")}>
        <input id={`${idPrefix}-val`} type="number" min="0" value={value.value} onChange={set("value")} required />
      </Field>
      <Field id={`${idPrefix}-max`} label={t("contractor.maxWorkers")}>
        <input id={`${idPrefix}-max`} type="number" min="1" value={value.max_workers} onChange={set("max_workers")} required />
      </Field>
      <Field id={`${idPrefix}-start`} label={t("contractor.start")}>
        <input id={`${idPrefix}-start`} type="date" value={value.start_date} onChange={set("start_date")} required />
      </Field>
      <Field id={`${idPrefix}-end`} label={t("contractor.end")}>
        <input id={`${idPrefix}-end`} type="date" value={value.end_date} onChange={set("end_date")} required />
      </Field>
    </div>
  );
}

const emptyContract = () => ({ work_type: "transport", work_order_no: "", value: "", max_workers: 20, start_date: today(), end_date: "" });

export function RegisterContractorButton({ onCreated }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ name: "", registration_no: "", labour_licence_no: "", licence_valid_to: "", epf_code: "", esi_code: "", contact: "" });
  const [contract, setContract] = useState(emptyContract);
  const { busy, error, run } = useSubmit((c) => { setOpen(false); onCreated?.(c); });
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const fields = ["name", "registration_no", "labour_licence_no", "epf_code", "esi_code", "contact"];

  return (
    <>
      <button className="primary" type="button" onClick={() => setOpen(true)}>{t("contractor.register")}</button>
      <FormModal id="contractor-new" open={open} onClose={() => setOpen(false)} title={t("contractor.register")}
                 subtitle={t("contractor.registerHint")} busy={busy} error={error} submitLabel={t("contractor.save")}
                 onSubmit={() => run(() => createContractor({ ...form, contract: { ...contract, value: Number(contract.value), max_workers: Number(contract.max_workers) } }), t("contractor.saved"))}>
        <div className="form-grid">
          {fields.map((f) => (
            <Field key={f} id={`cn-${f}`} label={t(`contractor.field.${f}`)}>
              <input id={`cn-${f}`} value={form[f]} onChange={set(f)} required />
            </Field>
          ))}
          <Field id="cn-licence" label={t("contractor.field.licence_valid_to")}>
            <input id="cn-licence" type="date" value={form.licence_valid_to} onChange={set("licence_valid_to")} required />
          </Field>
        </div>
        <span className="label">{t("contractor.firstContract")}</span>
        <ContractFields value={contract} onChange={setContract} idPrefix="cn-c" />
      </FormModal>
    </>
  );
}

export function AddContractButton({ contractor, onDone }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const [contract, setContract] = useState(emptyContract);
  const { busy, error, run } = useSubmit(() => { setOpen(false); onDone?.(); });
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>{t("contractor.addContract")}</button>
      <FormModal id={`contract-new-${contractor.id}`} open={open} onClose={() => setOpen(false)} title={t("contractor.addContract")}
                 subtitle={contractor.name} busy={busy} error={error} submitLabel={t("contractor.save")}
                 onSubmit={() => run(() => createContract({ ...contract, contractor_id: contractor.id, value: Number(contract.value), max_workers: Number(contract.max_workers) }), t("contractor.saved"))}>
        <ContractFields value={contract} onChange={setContract} idPrefix={`ca-${contractor.id}`} />
      </FormModal>
    </>
  );
}

export function AddWorkerButton({ contracts, onDone }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ contract: contracts[0]?.id ?? "", name: "", worker_code: "", vt_cert_valid_to: "", medical_exam_date: today() });
  const { busy, error, run } = useSubmit(() => { setOpen(false); onDone?.(); });
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  if (!contracts.length) return null;
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>{t("contractor.addWorker")}</button>
      <FormModal id="worker-new" open={open} onClose={() => setOpen(false)} title={t("contractor.addWorker")} busy={busy} error={error}
                 submitLabel={t("contractor.save")}
                 onSubmit={() => run(() => addWorker(form.contract, { name: form.name, worker_code: form.worker_code,
                   vt_cert_valid_to: form.vt_cert_valid_to, medical_exam_date: form.medical_exam_date }), t("contractor.saved"))}>
        <Field id="wn-contract" label={t("contractor.contract")}>
          <select id="wn-contract" value={form.contract} onChange={set("contract")}>
            {contracts.map((c) => <option key={c.id} value={c.id}>{c.work_order_no}</option>)}
          </select>
        </Field>
        <div className="form-grid">
          <Field id="wn-name" label={t("contractor.workerName")}><input id="wn-name" value={form.name} onChange={set("name")} required /></Field>
          <Field id="wn-code" label={t("contractor.workerCode")}><input id="wn-code" value={form.worker_code} onChange={set("worker_code")} required /></Field>
          <Field id="wn-vt" label={t("contractor.vtValidTo")}><input id="wn-vt" type="date" value={form.vt_cert_valid_to} onChange={set("vt_cert_valid_to")} required /></Field>
          <Field id="wn-med" label={t("contractor.medicalDate")}><input id="wn-med" type="date" value={form.medical_exam_date} onChange={set("medical_exam_date")} required /></Field>
        </div>
      </FormModal>
    </>
  );
}

export function UploadDocumentButton({ contracts, preset, onDone, label }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ contract: preset?.contract_id ?? contracts[0]?.id ?? "", docType: preset?.doc_type ?? "wage_register", period: preset?.period ?? lastMonth() });
  const [file, setFile] = useState(null);
  const { busy, error, run } = useSubmit(() => { setOpen(false); setFile(null); onDone?.(); });
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  if (!contracts.length) return null;
  return (
    <>
      <button type="button" className={preset ? "small-btn" : ""} onClick={() => setOpen(true)}>{label ?? t("contractor.upload")}</button>
      <FormModal id={`doc-new-${preset ? `${preset.contract_id}-${preset.doc_type}-${preset.period}` : "any"}`} open={open} onClose={() => setOpen(false)}
                 title={t("contractor.upload")} subtitle={t("contractor.uploadHint")} busy={busy} error={error} submitLabel={t("contractor.uploadSubmit")}
                 disabled={!file} onSubmit={() => run(() => uploadDocument(form.contract, { docType: form.docType, period: form.period, file }), t("contractor.uploaded"))}>
        <Field id="dn-contract" label={t("contractor.contract")}>
          <select id="dn-contract" value={form.contract} onChange={set("contract")}>
            {contracts.map((c) => <option key={c.id} value={c.id}>{c.work_order_no}</option>)}
          </select>
        </Field>
        <div className="form-grid">
          <Field id="dn-type" label={t("contractor.docTypeLabel")}>
            <select id="dn-type" value={form.docType} onChange={set("docType")}>
              {DOC_TYPES.map((d) => <option key={d} value={d}>{docTypeLabel(d)}</option>)}
            </select>
          </Field>
          <Field id="dn-period" label={t("contractor.period")}>
            <input id="dn-period" type="month" value={form.period} onChange={set("period")} required />
          </Field>
        </div>
        <Field id="dn-file" label={t("contractor.file")}>
          <input id="dn-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
      </FormModal>
    </>
  );
}

export function ChangeStatusButton({ contractor, onDone }) {
  const t = useT();
  const next = { active: ["suspended", "blacklisted"], suspended: ["active", "blacklisted"], blacklisted: ["active"] }[contractor.status] ?? [];
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState(next[0]);
  const [reason, setReason] = useState("");
  const { busy, error, run } = useSubmit(() => { setOpen(false); setReason(""); onDone?.(); });
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>{t("contractor.changeStatus")}</button>
      <FormModal id={`status-${contractor.id}`} open={open} onClose={() => setOpen(false)} title={t("contractor.changeStatus")}
                 subtitle={contractor.name} busy={busy} error={error} submitLabel={t("contractor.save")} disabled={!reason.trim()}
                 onSubmit={() => run(() => changeContractorStatus(contractor.id, status, reason), t("contractor.statusChanged"))}>
        <Field id="st-status" label={t("contractor.newStatus")}>
          <select id="st-status" value={status} onChange={(e) => setStatus(e.target.value)}>
            {next.map((s) => <option key={s} value={s}>{t(`contractor.status.${s}`)}</option>)}
          </select>
        </Field>
        <Field id="st-reason" label={t("contractor.reasonLabel")}>
          <textarea id="st-reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} required />
        </Field>
      </FormModal>
    </>
  );
}
