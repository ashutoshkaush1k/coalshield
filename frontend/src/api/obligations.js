// The statutory obligation register (Phase 5B). The screen polls getObligationView - one request
// per cycle (docs/PERFORMANCE.md). Every task carries its obligation's citation.
import { client } from "./client";

/** Mine head: { summary, due_soon, overdue, submitted, open, accepted }. Multi-mine roles: { summary, pending_review }. */
export const getObligationView = (state = null) =>
  client.get("/views/obligations", { params: state ? { state } : {} }).then((r) => r.data);
export const getObligationTask = (id) => client.get(`/obligation-tasks/${id}`).then((r) => r.data);
export const submitEvidence = (taskId, { file, note }) => {
  const form = new FormData();
  form.append("file", file);
  if (note) form.append("note", note);
  return client.post(`/obligation-tasks/${taskId}/submissions`, form).then((r) => r.data);
};
/** decision "accept" or "reject" (a reason is required to reject). */
export const reviewEvidence = (submissionId, decision, note) =>
  client.post(`/obligation-submissions/${submissionId}/review`, { decision, note: note || undefined }).then((r) => r.data);
/** Government: waive a task for its period (a reason is required). */
export const waiveTask = (taskId, reason) =>
  client.post(`/obligation-tasks/${taskId}/waive`, { reason }).then((r) => r.data);
/** The cited catalogue - read once per screen, not polled. */
export const getObligations = () => client.get("/obligations").then((r) => r.data);
