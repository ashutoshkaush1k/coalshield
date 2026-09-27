// The map (Phase 5B). Outlines are local data served by the API (no internet needed) and fetched
// once - the browser revalidates them by ETag; the mines are polled with the screen.
import { client } from "./client";

export const getStates = () => client.get("/geo/states").then((r) => r.data);
export const getDistricts = () => client.get("/geo/districts").then((r) => r.data);
/** { mines: GeoJSON FeatureCollection } - the mines in scope with score, band and location quality. */
export const getMapView = (state = null) =>
  client.get("/views/map", { params: state ? { state } : {} }).then((r) => r.data);
