// Small shared pieces of the production screens: status tags, anomaly reasons as text, and
// number formatting (Indian digit grouping).
import { t, useT } from "../../i18n/t";
import { fmtDate, fmtMonth, fmtNumber } from "../../utils/format";

const TAG = {
  draft: "tag-ack", submitted: "tag-resolved", locked: "tag-ack",
  pending: "tag-directive", overdue: "tag-open", escalated: "tag-open", closed: "tag-resolved",
};

/** An entry or request status as a labelled tag (never colour alone). */
export function StatusTag({ status }) {
  const tr = useT();
  return <span className={`tag ${TAG[status] ?? ""}`}>{tr(`status.${status}`)}</span>;
}

// Numbers in the UI language with Indian grouping (utils/format.js).
export const fmtNum = (n) => (n === null || n === undefined ? "-" : fmtNumber(n));
export const fmtPct = (n) => (n === null || n === undefined ? "-" : `${fmtNumber(n)} %`);

/** One flagged day's reasons, e.g. "2026-09-04: output 2x the 30-day average (14.4 standard deviations)". */
export const anomalyLines = (day) =>
  (day?.reasons ?? []).map((r) => t(`production.anomaly.${r.code}`, { ...r.params, date: fmtDate(day.date) }));

/** "YYYY-MM" of a date string, and the month's first and last day. */
export const monthOf = (date) => date.slice(0, 7);
export const monthLabel = (month) => fmtMonth(month);
