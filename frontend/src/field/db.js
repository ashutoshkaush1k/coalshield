// The field app's storage on the phone (IndexedDB, Phase 7B). Nothing here needs the network.
//
//   kv        the session (account, encrypted token), the offline reference data (bootstrap), the
//             encryption key (a non-extractable CryptoKey: usable here, never readable as bytes)
//   visits    one per inspection visit started on the phone      {client_id, user_id, status, ...}
//   captures  one per finding                                     {client_id, visit_client_id, ...}
//   photos    compressed photo blobs of a capture                 {client_id, capture_client_id, blob, ...}
//
// Items move pending -> syncing -> synced | failed. A queued item is only ever deleted after it
// has synced (and then only when the user signs out), so no capture is lost by a failed sync, an
// expired login or a closed app.

const DB_NAME = "smg-field";
const VERSION = 1;
const STORES = ["kv", "visits", "captures", "photos"];
let opening = null;

export function openDb() {
  opening ??= new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, VERSION);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains("kv")) db.createObjectStore("kv");
      for (const name of STORES.slice(1)) {
        if (!db.objectStoreNames.contains(name)) {
          const store = db.createObjectStore(name, { keyPath: "client_id" });
          store.createIndex("user_id", "user_id");
        }
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  return opening;
}

const done = (request) => new Promise((resolve, reject) => {
  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(request.error);
});

async function tx(store, mode, work) {
  const db = await openDb();
  const transaction = db.transaction(store, mode);
  const result = await work(transaction.objectStore(store));
  await new Promise((resolve, reject) => {
    transaction.oncomplete = resolve;
    transaction.onerror = () => reject(transaction.error);
    transaction.onabort = () => reject(transaction.error);
  });
  return result;
}

export const kvGet = (key) => tx("kv", "readonly", (s) => done(s.get(key)));
export const kvSet = (key, value) => tx("kv", "readwrite", (s) => done(s.put(value, key)));
export const kvDelete = (key) => tx("kv", "readwrite", (s) => done(s.delete(key)));

export const put = (store, item) => tx(store, "readwrite", (s) => done(s.put(item)));
export const get = (store, id) => tx(store, "readonly", (s) => done(s.get(id)));
export const remove = (store, id) => tx(store, "readwrite", (s) => done(s.delete(id)));
export const all = (store) => tx(store, "readonly", (s) => done(s.getAll()));

/** Update one item in place (read-modify-write in one transaction). */
export const update = (store, id, changes) => tx(store, "readwrite", async (s) => {
  const item = await done(s.get(id));
  if (!item) return null;
  const next = { ...item, ...(typeof changes === "function" ? changes(item) : changes) };
  await done(s.put(next));
  return next;
});

/** Everything queued on this phone, oldest first. */
export async function queue() {
  const [visits, captures, photos] = await Promise.all([all("visits"), all("captures"), all("photos")]);
  const byTime = (a, b) => a.created_at.localeCompare(b.created_at);
  return { visits: visits.sort(byTime), captures: captures.sort(byTime), photos: photos.sort(byTime) };
}
