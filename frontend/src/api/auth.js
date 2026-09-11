// login() and me() calls.
import { client, clearToken, setToken } from "./client";

export async function login(email, password) {
  const { data } = await client.post("/auth/login", { email, password });
  setToken(data.access_token);
  return data.user;
}

export async function me() {
  const { data } = await client.get("/auth/me");
  return data;
}

export function logout() {
  clearToken();
}
