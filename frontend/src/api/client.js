// Axios instance for the Yii2 API: base URL, bearer token, and one error shape for every caller.
import axios from "axios";
import { errorMessage } from "../i18n/t";

const TOKEN_KEY = "smg.token";

// sessionStorage, not localStorage: per-tab sessions let the Government and Mine Head dashboards
// sit side by side in two tabs, which is how the cross-mine story is presented
// (docs/demo-script.md). Closing the tab ends the session; a refresh keeps it.
const store = window.sessionStorage;

// VITE_API_URL (frontend/.env) wins; VITE_API_BASE_URL is read for one release (PLAN Q5).
export const API_URL =
  import.meta.env.VITE_API_URL || import.meta.env.VITE_API_BASE_URL || "http://localhost:8080/v1";

export const client = axios.create({ baseURL: API_URL });

// Stored files (annotated frames, proof photos) come as signed "/v1/files/..." links on the API
// host, not the Vite dev server, so they are rebuilt against the API origin.
export const assetUrl = (path) => {
  if (!path) return null;
  if (/^https?:\/\//i.test(path)) return path;
  const origin = new URL(API_URL, window.location.href).origin;
  return `${origin}${path.startsWith("/") ? "" : "/"}${path}`;
};

export const getToken = () => store.getItem(TOKEN_KEY);
export const setToken = (t) => store.setItem(TOKEN_KEY, t);
export const clearToken = () => store.removeItem(TOKEN_KEY);

// "Continue as admin (demo)" (components/auth/DemoAccess.jsx): a demo session that runs out is not
// a silent sign-out - the app shows "Your demo session has ended" and offers a new one.
const DEMO_KEY = "smg.demo";
export const DEMO_ENDED_EVENT = "smg:demo-session-ended";
export const isDemoSession = () => store.getItem(DEMO_KEY) === "1";
export const setDemoSession = (on) => (on ? store.setItem(DEMO_KEY, "1") : store.removeItem(DEMO_KEY));

client.interceptors.request.use((config) => {
  const token = getToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Every failure becomes {status, code, params, fields, message}. The API sends codes only
// ({"error": {"code", "params"?, "fields"?}}); the message is the translated text.
// 403 is not a logout (the account is valid, just not permitted); out-of-scope records are 404.
client.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const envelope = error.response?.data?.error ?? {};
    const normalised = {
      status,
      code: envelope.code ?? (error.response ? `HTTP_${status}` : "NETWORK"),
      params: envelope.params ?? {},
      fields: envelope.fields ?? null,
      isForbidden: status === 403,
      isNotFound: status === 404,
      isNetwork: !error.response,
    };
    normalised.message = errorMessage(normalised);

    if (status === 401 && normalised.code !== "INVALID_CREDENTIALS") {
      clearToken();
      if (isDemoSession()) {
        normalised.isDemoEnded = true;   // ErrorNotice shows nothing: the "demo session has ended" popup speaks
        window.dispatchEvent(new Event(DEMO_ENDED_EVENT));
      }
      else if (!window.location.pathname.startsWith("/login")) window.location.href = "/login";
    }
    return Promise.reject(normalised);
  },
);

/** GET a paged list and keep the paging headers. */
export async function getPage(url, params = {}) {
  const response = await client.get(url, { params });
  return { items: response.data, total: Number(response.headers["x-total-count"] ?? response.data.length) };
}
