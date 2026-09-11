// Axios instance with base URL and a token interceptor; a 401/403 clears the session.
import axios from "axios";

const TOKEN_KEY = "smg.token";

// sessionStorage, not localStorage, and the difference matters for the demo: localStorage is
// shared across every tab on the origin, so signing in as a Mine Head in one tab would silently
// end the Government session in another. Per-tab storage lets both dashboards be open side by
// side, which is exactly how the cross-mine story is presented (docs/demo-script.md).
// Trade-off: closing the tab ends the session. A refresh keeps it.
const store = window.sessionStorage;

export const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || "http://localhost:8000/api/v1",
});

// Annotated frames are served by the API host at /static/..., not by the Vite dev server, so
// a relative src would 404 against localhost:5173. This rebuilds them against the API origin.
export const assetUrl = (path) => {
  if (!path) return null;
  if (/^https?:\/\//i.test(path)) return path;
  const origin = client.defaults.baseURL.replace(/\/api\/v\d+\/?$/, "");
  return `${origin}${path.startsWith("/") ? "" : "/"}${path}`;
};

export const getToken = () => store.getItem(TOKEN_KEY);
export const setToken = (t) => store.setItem(TOKEN_KEY, t);
export const clearToken = () => store.removeItem(TOKEN_KEY);

client.interceptors.request.use((config) => {
  const token = getToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Normalise every failure into { status, message } so components never render a raw axios error.
// 403 is deliberately NOT treated as a logout: a Mine Head hitting a Government-only route is
// correctly authenticated, just not permitted, and signing them out would be wrong and confusing.
client.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const detail = error.response?.data?.detail;

    if (status === 401) {
      clearToken();
      if (!window.location.pathname.startsWith("/login")) window.location.href = "/login";
    }

    return Promise.reject({
      status,
      message:
        detail ||
        (status === 403
          ? "You do not have access to this data."
          : error.message === "Network Error"
            ? "Cannot reach the API. Is the backend running on port 8000?"
            : "Something went wrong."),
      isForbidden: status === 403,
      isNetwork: !error.response,
    });
  },
);
