// Mine Head action: close a government directive with evidence, in a focused modal.
//
// A modal rather than an inline expansion: this is a text field plus a file picker, and
// expanding it in place pushed the rest of the feed around while the operator typed.
import { useRef, useState } from "react";
import { resolveAlert } from "../../api/alerts";
// The API stores proof images as JPG, PNG or WEBP (FileStorage whitelist).
const PROOF_TYPES = ["image/jpeg", "image/png", "image/webp"];
const ACCEPT_ATTR = ".jpg,.jpeg,.png,.webp";
import { ErrorNotice } from "../common/ErrorNotice";
import { Modal } from "../overlay/Overlay";
import { useToast } from "../overlay/ToastHost";
import { alertText } from "../../i18n/labels";
import { useT } from "../../i18n/t";

export function ResolveDirectiveForm({ alert, onResolved }) {
  const [open, setOpen] = useState(false);
  const [proofText, setProofText] = useState("");
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const inputRef = useRef(null);
  const { notify } = useToast();
  const t = useT();

  function choose(selected) {
    setError(null);
    if (!selected) return setFile(null);
    // Same allowlist the vision upload uses, checked here for an instant message.
    if (!PROOF_TYPES.includes(selected.type)) {
      setError({ message: t("directive.proofType") });
      return;
    }
    setFile(selected);
  }

  function close() {
    if (busy) return;
    setOpen(false);
    setError(null);
  }

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const updated = await resolveAlert(alert.id, { proofText, file });
      setOpen(false);
      setProofText("");
      setFile(null);
      if (inputRef.current) inputRef.current.value = "";
      notify({ title: t("directive.resolved"), body: t("directive.resolvedBody") });
      onResolved?.(updated);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>{t("directive.resolveWithProof")}</button>

      <Modal
        open={open}
        onClose={close}
        title={t("directive.resolveTitle")}
        subtitle={alertText(alert)}
        footer={
          <>
            <button type="button" onClick={close} disabled={busy}>{t("common.cancel")}</button>
            <button className="primary" type="submit" form={`resolve-${alert.id}`}
                    disabled={busy || !proofText.trim()}>
              {busy ? t("common.submitting") : t("directive.submitResolution")}
            </button>
          </>
        }
      >
        <form id={`resolve-${alert.id}`} onSubmit={submit} className="stack tight">
          <div>
            <label htmlFor={`proof-${alert.id}`}>{t("directive.actionTaken")}</label>
            <textarea id={`proof-${alert.id}`} rows={4} value={proofText} required
                      onChange={(e) => setProofText(e.target.value)}
                      placeholder={t("directive.actionPlaceholder")} />
          </div>

          <div>
            <label htmlFor={`proof-file-${alert.id}`}>{t("directive.proofPhoto")}</label>
            <input id={`proof-file-${alert.id}`} ref={inputRef} type="file" accept={ACCEPT_ATTR}
                   onChange={(e) => choose(e.target.files?.[0])} />
          </div>

          <ErrorNotice error={error} />
        </form>
      </Modal>
    </>
  );
}
