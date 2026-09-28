// Image upload for the live CV demo, wired to POST /api/v1/vision/analyze.
import { useRef, useState } from "react";
import { ACCEPTED_IMAGE_TYPES, ACCEPT_ATTR, analyzeImage } from "../../api/vision";
import { Modal } from "../overlay/Overlay";
import { ErrorNotice } from "../common/ErrorNotice";
import { DetectionPreview } from "./DetectionPreview";
import { useT } from "../../i18n/t";
import { fmtBytes, fmtNumber } from "../../utils/format";

const MAX_BYTES = 12 * 1024 * 1024;

export function UploadPanel({ mineId, onAnalysed }) {
  const [open, setOpen] = useState(false);
  const [file, setFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState(null);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const inputRef = useRef(null);
  const t = useT();

  function choose(selected) {
    setError(null);
    setResult(null);
    if (!selected) return;

    // Validated here as well as server-side: an instant message beats a round trip to a 422,
    // and on demo day nobody wants to debug a rejected upload in front of judges.
    if (!ACCEPTED_IMAGE_TYPES.includes(selected.type)) {
      setError({ message: t("vision.badType", { type: selected.type || "?" }) });
      return;
    }
    if (selected.size > MAX_BYTES) {
      setError({ message: t("vision.tooLarge", { size: fmtNumber(selected.size / 1e6, 1) }) });
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
      <button type="button" onClick={() => setOpen(true)}>{t("vision.run")}</button>

      <Modal
        open={open}
        onClose={close}
        wide
        title={t("vision.title")}
        subtitle={t("vision.subtitle")}
        footer={
          <>
            {(file || result) && (
              <button type="button" onClick={reset} disabled={busy}>{t("common.clear")}</button>
            )}
            <button type="button" onClick={close} disabled={busy}>{t("common.close")}</button>
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
            aria-label={t("vision.chooseImage")}
          />
          <button className="primary" onClick={submit} disabled={!file || busy}>
            {busy ? t("vision.analysing") : t("vision.run")}
          </button>
        </div>

        {previewUrl && !result && (
          <figure className="annotated pending">
            <img src={previewUrl} alt={t("vision.previewAlt")} />
            <figcaption className="small faint">
              {t("vision.previewCaption", { name: file?.name, size: fmtBytes(file.size) })}
            </figcaption>
          </figure>
        )}

        <ErrorNotice
          error={error}
          context={t("vision.ownMineOnly")}
        />

        <DetectionPreview result={result} />
      </div>
      </Modal>
    </>
  );
}
