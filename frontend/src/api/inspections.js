// Auto-ranked inspection queue (Government only).
import { client } from "./client";

export const getInspectionQueue = ({ limit = null, state = null } = {}) => {
  const params = {};
  if (limit) params.limit = limit;
  if (state) params.state = state;
  return client.get("/inspections", { params }).then((r) => r.data);
};
