// The field app's session (Phase 7B): the account and the offline reference data stay on the phone
// after the first sign-in, so the app opens and captures work with no network and with an expired
// login. The token is kept encrypted at rest (AES-GCM, with a key the browser will not export) and
// is only needed to sync. Signing out removes the token and the synced items; unsynced ones stay
// until their account signs in again and syncs them.
import axios from "axios";
import { API_URL, setToken } from "../api/client";
import { kvDelete, kvGet, kvSet } from "./db";

export const fieldApi = axios.create({ baseURL: API_URL, timeout: 30000 });

/** {sub, exp} of a JWT, without verifying it (the server does that). */
export function tokenClaims(token) {
  try {
    const part = token.split(".")[1].replace(/-/g, "+").replace(/_/g, "/");
    return JSON.parse(atob(part.padEnd(part.length + ((4 - (part.length % 4)) % 4), "=")));
  } catch {
    return {};
  }
}

/** True while the token has at least a minute left. */
export const tokenValid = (session) => !!session?.token && (tokenClaims(session.token).exp ?? 0) * 1000 > Date.now() + 60000;

async function key() {
  let k = await kvGet("key");
  if (!k) {
    k = await crypto.subtle.generateKey({ name: "AES-GCM", length: 256 }, false, ["encrypt", "decrypt"]);
    await kvSet("key", k);
  }
  return k;
}

async function seal(text) {
  if (!globalThis.crypto?.subtle) return null;   // not a secure context: the token stays in memory only
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, await key(), new TextEncoder().encode(text));
  return { iv, data };
}

async function open(sealed) {
  if (!sealed || !globalThis.crypto?.subtle) return null;
  try {
    const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: sealed.iv }, await key(), sealed.data);
    return new TextDecoder().decode(plain);
  } catch {
    return null;
  }
}

let memoryToken = null;

/** The stored session {user, token, bootstrap, bootstrapped_at} or null (never signed in on this phone). */
export async function loadSession() {
  const stored = await kvGet("session");
  if (!stored) return null;
  const token = memoryToken ?? (await open(stored.token));
  return { ...stored, token };
}

async function saveSession(session) {
  memoryToken = session.token;
  await kvSet("session", { ...session, token: session.token ? await seal(session.token) : null });
}

/** Sign in (online): the token, the account, and everything to work offline. */
export async function signIn(email, password) {
  const { data } = await fieldApi.post("/auth/login", { email, password });
  return adopt(data.access_token);
}

/** Use a token (a fresh sign-in, or the dashboard's session in this tab) and refresh the offline data. */
export async function adopt(token) {
  const { data } = await fieldApi.get("/field/bootstrap", { headers: { Authorization: `Bearer ${token}` } });
  const previous = await kvGet("session");
  const session = { user: data.user, token, bootstrap: data, bootstrapped_at: new Date().toISOString(),
    // The accounts whose items are on this phone (a phone may be shared; each syncs its own).
    accounts: [...new Set([...(previous?.accounts ?? []), data.user.id])] };
  await saveSession(session);
  setToken(token);   // the dashboard pages in this tab use it too
  return session;
}

/** Refresh the offline data with the current token (online). */
export async function refresh(session) {
  return adopt(session.token);
}

/** Forget the token; keep the account and the offline data so queued items stay visible. */
export async function dropToken(session) {
  memoryToken = null;
  await saveSession({ ...session, token: null });
}

export async function forgetSession() {
  memoryToken = null;
  await kvDelete("session");
}
