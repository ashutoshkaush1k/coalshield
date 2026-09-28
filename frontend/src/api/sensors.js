// Sensor readings and trend series.
import { client } from "./client";

export const getReadings = (mineId, params = {}) =>
  client.get(`/sensors/${mineId}`, { params }).then((r) => r.data);

export const getTrend = (mineId, points = 40) =>
  client.get(`/sensors/${mineId}/trend`, { params: { points } }).then((r) => r.data);

/**
 * Current sensor standing across every mine - who is breaching right now.
 *
 * Government only; a Mine Head gets a 403, the same pattern as /inspections. Distinct
 * from getTrend, which answers what one mine has been doing over time.
 */
export const getFleetSensors = (state = null) =>
  client.get("/sensors", { params: state ? { state } : {} }).then((r) => r.data);

/** Stack order, bottom to top. Fixed so a bar reads the same way in every window. */
export const BREACH_CATEGORIES = ["gas", "dust", "temperature"];

/**
 * Breach frequency over time, bucketed and split by sensor category.
 *
 * One request. This used to assemble the buckets client-side by calling the per-mine
 * trend endpoint once per mine, which was fine at five mines and untenable across a
 * national dataset. Scope and the state filter are both applied server-side.
 *
 * Each bucket carries the per-category counts and `breaches`, the total, so the stacked
 * bar height and the "worst window" reading cannot drift apart.
 */
export async function getBreachBuckets({ state = null, mineId = null } = {}) {
  const params = {};
  if (state) params.state = state;
  if (mineId != null) params.mine_id = mineId;

  const { data } = await client.get("/sensors/breaches", { params });
  // The window's label is formatted where it is shown (fmtDayHour), in the UI language.
  return data.buckets.map((b) => ({ ...b, start: b.start_ms }));
}
