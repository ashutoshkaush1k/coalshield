// A mine's records behind one row of buttons: violations, corrective actions, incidents and the
// audit trail, each in a drawer with a detail view. Shared by the government drill-down and the
// mine head overview so both read exactly the same data the same way. Actions (record or close a
// corrective action) appear only when the account holds the permission; the API checks it again.
import { useState } from "react";
import { createCorrectiveAction, resolveCorrectiveAction } from "../../api/correctiveActions";
import { linkViolationContractor } from "../../api/contractors";
import { ContractorSelect } from "../contractors/ContractorSelect";
import { can } from "../../auth/permissions";
import { useAuth } from "../../hooks/useAuth";
import {
  actionDescription, auditChanges, auditHeadline, categoryLabel, descriptionCodeLabel, incidentSeverityLabel,
  incidentTypeLabel, reportingCheckText, sourceLabel, statusLabel, violationTypeLabel,
} from "../../i18n/labels";
import { useT } from "../../i18n/t";
import { fmtDateTime, fmtPercent } from "../../utils/format";
import { EmptyState } from "../common/EmptyState";
import { ErrorNotice } from "../common/ErrorNotice";
import { Drawer, Modal } from "../overlay/Overlay";
import { useToast } from "../overlay/ToastHost";

function Field({ label, children, mono = false }) {
  return (
    <div className="detail-field">
      <span className="label">{label}</span>
      <div className={`detail-value${mono ? " mono" : ""}`}>{children ?? "-"}</div>
    </div>
  );
}

const PROOF_TYPES = ["image/jpeg", "image/png", "image/webp"];

/** Buttons plus drawers. `bundle` = {violations, correctiveActions, incidents, audit}. */
export function MineRecords({ bundle, onChanged }) {
  const t = useT();
  const [panel, setPanel] = useState(null);
  const [selected, setSelected] = useState(null);
  const { violations, correctiveActions, incidents, audit } = bundle;
  const openViolations = violations.filter((v) => !v.resolved).length;
  const overdue = correctiveActions.filter((a) => a.is_overdue).length;
  const late = incidents.filter((i) => !i.reported_within_48h).length;

  // Selections are re-read from the live bundle, so a poll updates an open detail in place.
  const live = (kind, list) => (selected?.kind === kind ? list.find((r) => r.id === selected.id) : null);

  return (
    <>
      <div className="row wrap records-bar">
        <button type="button" onClick={() => setPanel("violations")}>
          {t("violation.title")} ({openViolations} {statusLabel("open").toLowerCase()})
        </button>
        <button type="button" onClick={() => setPanel("actions")}>
          {t("correctiveAction.title")} ({correctiveActions.length}{overdue ? `, ${overdue} ${t("correctiveAction.overdue")}` : ""})
        </button>
        <button type="button" onClick={() => setPanel("incidents")}>
          {t("incident.title")} ({incidents.length}{late ? `, ${late} ${t("incident.late")}` : ""})
        </button>
        <button type="button" onClick={() => setPanel("audit")}>
          {t("audit.title")} ({audit.length})
        </button>
      </div>

      <Drawer open={panel === "violations"} onClose={() => setPanel(null)} title={t("violation.title")}
              subtitle={t("records.latest", { count: violations.length })} flush>
        {violations.length ? (
          <table>
            <thead><tr><th>{t("violation.type")}</th><th>{t("violation.category")}</th><th>{t("violation.status")}</th><th>{t("violation.detected")}</th></tr></thead>
            <tbody>
              {violations.map((v) => (
                <tr key={v.id} className="clickable" onClick={() => setSelected({ kind: "violation", id: v.id })}>
                  <td><strong>{violationTypeLabel(v.violation_type)}</strong><div className="faint small">{sourceLabel(v.source)}</div></td>
                  <td>{categoryLabel(v.category)}</td>
                  <td><span className={`tag ${v.resolved ? "tag-resolved" : "tag-open"}`}>{statusLabel(v.resolved ? "resolved" : "open")}</span></td>
                  <td className="mono">{fmtDateTime(v.detected_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : <EmptyState>{t("violation.empty")}</EmptyState>}
      </Drawer>

      <Drawer open={panel === "actions"} onClose={() => setPanel(null)} title={t("correctiveAction.title")}
              subtitle={t("records.latest", { count: correctiveActions.length })} flush>
        {correctiveActions.length ? (
          <table>
            <thead><tr><th>{t("correctiveAction.description")}</th><th>{t("violation.status")}</th><th>{t("correctiveAction.due")}</th></tr></thead>
            <tbody>
              {correctiveActions.map((a) => (
                <tr key={a.id} className="clickable" onClick={() => setSelected({ kind: "action", id: a.id })}>
                  <td>{actionDescription(a.description)}<div className="faint small mono">{t("records.forViolation", { id: a.violation_id })}</div></td>
                  <td>
                    <span className={`tag ${a.status === "resolved" ? "tag-resolved" : "tag-open"}`}>{statusLabel(a.status)}</span>
                    {a.is_overdue && <span className="tag tag-open" style={{ marginLeft: 4 }}>{t("correctiveAction.overdue")}</span>}
                  </td>
                  <td className="mono">{fmtDateTime(a.due_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : <EmptyState>{t("correctiveAction.empty")}</EmptyState>}
      </Drawer>

      <Drawer open={panel === "incidents"} onClose={() => setPanel(null)} title={t("incident.title")}
              subtitle={t("records.incidentsHint")} flush>
        {incidents.length ? (
          <table>
            <thead><tr><th>{t("incident.cause")}</th><th>{t("violation.status")}</th><th>{t("incident.occurred")}</th></tr></thead>
            <tbody>
              {incidents.map((i) => (
                <tr key={i.id} className="clickable" onClick={() => setSelected({ kind: "incident", id: i.id })}>
                  <td><strong>{incidentSeverityLabel(i.severity)}</strong><div className="faint small">{incidentTypeLabel(i.type)}</div></td>
                  <td><span className={`tag ${i.reported_within_48h ? "tag-resolved" : "tag-open"}`}>{i.obligation_code}{i.reported_within_48h ? "" : ` · ${t("incident.late")}`}</span></td>
                  <td className="mono">{fmtDateTime(i.occurred_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : <EmptyState>{t("incident.empty")}</EmptyState>}
      </Drawer>

      <Drawer open={panel === "audit"} onClose={() => setPanel(null)} title={t("audit.title")} subtitle={t("audit.subtitle")} flush>
        {audit.length ? (
          <table>
            <thead><tr><th>{t("records.event")}</th><th>{t("records.when")}</th></tr></thead>
            <tbody>
              {audit.map((entry) => (
                <tr key={entry.id} className="clickable" onClick={() => setSelected({ kind: "audit", id: entry.id })}>
                  <td>
                    <strong>{auditHeadline(entry)}</strong>
                    {entry.source === "seed_history" && <span className="tag" style={{ marginLeft: 6 }}>{t("audit.source.seed_history")}</span>}
                    <div className="muted small">{entry.actor || t("audit.system")} &middot; {auditChanges(entry).slice(0, 2).join("; ")}</div>
                  </td>
                  <td className="mono">{fmtDateTime(entry.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : <EmptyState>{t("audit.empty")}</EmptyState>}
      </Drawer>

      <ViolationDetail violation={live("violation", violations)} actions={correctiveActions}
                       onClose={() => setSelected(null)} onChanged={onChanged} />
      <ActionDetail action={live("action", correctiveActions)} onClose={() => setSelected(null)} onChanged={onChanged} />
      <IncidentDetail incident={live("incident", incidents)} violations={violations} onClose={() => setSelected(null)} />
      <AuditDetail entry={live("audit", audit)} onClose={() => setSelected(null)} />
    </>
  );
}

function ViolationDetail({ violation, actions, onClose, onChanged }) {
  const t = useT();
  const { user } = useAuth();
  if (!violation) return null;
  const own = actions.filter((a) => a.violation_id === violation.id);
  const frame = violation.frame_ref;
  return (
    <Drawer open onClose={onClose} title={t("records.violationDetail")} subtitle={violationTypeLabel(violation.violation_type)}
            footer={!violation.resolved && can(user, "correctiveAction.create")
              ? <RecordActionButton violation={violation} onSaved={onChanged} /> : null}>
      <div className="stack tight">
        <Field label={t("violation.type")}>{violationTypeLabel(violation.violation_type)}</Field>
        <Field label={t("violation.category")}>{categoryLabel(violation.category)}</Field>
        <Field label={t("violation.source")}>{sourceLabel(violation.source)}</Field>
        {violation.confidence != null && <Field label={t("violation.confidence")}>{fmtPercent(violation.confidence)}</Field>}
        <Field label={t("violation.detected")}>{fmtDateTime(violation.detected_at)}</Field>
        <Field label={t("violation.status")}>
          {violation.resolved ? t("violation.resolvedAt", { when: fmtDateTime(violation.resolved_at) }) : statusLabel("open")}
        </Field>
        {frame && <Field label={t("violation.frame")} mono>{frame}</Field>}
        {violation.inspection_id && <Field label={t("records.inspection")} mono>#{violation.inspection_id}</Field>}
        {can(user, "violation.linkContractor")
          ? <LinkContractor violation={violation} onChanged={onChanged} />
          : violation.contractor_id && <Field label={t("contractor.responsible")} mono>#{violation.contractor_id}</Field>}
        <div>
          <span className="label">{t("correctiveAction.title")}</span>
          {own.length ? own.map((a) => (
            <div key={a.id} className="proof" style={{ marginTop: "var(--space-2)" }}>
              <div className="proof-meta">{statusLabel(a.status)} &middot; {t("correctiveAction.due")} {fmtDateTime(a.due_at)}{a.is_overdue ? ` · ${t("correctiveAction.overdue")}` : ""}</div>
              <p className="proof-text">{actionDescription(a.description)}</p>
            </div>
          )) : <p className="faint small">{t("correctiveAction.empty")}</p>}
        </div>
      </div>
    </Drawer>
  );
}

function LinkContractor({ violation, onChanged }) {
  const t = useT();
  const { notify } = useToast();
  async function change(contractorId) {
    try {
      await linkViolationContractor(violation.id, contractorId);
      notify({ title: t("contractor.linked") });
      onChanged?.();
    } catch (err) {
      notify({ title: t("contractor.linkFailed"), body: err.message, tone: "error" });
    }
  }
  return <ContractorSelect id={`v-contractor-${violation.id}`} mineId={violation.mine_id} value={violation.contractor_id} onChange={change} />;
}

function RecordActionButton({ violation, onSaved }) {
  const t = useT();
  const { notify } = useToast();
  const [open, setOpen] = useState(false);
  const [description, setDescription] = useState("");
  const [due, setDue] = useState(() => new Date(Date.now() + 7 * 86400e3).toISOString().slice(0, 10));
  const [contractorId, setContractorId] = useState(violation.contractor_id ?? null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await createCorrectiveAction({ violationId: violation.id, description, dueAt: `${due}T12:00:00Z`, contractorId });
      notify({ title: t("correctiveAction.saved") });
      setOpen(false);
      setDescription("");
      onSaved?.();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <button className="primary" type="button" onClick={() => setOpen(true)}>{t("correctiveAction.record")}</button>
      <Modal open={open} onClose={() => !busy && setOpen(false)} title={t("correctiveAction.recordTitle")}
             subtitle={violationTypeLabel(violation.violation_type)}
             footer={<>
               <button type="button" onClick={() => setOpen(false)} disabled={busy}>{t("records.cancel")}</button>
               <button className="primary" type="submit" form={`ca-new-${violation.id}`} disabled={busy || description.trim().length < 3}>
                 {busy ? t("records.saving") : t("correctiveAction.save")}
               </button>
             </>}>
        <form id={`ca-new-${violation.id}`} onSubmit={submit} className="stack tight">
          <div>
            <label htmlFor={`ca-desc-${violation.id}`}>{t("correctiveAction.descriptionLabel")}</label>
            <textarea id={`ca-desc-${violation.id}`} rows={3} value={description} required onChange={(e) => setDescription(e.target.value)} />
          </div>
          <div>
            <label htmlFor={`ca-due-${violation.id}`}>{t("correctiveAction.dueLabel")}</label>
            <input id={`ca-due-${violation.id}`} type="date" value={due} required onChange={(e) => setDue(e.target.value)} />
          </div>
          <ContractorSelect id={`ca-contractor-${violation.id}`} mineId={violation.mine_id} value={contractorId} onChange={setContractorId} />
          <ErrorNotice error={error} />
        </form>
      </Modal>
    </>
  );
}

function ActionDetail({ action, onClose, onChanged }) {
  const t = useT();
  const { user } = useAuth();
  if (!action) return null;
  const proof = action.proof_image_path;
  return (
    <Drawer open onClose={onClose} title={t("correctiveAction.title")} subtitle={t("records.forViolation", { id: action.violation_id })}
            footer={action.status === "open" && can(user, "correctiveAction.resolve")
              ? <ResolveActionButton action={action} onResolved={onChanged} /> : null}>
      <div className="stack tight">
        <Field label={t("correctiveAction.description")}>{actionDescription(action.description)}</Field>
        <Field label={t("violation.status")}>{statusLabel(action.status)}{action.is_overdue ? ` · ${t("correctiveAction.overdue")}` : ""}</Field>
        <Field label={t("correctiveAction.due")}>{fmtDateTime(action.due_at)}</Field>
        <Field label={t("records.created")}>{fmtDateTime(action.created_at)}</Field>
        {action.resolved_at && <Field label={t("status.resolved")}>{fmtDateTime(action.resolved_at)}</Field>}
        {proof && <Field label={t("alert.proof")} mono>{proof}</Field>}
      </div>
    </Drawer>
  );
}

function ResolveActionButton({ action, onResolved }) {
  const t = useT();
  const { notify } = useToast();
  const [open, setOpen] = useState(false);
  const [proofText, setProofText] = useState("");
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await resolveCorrectiveAction(action.id, { proofText, file });
      notify({ title: t("correctiveAction.resolved") });
      setOpen(false);
      onResolved?.();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <button className="primary" type="button" onClick={() => setOpen(true)}>{t("correctiveAction.resolve")}</button>
      <Modal open={open} onClose={() => !busy && setOpen(false)} title={t("correctiveAction.resolveTitle")}
             subtitle={actionDescription(action.description)}
             footer={<>
               <button type="button" onClick={() => setOpen(false)} disabled={busy}>{t("records.cancel")}</button>
               <button className="primary" type="submit" form={`ca-resolve-${action.id}`} disabled={busy || !proofText.trim()}>
                 {busy ? t("records.saving") : t("correctiveAction.submit")}
               </button>
             </>}>
        <form id={`ca-resolve-${action.id}`} onSubmit={submit} className="stack tight">
          <div>
            <label htmlFor={`ca-proof-${action.id}`}>{t("correctiveAction.proofLabel")}</label>
            <textarea id={`ca-proof-${action.id}`} rows={3} value={proofText} required onChange={(e) => setProofText(e.target.value)} />
          </div>
          <div>
            <label htmlFor={`ca-file-${action.id}`}>{t("correctiveAction.photoLabel")}</label>
            <input id={`ca-file-${action.id}`} type="file" accept=".jpg,.jpeg,.png,.webp"
                   onChange={(e) => {
                     const f = e.target.files?.[0] ?? null;
                     setFile(f && PROOF_TYPES.includes(f.type) ? f : null);
                   }} />
          </div>
          <ErrorNotice error={error} />
        </form>
      </Modal>
    </>
  );
}

function IncidentDetail({ incident, violations, onClose }) {
  const t = useT();
  if (!incident) return null;
  const check = incident.reporting_check;
  const linked = violations.find((v) => v.id === incident.related_violation_id);
  return (
    <Drawer open onClose={onClose} title={incidentSeverityLabel(incident.severity)} subtitle={incidentTypeLabel(incident.type)}>
      <div className="stack tight">
        <div className={`notice ${check?.code === "REPORTED_WITHIN_48H" ? "info" : "error"}`}>
          {reportingCheckText(check)}
        </div>
        <Field label={t("incident.cause")}>{descriptionCodeLabel(incident.description_code)}</Field>
        <Field label={t("incident.occurred")}>{fmtDateTime(incident.occurred_at)}</Field>
        <Field label={t("incident.reported")}>{fmtDateTime(incident.reported_at)}</Field>
        <Field label={t("incident.persons")}>{incident.persons_affected}</Field>
        <Field label={t("records.obligation")}>{t(`incident.obligation.${incident.obligation_code}`)}</Field>
        <Field label={t("incident.linkedViolation")}>
          {incident.related_violation_id
            ? `#${incident.related_violation_id}${linked ? ` · ${violationTypeLabel(linked.violation_type)} (${categoryLabel(linked.category)})` : ""}`
            : t("incident.none")}
        </Field>
      </div>
    </Drawer>
  );
}

function AuditDetail({ entry, onClose }) {
  const t = useT();
  if (!entry) return null;
  return (
    <Drawer open onClose={onClose} title={auditHeadline(entry)} subtitle={fmtDateTime(entry.created_at)}>
      <div className="stack tight">
        <Field label={t("records.actor")}>{entry.actor || t("audit.system")}</Field>
        <div>
          <span className="label">{t("audit.changes")}</span>
          <ul className="change-list">
            {auditChanges(entry).map((line) => <li key={line}>{line}</li>)}
          </ul>
        </div>
        <Field label={t("audit.hash")} mono>{entry.row_hash?.slice(0, 32)}&hellip;</Field>
      </div>
    </Drawer>
  );
}

