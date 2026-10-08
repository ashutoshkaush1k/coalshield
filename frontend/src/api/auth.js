// login() and me() calls.
import { client, clearToken, setDemoSession, setToken } from "./client";

export async function login(email, password) {
  const { data } = await client.post("/auth/login", { email, password });
  setToken(data.access_token);
  setDemoSession(false);
  return data.user;
}

/** GET /v1/auth/demo: true when the API offers "Continue as admin (demo)" (404 when it is off). */
export async function demoAvailable() {
  try {
    await client.get("/auth/demo");
    return true;
  } catch {
    return false;
  }
}

/** POST /v1/auth/demo: the demo admin account, no password; a 30-minute session. */
export async function demoLogin() {
  const { data } = await client.post("/auth/demo");
  setToken(data.access_token);
  setDemoSession(true);
  return data.user;
}

export async function me() {
  const { data } = await client.get("/auth/me");
  return data;
}

export function logout() {
  clearToken();
  setDemoSession(false);
}

/** PATCH /v1/users/me - only preferred_language may change. */
export async function updateMe(changes) {
  const { data } = await client.patch("/users/me", changes);
  return data;
}

/** POST /v1/users/me/password - {current_password, new_password}; 204 when changed. */
export async function changePassword(currentPassword, newPassword) {
  await client.post("/users/me/password", { current_password: currentPassword, new_password: newPassword });
}
