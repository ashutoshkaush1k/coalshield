// Inspection priority queue (multi-mine roles) and inspection records.
import { client } from "./client";

export const getInspectionQueue = ({ limit = null, state = null } = {}) => {
  const params = {};
  if (limit) params.limit = limit;
  if (state) params.state = state;
  return client.get("/inspections/priority", { params }).then((r) => r.data);
};

export const listInspections = (params = {}) => client.get("/inspections", { params }).then((r) => r.data);
export const getInspection = (id) => client.get(`/inspections/${id}`).then((r) => r.data);
