// Compliance score (current, with its inputs) and the recorded history for the trend line.
import { client } from "./client";

export const getCompliance = (mineId) => client.get(`/compliance/${mineId}`).then((r) => r.data);
export const getComplianceHistory = (mineId, limit = 200) =>
  client.get(`/compliance/${mineId}/history`, { params: { limit } }).then((r) => r.data);
