// Dashboard search (government and corporate): GET /v1/search?q= -> {q, mines, contractors,
// grievances, obligations}, at most 5 each, scoped by the API like each list endpoint.
import { client } from "./client";

export const searchAll = (q, { signal } = {}) => client.get("/search", { params: { q }, signal }).then((r) => r.data);
