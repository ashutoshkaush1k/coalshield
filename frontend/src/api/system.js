// GET /v1/system/status (Phase 8): which engine the automated detection uses right now.
import { client } from "./client";

export const getSystemStatus = () => client.get("/system/status").then((r) => r.data);
