// Contractor-specific labels: score reasons ({code, params}), work and document types.
import { humanise } from "../utils/format";
import { t } from "./t";

export const workTypeLabel = (key) => t(`contractor.workType.${key}`, { defaultValue: humanise(key) });
export const docTypeLabel = (key) => t(`contractor.docType.${key}`, { defaultValue: humanise(key) });

/** One reason behind a contractor's score. */
export function contractorReason(reason) {
  if (!reason) return "";
  const p = reason.params || {};
  return t(`contractor.reason.${reason.code}`, {
    ...p,
    doc_types: (p.doc_types || []).map(docTypeLabel).join(", "),
    status: p.status ? t(`contractor.status.${p.status}`) : "",
    days: Math.abs(p.days_left ?? 0),
  });
}
