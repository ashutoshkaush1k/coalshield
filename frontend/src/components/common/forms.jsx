// Shared form pieces for the modal forms (contractors, production, detail requests): a modal
// shell with submit and error display, a submit runner with busy / error state and a toast, and
// a labelled field.
import { useState } from "react";
import { useT } from "../../i18n/t";
import { ErrorNotice } from "./ErrorNotice";
import { Modal } from "../overlay/Overlay";
import { useToast } from "../overlay/ToastHost";

/** Modal shell with a submit button and error display. */
export function FormModal({ id, open, onClose, title, subtitle, busy, error, submitLabel, onSubmit, disabled, children }) {
  const t = useT();
  return (
    <Modal open={open} onClose={() => !busy && onClose()} title={title} subtitle={subtitle}
           footer={<>
             <button type="button" onClick={onClose} disabled={busy}>{t("records.cancel")}</button>
             <button className="primary" type="submit" form={id} disabled={busy || disabled}>{busy ? t("records.saving") : submitLabel}</button>
           </>}>
      <form id={id} className="stack tight" onSubmit={(e) => { e.preventDefault(); onSubmit(); }}>
        {children}
        <ErrorNotice error={error} />
      </form>
    </Modal>
  );
}

/** Runs an API call with busy/error state and a toast on success. */
export function useSubmit(onDone) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const { notify } = useToast();
  const run = async (call, toast) => {
    setBusy(true);
    setError(null);
    try {
      const result = await call();
      if (toast) notify({ title: toast });
      onDone?.(result);
      return true;
    } catch (err) {
      setError(err);
      return false;
    } finally {
      setBusy(false);
    }
  };
  return { busy, error, run, reset: () => setError(null) };
}

export function Field({ id, label, children }) {
  return <div><label htmlFor={id}>{label}</label>{children}</div>;
}
