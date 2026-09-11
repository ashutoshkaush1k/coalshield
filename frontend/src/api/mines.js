// Mine list, detail, and cross-mine comparison queries.
import { client } from "./client";

export const listMines = () => client.get("/mines").then((r) => r.data);
export const getMine = (mineId) => client.get(`/mines/${mineId}`).then((r) => r.data);
