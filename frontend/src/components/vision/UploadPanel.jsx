// Image upload for the live CV demo, wired to POST /api/v1/vision/analyze.
import { useRef, useState } from "react";
import { ACCEPTED_IMAGE_TYPES, ACCEPT_ATTR, analyzeImage } from "../../api/vision";
import { Modal } from "../overlay/Overlay";
import { ErrorNotice } from "../common/ErrorNotice";
import { DetectionPreview } from "./DetectionPreview";

const MAX_BYTES = 12 * 1024 * 1024;

export function UploadPanel({ mineId, onAnalysed }) {
  const [open, setOpen] = useState(false);
  const [file, setFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState(null);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const inputRef = useRef(null);

  function choose(selected) {
    setError(null);
    setResult(null);
    if (!selected) return;

    // Validated here as well as server-side: an instant message beats a round trip to a 422,
    // and on demo day nobody wants to debug a rejected upload in front of judges.
    if (!ACCEPTED_IMAGE_TYPES.includes(selected.type)) {
      setError({ message: `${selected.type || "That file type"} is not supported. Use JPG, PNG, WEBP or BMP.` });
      return;
    }
    if (selected.size > MAX_BYTES) {
      setError({ message: `That file is ${(selected.size / 1e6).toFixed(1)} MB. Keep it under 12 MB.` });
      return;
    }

    setFile(selected);
    setPreviewUrl((old) => {
      if (old) URL.revokeObjectURL(old);
      return URL.createObjectURL(selected);
    });
  }

  async function submit() {
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      const analysis = await analyzeImage(mineId, file);
      setResult(analysis);
      // Tell the dashboard to refetch now rather than waiting out the 5s poll, so the score
      // visibly moves the moment the result lands.
      onAnalysed?.(analysis);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  function reset() {
    setFile(null);
    setResult(null);
    setError(null);
    setPreviewUrl((old) => {
      if (old) URL.revokeObjectURL(old);
      return null;
    });
    if (inputRef.current) inputRef.current.value = "";
  }

  function close() {
    if (busy) return;
    setOpen(false);
  }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>Run PPE detection</button>

      <Modal
        open={open}
        onClose={close}
        wide
        title="PPE detection"
        subtitle="Upload site footage - detections apply to this mine immediately"
        footer={
          <>
            {(file || result) && (
              <button type="button" onClick={reset} disabled={busy}>Clear</button>
            )}
            <button type="button" onClick={close} disabled={busy}>Close</button>
          </>
        }
      >
      <div className="stack">
        <div className="upload-row">
          <input
            ref={inputRef}
            type="file"
            accept={ACCEPT_ATTR}
            onChange={(e) => choose(e.target.files?.[0])}
            disabled={busy}
            aria-label="Choose an image to analyse"
          />
          <button className="primary" onClick={submit} disabled={!file || busy}>
            {busy ? "Analysing..." : "Run PPE detection"}
          </button>
        </div>

        {previewUrl && !result && (
          <figure className="annotated pending">
            <img src={previewUrl} alt="Selected frame, not yet analysed" />
            <figcaption className="small faint">
              {file?.name}, {(file.size / 1e3).toFixed(0)} KB, not yet analysed
            </figcaption>
          </figure>
        )}

        <ErrorNotice
          error={error}
          context="This account can only submit footage for its own mine."
        />

        <DetectionPreview result={result} />
      </div>
      </Modal>
    </>
  );
}
