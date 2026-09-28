// Sending the queue (Phase 7B). One batch of visits and captures, in the order they were made,
// then the photos of the synced captures. Every item carries its own id, so sending it again -
// after a lost answer, a dropped connection or a second tap - never creates a second record: the
// server answers "replayed" with the first result. Items keep their place in the queue until the
// server has accepted them; a failure leaves them queued with its reason.
import { all, update } from "./db";
import { fieldApi, tokenValid } from "./session";

const RETRYABLE = ["pending", "failed", "syncing"];

const errorOf = (e) => {
  if (!e.response) return { code: "NETWORK" };
  const env = e.response.data?.error ?? {};
  return { code: env.code ?? `HTTP_${e.response.status}`, params: env.params ?? {}, fields: env.fields ?? null };
};

/**
 * Sync this account's queue. @returns {state: "done"|"login"|"offline"|"error", synced, failed, replayed}
 * "login": the token is missing or expired - nothing was sent and nothing changed.
 */
export async function syncQueue(session, onProgress = () => {}) {
  if (!tokenValid(session)) return { state: "login", synced: 0, failed: 0, replayed: 0 };
  const userId = session.user.id;
  const headers = { Authorization: `Bearer ${session.token}` };
  const mine = (item) => item.user_id === userId;
  const visits = (await all("visits")).filter(mine).sort((a, b) => a.created_at.localeCompare(b.created_at));
  const captures = (await all("captures")).filter(mine).sort((a, b) => a.created_at.localeCompare(b.created_at));
  const count = { synced: 0, failed: 0, replayed: 0 };

  const items = [
    ...visits.filter((v) => RETRYABLE.includes(v.status)).map((v) => ({ store: "visits", item: v, body: {
      kind: "visit", client_id: v.client_id, mine_id: v.mine_id, inspection_id: v.inspection_id, recorded_at: v.recorded_at } })),
    ...captures.filter((c) => RETRYABLE.includes(c.status)).map((c) => ({ store: "captures", item: c, body: {
      kind: "capture", client_id: c.client_id, visit_client_id: c.visit_client_id, category: c.category, severity: c.severity,
      checklist_item: c.checklist_item ?? null, obligation_code: c.obligation_code ?? null, note: c.note || null,
      recorded_at: c.recorded_at, location_status: c.location_status, location: c.location ?? null,
      violation_type: c.violation_type ?? null, corrective_action: c.corrective_action ?? null } })),
  ];

  if (items.length) {
    for (const { store, item } of items) await update(store, item.client_id, { status: "syncing" });
    onProgress();
    let response;
    try {
      response = await fieldApi.post("/field/sync", { device_now: new Date().toISOString(), items: items.map((i) => i.body) }, { headers });
    } catch (e) {
      const error = errorOf(e);
      const status = e.response?.status;
      // Not sent, or refused as a whole: back in the queue as it was (401: sign in again, then sync).
      for (const { store, item } of items) {
        await update(store, item.client_id, status && status !== 401 ? { status: "failed", error } : { status: "pending", error });
      }
      onProgress();
      if (status === 401) return { state: "login", ...count };
      return { state: error.code === "NETWORK" ? "offline" : "error", ...count };
    }
    const byId = new Map(items.map((i) => [i.body.client_id, i]));
    for (const r of response.data.results) {
      const entry = byId.get(r.client_id);
      if (!entry) continue;
      if (r.status === "failed") {
        count.failed++;
        await update(entry.store, r.client_id, { status: "failed", error: r.error });
      } else {
        count[r.status === "replayed" ? "replayed" : "synced"]++;
        await update(entry.store, r.client_id, { status: "synced", error: null, result: r.result, synced_at: new Date().toISOString(),
          replayed: r.status === "replayed" });
      }
    }
    onProgress();
  }

  // Photos of captures the server now has.
  const syncedCaptures = new Set((await all("captures")).filter((c) => mine(c) && c.status === "synced").map((c) => c.client_id));
  const photos = (await all("photos")).filter((p) => mine(p) && RETRYABLE.includes(p.status) && syncedCaptures.has(p.capture_client_id));
  for (const photo of photos) {
    await update("photos", photo.client_id, { status: "syncing" });
    onProgress();
    const form = new FormData();
    form.append("client_id", photo.client_id);
    form.append("capture_client_id", photo.capture_client_id);
    form.append("file", photo.blob, `${photo.client_id}.jpg`);
    try {
      const { data } = await fieldApi.post("/field/photos", form, { headers });
      await update("photos", photo.client_id, { status: "synced", error: null, result: data.result, synced_at: new Date().toISOString() });
      count[data.status === "replayed" ? "replayed" : "synced"]++;
    } catch (e) {
      const error = errorOf(e);
      await update("photos", photo.client_id, { status: e.response && e.response.status !== 401 ? "failed" : "pending", error });
      if (!e.response) return { state: "offline", ...count };
      if (e.response.status === 401) return { state: "login", ...count };
      count.failed++;
    }
    onProgress();
  }
  return { state: "done", ...count };
}
