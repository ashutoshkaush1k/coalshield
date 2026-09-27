// Mine head: one shift's production entry. New entries and drafts are saved freely and submitted
// when final; a submitted (locked) entry can only be corrected with a reason, which the API writes
// to the edit log with the old and new values.
import { useEffect, useState } from "react";
import { NUMBER_FIELDS, SHIFTS, createEntry, deleteEntry, submitEntry, updateEntry } from "../../api/production";
import { useT } from "../../i18n/t";
import { Field, FormModal, useSubmit } from "../common/forms";

const blank = (date) => ({
  date, shift: "A", remarks: "",
  ...Object.fromEntries(NUMBER_FIELDS.map((f) => [f, ""])),
});
const fromEntry = (e) => ({ date: e.date, shift: e.shift, remarks: e.remarks ?? "", ...Object.fromEntries(NUMBER_FIELDS.map((f) => [f, e[f]])) });
const numbers = (form) => Object.fromEntries(NUMBER_FIELDS.map((f) => [f, form[f] === "" ? null : Number(form[f])]));

/**
 * `entry` null: a new entry for `date`. A draft: edit, save, submit or delete. Submitted or
 * locked: correct with a reason.
 */
export function EntryForm({ open, entry, date, onClose, onSaved }) {
  const t = useT();
  const [form, setForm] = useState(() => (entry ? fromEntry(entry) : blank(date)));
  const [reason, setReason] = useState("");
  const { busy, error, run, reset } = useSubmit((saved) => { onSaved?.(saved); onClose(); });
  useEffect(() => { if (open) { setForm(entry ? fromEntry(entry) : blank(date)); setReason(""); reset(); } }, [open, entry, date]); // eslint-disable-line react-hooks/exhaustive-deps
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const locked = entry && entry.status !== "draft";
  const body = { ...numbers(form), remarks: form.remarks || null };

  const save = () => run(
    () => (entry ? updateEntry(entry.id, locked ? { ...body, reason } : body) : createEntry({ ...body, date: form.date, shift: form.shift })),
    locked ? t("production.entry.corrected") : t("production.entry.saved"),
  );
  const saveAndSubmit = () => run(async () => {
    const saved = entry ? await updateEntry(entry.id, body) : await createEntry({ ...body, date: form.date, shift: form.shift });
    return submitEntry(saved.id);
  }, t("production.entry.submitted"));
  const remove = () => run(() => deleteEntry(entry.id), t("production.entry.deleted"));

  return (
    <FormModal id="production-entry" open={open} onClose={onClose}
               title={locked ? t("production.entry.correctTitle") : entry ? t("production.entry.title") : t("production.entry.new")}
               subtitle={locked ? t("production.entry.correctHint") : t("production.entry.hint")}
               busy={busy} error={error}
               submitLabel={locked ? t("production.entry.saveCorrection") : t("production.entry.saveDraft")}
               disabled={locked && reason.trim().length < 5}
               onSubmit={save}>
      <div className="form-grid">
        <Field id="pe-date" label={t("production.field.date")}>
          <input id="pe-date" type="date" value={form.date} onChange={set("date")} disabled={Boolean(entry)} required />
        </Field>
        <Field id="pe-shift" label={t("production.field.shift")}>
          <select id="pe-shift" value={form.shift} onChange={set("shift")} disabled={Boolean(entry)}>
            {SHIFTS.map((s) => <option key={s} value={s}>{t("production.shiftName", { shift: s })}</option>)}
          </select>
        </Field>
        {NUMBER_FIELDS.map((f) => (
          <Field key={f} id={`pe-${f}`} label={t(`production.field.${f}`)}>
            <input id={`pe-${f}`} type="number" min="0" step={f === "manpower_present" ? 1 : 0.1} max={f === "breakdown_hours" ? 8 : undefined}
                   value={form[f]} onChange={set(f)} required />
          </Field>
        ))}
      </div>
      <Field id="pe-remarks" label={t("production.field.remarks")}>
        <input id="pe-remarks" value={form.remarks} onChange={set("remarks")} maxLength={1000} />
      </Field>
      {locked && (
        <Field id="pe-reason" label={t("production.entry.reason")}>
          <textarea id="pe-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} required />
        </Field>
      )}
      {!locked && (
        <div className="row">
          <button type="button" className="primary" id="pe-submit" onClick={saveAndSubmit} disabled={busy}>{t("production.entry.submit")}</button>
          {entry && <button type="button" onClick={remove} disabled={busy}>{t("production.entry.delete")}</button>}
        </div>
      )}
    </FormModal>
  );
}
