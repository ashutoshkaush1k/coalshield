// One request per dashboard screen and polling cycle (docs/PERFORMANCE.md). Each part has the
// shape of the endpoint it comes from (/dashboard, /contractors/summary, /mines/{id}, ...).
import { client } from "./client";

/** { dashboard, contractor_summary } - contractor_summary is null for roles without it. */
export const getOverviewView = (state = null) =>
  client.get("/views/overview", { params: state ? { state } : {} }).then((r) => r.data);

/** { mine, trend, violations, alerts, audit, corrective_actions, incidents } for one mine. */
export const getMineView = (mineId) => client.get(`/views/mine/${mineId}`).then((r) => r.data);

/** { queue, patterns }: the inspection queue ordered by the Governance Risk Index, and the detectors' findings (Phase 7). */
export const getPriorityView = (state = null) =>
  client.get("/views/priority", { params: state ? { state } : {} }).then((r) => r.data);
