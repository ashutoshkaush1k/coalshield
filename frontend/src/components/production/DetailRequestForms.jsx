// "Call for Detailed Report" (brief Phase 4): the regulator's request (date range, reason,
// deadline), the mine head's response (note and optional file), and closing it.
import { useState } from "react";
import { closeRequest, createDetailRequest, respondToRequest } from "../../api/production";
import { useT } from "../../i18n/t";
import { Field, FormModal, useSubmit } from "../common/forms";

const isoDate = (d) => d.toISOString().slice(0, 10);
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return isoDate(d); };
const inDays = (n) => { const d = new Date(); d.setDate(d.getDate() + n); d.setHours(17, 0, 0, 0); return d; };
/** A local "YYYY-MM-DDTHH:mm" for a datetime-local input. */
const localInput = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

export function CallForReportButton({ mine, defaults, onCreated, className = "small-btn" }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const initial = () => ({
    date_from: defaults?.from ?? `${daysAgo(0).slice(0, 8)}01`, date_to: defaults?.to ?? daysAgo(1),
    reason: defaults?.reason ?? "", due_at: localInput(inDays(7)),
  });
  const [form, setForm] = useState(initial);
  const { busy, error, run } = useSubmit((r) => { setOpen(false); onCreated?.(r); });
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  return (
    <>
      <button type="button" className={className} onClick={() => { setForm(initial()); setOpen(true); }}>{t("production.gov.callFor")}</button>
      <FormModal id={`dr-new-${mine.mine_id}`} open={open} onClose={() => setOpen(false)}
                 title={`${t("detailRequest.title")}: ${mine.name}`} subtitle={t("detailRequest.hint")}
                 busy={busy} error={error} submitLabel={t("detailRequest.send")}
                 onSubmit={() => run(() => createDetailRequest({ ...form, mine_id: mine.mine_id, due_at: new Date(form.due_at).toISOString() }), t("detailRequest.sent"))}>
        <div className="form-grid">
          <Field id="dr-from" label={t("detailRequest.from")}>
            <input id="dr-from" type="date" value={form.date_from} onChange={set("date_from")} required />
          </Field>
          <Field id="dr-to" label={t("detailRequest.to")}>
            <input id="dr-to" type="date" value={form.date_to} onChange={set("date_to")} required />
          </Field>
          <Field id="dr-due" label={t("detailRequest.due")}>
            <input id="dr-due" type="datetime-local" value={form.due_at} onChange={set("due_at")} required />
          </Field>
        </div>
        <Field id="dr-reason" label={t("detailRequest.reason")}>
          <textarea id="dr-reason" rows={3} value={form.reason} onChange={set("reason")} minLength={10} maxLength={1000} required />
        </Field>
      </FormModal>
    </>
  );
}

export function RespondButton({ request, onDone }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState("");
  const [file, setFile] = useState(null);
  const { busy, error, run } = useSubmit((r) => { setOpen(false); onDone?.(r); });
  return (
    <>
      <button type="button" className="small-btn primary" onClick={() => { setNote(""); setFile(null); setOpen(true); }}>{t("detailRequest.respond")}</button>
      <FormModal id={`dr-respond-${request.id}`} open={open} onClose={() => setOpen(false)}
                 title={t("detailRequest.respondTitle")} subtitle={`${request.date_from} - ${request.date_to}: ${request.reason}`}
                 busy={busy} error={error} submitLabel={t("detailRequest.respond")}
                 onSubmit={() => run(() => respondToRequest(request.id, { note, file }), t("detailRequest.responded"))}>
        <Field id="dr-note" label={t("detailRequest.note")}>
          <textarea id="dr-note" rows={4} value={note} onChange={(e) => setNote(e.target.value)} minLength={5} maxLength={2000} required />
        </Field>
        <Field id="dr-file" label={t("detailRequest.file")}>
          <input id="dr-file" type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
      </FormModal>
    </>
  );
}

export function CloseRequestButton({ request, onDone }) {
  const t = useT();
  const { busy, run } = useSubmit(onDone);
  return (
    <button type="button" className="small-btn" disabled={busy} onClick={() => run(() => closeRequest(request.id), t("detailRequest.closed"))}>
      {t("detailRequest.close")}
    </button>
  );
}
