// Alerts ({code, params}), government directives, and the proof-backed resolution loop.
import { client } from "./client";

export const ALERT_STATUS = { OPEN: "open", ACKNOWLEDGED: "acknowledged", RESOLVED: "resolved" };

/** params: mine_id, status, directives, since (ISO), per_page. */
export const listAlerts = (params = {}) => client.get("/alerts", { params }).then((r) => r.data);
export const acknowledgeAlert = (id) => client.post(`/alerts/${id}/ack`).then((r) => r.data);

/**
 * Raise a directive against a mine (government). Without message or severity the server
 * snapshots the mine's score and band at the moment of the click.
 */
export const raiseDirective = ({ mineId, message = null, severity = null, referenceId = null }) =>
  client
    .post("/alerts/directives", { mine_id: mineId, message, severity, reference_id: referenceId })
    .then((r) => r.data);

/** Close an alert with evidence (multipart: the proof may carry a photo). */
export const resolveAlert = (id, { proofText, file }) => {
  const form = new FormData();
  form.append("proof_text", proofText);
  if (file) form.append("file", file);
  return client.post(`/alerts/${id}/resolve`, form).then((r) => r.data);
};

/** Government sends a resolved directive back. */
export const reopenAlert = (id, reason = "") => client.post(`/alerts/${id}/reopen`, { reason }).then((r) => r.data);

export const isOpenDirective = (a) => a.is_directive && a.status !== ALERT_STATUS.RESOLVED;
