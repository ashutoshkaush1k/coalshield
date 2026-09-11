// Alerts feed, government directives, and the proof-backed resolution loop.
import { client } from "./client";

export const listAlerts = (params = {}) => client.get("/alerts", { params }).then((r) => r.data);
export const acknowledgeAlert = (id) => client.post(`/alerts/${id}/ack`).then((r) => r.data);

/**
 * Raise a directive against a mine. Government only; a Mine Head gets a 403.
 *
 * Message and severity are optional: omitted, the server composes them from the mine's
 * state at the moment of the request. That is the one-click "Flag for inspection" path.
 * `referenceId` links the flag to a specific violation or reading when one is in focus.
 */
export const raiseDirective = ({ mineId, message = null, severity = null, referenceId = null }) =>
  client
    .post("/alerts/directives", {
      mine_id: mineId,
      message,
      severity,
      reference_id: referenceId,
    })
    .then((r) => r.data);

/**
 * Close a directive with evidence. Multipart because the proof may carry an image;
 * the image is stored but no detection is run on it.
 */
export const resolveAlert = (id, { proofText, file }) => {
  const form = new FormData();
  form.append("proof_text", proofText);
  if (file) form.append("file", file);
  return client
    .post(`/alerts/${id}/resolve`, form, { headers: { "Content-Type": "multipart/form-data" } })
    .then((r) => r.data);
};

/** Government only. Sends a directive back when the proof is not sufficient. */
export const reopenAlert = (id, reason = "") =>
  client.post(`/alerts/${id}/reopen`, { reason }).then((r) => r.data);

export const ALERT_TYPE = { SYSTEM: "SYSTEM", DIRECTIVE: "DIRECTIVE" };
export const ALERT_STATUS = { OPEN: "OPEN", RESOLVED: "RESOLVED" };
