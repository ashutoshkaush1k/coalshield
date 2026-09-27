// Production reporting (brief Phase 4): daily entries, the numbers-only summary, the detail gate
// and the "Call for Detailed Report" workflow. The screens poll getProductionView /
// getProductionOverview (one request per cycle, docs/PERFORMANCE.md).
import { client } from "./client";

export const SHIFTS = ["A", "B", "C"];
/** The figures of an entry, in form order. */
export const NUMBER_FIELDS = [
  "coal_target_t", "coal_actual_t", "ob_target_m3", "ob_actual_m3",
  "dispatch_t", "closing_stock_t", "breakdown_hours", "manpower_present",
];

/** Mine head: { month, today, entries, charts, requests } for the own mine. */
export const getProductionView = (month) =>
  client.get("/views/production", { params: month ? { month } : {} }).then((r) => r.data);

/** Multi-mine roles: { summary, requests }. */
export const getProductionOverview = ({ state = null, date = null } = {}) =>
  client.get("/views/production-overview", { params: { ...(state ? { state } : {}), ...(date ? { date } : {}) } }).then((r) => r.data);

/** Entries with their edit log, and charts - 403 DETAIL_REQUEST_REQUIRED unless a request covers it. */
export const getProductionDetail = ({ mineId, from, to }) =>
  client.get("/production/detail", { params: { mine_id: mineId, from, to } }).then((r) => r.data);

export const createEntry = (body) => client.post("/production", body).then((r) => r.data);
/** A draft changes freely; a submitted or locked entry needs `reason`. */
export const updateEntry = (id, body) => client.patch(`/production/${id}`, body).then((r) => r.data);
export const submitEntry = (id) => client.post(`/production/${id}/submit`).then((r) => r.data);
export const deleteEntry = (id) => client.delete(`/production/${id}`);

export const createDetailRequest = (body) => client.post("/detail-requests", body).then((r) => r.data);
export const respondToRequest = (id, { note, file }) => {
  const form = new FormData();
  form.append("response_note", note);
  if (file) form.append("file", file);
  return client.post(`/detail-requests/${id}/respond`, form).then((r) => r.data);
};
export const closeRequest = (id, note) => client.post(`/detail-requests/${id}/close`, note ? { note } : {}).then((r) => r.data);
