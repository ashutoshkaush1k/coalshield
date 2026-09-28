// Recording on the phone (Phase 7B): photos compressed before they are stored, the location with
// its accuracy, and new queue items. Works with no network.
import { put } from "./db";

export const uuid = () =>
  globalThis.crypto?.randomUUID?.() ??
  "10000000-1000-4000-8000-100000000000".replace(/[018]/g, (c) =>
    (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));

/** A camera photo scaled to `maxPx` on its longest side and re-encoded as JPEG - typically 150-400 KB. */
export async function compressPhoto(file, { maxPx = 1600, quality = 0.72 } = {}) {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, maxPx / Math.max(bitmap.width, bitmap.height));
  const width = Math.round(bitmap.width * scale);
  const height = Math.round(bitmap.height * scale);
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  canvas.getContext("2d").drawImage(bitmap, 0, 0, width, height);
  bitmap.close?.();
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", quality));
  return { blob, width, height, original_bytes: file.size };
}

/**
 * The phone's position: {status: "gps", lat, lon, accuracy_m} or {status: "denied" | "unavailable"}.
 * Underground there is usually no fix; the user then marks the capture "location unknown".
 */
export function locate({ timeout = 15000 } = {}) {
  return new Promise((resolve) => {
    if (!navigator.geolocation) {
      resolve({ status: "unavailable" });
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ status: "gps", lat: p.coords.latitude, lon: p.coords.longitude, accuracy_m: Math.round(p.coords.accuracy * 10) / 10 }),
      (e) => resolve({ status: e.code === 1 ? "denied" : "unavailable" }),
      { enableHighAccuracy: true, timeout, maximumAge: 30000 },
    );
  });
}

/** Distance in metres between two points (haversine) - the phone's own geo-check, shown before sync. */
export function distanceM(a, b) {
  const rad = (d) => (d * Math.PI) / 180;
  const dLat = rad(b.lat - a.lat);
  const dLon = rad(b.lon - a.lon);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLon / 2) ** 2;
  return Math.round(2 * 6371000 * Math.asin(Math.sqrt(h)));
}

export async function startVisit(user, { mine_id, inspection_id = null }) {
  const visit = { client_id: uuid(), user_id: user.id, mine_id, inspection_id, recorded_at: new Date().toISOString(),
    created_at: new Date().toISOString(), status: "pending", error: null };
  await put("visits", visit);
  return visit;
}

export async function saveCapture(user, visit, fields, photos) {
  const capture = { client_id: uuid(), user_id: user.id, visit_client_id: visit.client_id, mine_id: visit.mine_id,
    ...fields, photo_ids: [], recorded_at: fields.recorded_at ?? new Date().toISOString(),
    created_at: new Date().toISOString(), status: "pending", error: null, result: null };
  for (const p of photos) {
    const photo = { client_id: uuid(), user_id: user.id, capture_client_id: capture.client_id, blob: p.blob,
      bytes: p.blob.size, width: p.width, height: p.height, created_at: new Date().toISOString(), status: "pending", error: null };
    await put("photos", photo);
    capture.photo_ids.push(photo.client_id);
  }
  await put("captures", capture);
  return capture;
}
