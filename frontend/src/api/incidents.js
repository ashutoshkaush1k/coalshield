// Incidents: list (mine_id, late), detail with the 48-hour reporting check, violation link.
import { client } from "./client";

export const listIncidents = (params = {}) => client.get("/incidents", { params }).then((r) => r.data);
export const getIncident = (id) => client.get(`/incidents/${id}`).then((r) => r.data);
export const linkIncidentViolation = (id, violationId) =>
  client.patch(`/incidents/${id}/violation`, { related_violation_id: violationId }).then((r) => r.data);
