// Violation log queries.
import { client } from "./client";

export const listViolations = (params = {}) =>
  client.get("/violations", { params }).then((r) => r.data);
