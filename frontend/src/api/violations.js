// Violations in scope (params: mine_id, per_page, resolved, filter[category]...).
import { client } from "./client";

export const listViolations = (params = {}) => client.get("/violations", { params }).then((r) => r.data);
export const getViolation = (id) => client.get(`/violations/${id}`).then((r) => r.data);
