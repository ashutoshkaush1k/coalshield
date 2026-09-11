// Mine Head action: close a government directive with evidence, in a focused modal.
//
// A modal rather than an inline expansion: this is a text field plus a file picker, and
// expanding it in place pushed the rest of the feed around while the operator typed.
import { useRef, useState } from "react";
import { resolveAlert } from "../../api/alerts";
import { ACCEPT_ATTR, ACCEPTED_IMAGE_TYPES } from "../../api/vision";
import { ErrorNotice } from "../common/ErrorNotice";
import { Modal } from "../overlay/Overlay";
import { useToast } from "../overlay/ToastHost";

export function ResolveDirectiveForm({ alert, onResolved }) {
  const [open, setOpen] = useState(false);
  const [proofText, setProofText] = useState("");
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const inputRef = useRef(null);
  const { notify } = useToast();

  function choose(selected) {
    setError(null);
    if (!selected) return setFile(null);
    // Same allowlist the vision upload uses, checked here for an instant message.
    if (!ACCEPTED_IMAGE_TYPES.includes(selected.type)) {
      setError({ message: "Attach a JPG, PNG, WEBP or BMP image as evidence." });
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
      notify({ title: "Directive resolved", body: "Your evidence is now visible to DGMS." });
      onResolved?.(updated);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>Resolve with proof</button>

      <Modal
        open={open}
        onClose={close}
        title="Resolve directive"
        subtitle={alert.message}
        footer={
          <>
            <button type="button" onClick={close} disabled={busy}>Cancel</button>
            <button className="primary" type="submit" form={`resolve-${alert.id}`}
                    disabled={busy || !proofText.trim()}>
              {busy ? "Submitting..." : "Submit resolution"}
            </button>
          </>
        }
      >
        <form id={`resolve-${alert.id}`} onSubmit={submit} className="stack tight">
          <div>
            <label htmlFor={`proof-${alert.id}`}>Corrective action taken</label>
            <textarea id={`proof-${alert.id}`} rows={4} value={proofText} required
                      onChange={(e) => setProofText(e.target.value)}
                      placeholder="Describe what was done to address this directive" />
          </div>

          <div>
            <label htmlFor={`proof-file-${alert.id}`}>Evidence photo (optional)</label>
            <input id={`proof-file-${alert.id}`} ref={inputRef} type="file" accept={ACCEPT_ATTR}
                   onChange={(e) => choose(e.target.files?.[0])} />
          </div>

          <ErrorNotice error={error} />
        </form>
      </Modal>
    </>
  );
}
