// Role-aware dashboard payload: multi-mine grid for Government, own-mine summary for a Mine Head.
import { client } from "./client";

export const getDashboard = (state = null) =>
  client.get("/dashboard", { params: state ? { state } : {} }).then((r) => r.data);
