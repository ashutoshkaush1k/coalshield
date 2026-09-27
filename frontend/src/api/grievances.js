// Grievances (brief Phase 5): the public form and tracking (no login), the staff queue and the
// analytics. The screen polls getGrievanceView (one request per cycle, docs/PERFORMANCE.md).
import { client } from "./client";

export const CATEGORIES = ["safety", "wages", "working_conditions", "harassment", "environment", "land_compensation", "other"];
export const SUBMITTER_TYPES = ["employee", "contract_worker", "community"];
export const LANGUAGES = ["en", "hi", "bn", "or", "te", "mr"];
/** Violation categories a safety grievance can be about (data/schema/violation_categories.yaml). */
export const SAFETY_CATEGORIES = ["ppe", "roof_strata", "ventilation_gas", "electrical", "transport_haulage", "explosives", "fire", "machinery", "environment", "welfare", "documentation"];
/** Status changes staff can make, from each status (the API enforces the same). */
export const NEXT = {
  received: ["acknowledged", "under_investigation", "resolved"],
  acknowledged: ["under_investigation", "resolved"],
  under_investigation: ["resolved"],
  resolved: ["closed", "reopened"],
  closed: ["reopened"],
  reopened: ["under_investigation", "resolved"],
};

// Public (no token needed; the client sends none when signed out).
export const listPublicMines = () => client.get("/public/mines").then((r) => r.data);
export const submitGrievance = (fields, file) => {
  const form = new FormData();
  Object.entries(fields).forEach(([k, v]) => { if (v !== null && v !== undefined) form.append(k, typeof v === "boolean" ? String(v) : v); });
  if (file) form.append("file", file);
  return client.post("/grievances/public", form).then((r) => r.data);
};
export const trackGrievance = (ticket) => client.get(`/grievances/track/${encodeURIComponent(ticket.trim().toUpperCase())}`).then((r) => r.data);

// Staff.
/** { stats, escalated, grievances } - stats and escalated are null for a mine head. */
export const getGrievanceView = (state = null) =>
  client.get("/views/grievances", { params: state ? { state } : {} }).then((r) => r.data);
export const getGrievance = (id) => client.get(`/grievances/${id}`).then((r) => r.data);
export const getAssignees = (id) => client.get(`/grievances/${id}/assignees`).then((r) => r.data);
export const transitionGrievance = (id, to, note) => client.post(`/grievances/${id}/transition`, { to, note: note || undefined }).then((r) => r.data);
export const assignGrievance = (id, userId) => client.post(`/grievances/${id}/assign`, { user_id: userId }).then((r) => r.data);
