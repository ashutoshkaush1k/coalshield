// Corrective actions: list, detail (with history), record for a violation, close with proof.
import { client } from "./client";

export const listCorrectiveActions = (params = {}) =>
  client.get("/corrective-actions", { params }).then((r) => r.data);

export const getCorrectiveAction = (id) => client.get(`/corrective-actions/${id}`).then((r) => r.data);

export const createCorrectiveAction = ({ violationId, description, dueAt, contractorId }) =>
  client
    .post("/corrective-actions", { violation_id: violationId, description, due_at: dueAt, contractor_id: contractorId ?? null })
    .then((r) => r.data);

export const resolveCorrectiveAction = (id, { proofText, file }) => {
  const form = new FormData();
  form.append("proof_text", proofText);
  if (file) form.append("file", file);
  return client.post(`/corrective-actions/${id}/resolve`, form).then((r) => r.data);
};
