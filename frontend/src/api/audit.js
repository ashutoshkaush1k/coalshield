// Audit trail queries.
import { client } from "./client";

export const listAudit = (params = {}) => client.get("/audit", { params }).then((r) => r.data);
