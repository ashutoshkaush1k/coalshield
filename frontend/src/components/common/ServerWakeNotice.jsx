// Online version only (VITE_WAKE_NOTICE=true, docs/DEPLOYMENT.md): the free API server sleeps after
// about 15 minutes without visitors and takes about a minute to start again, so a first request
// just waits. While the health check or any API call has been waiting a few seconds, a notice says
// so; it goes away by itself when the server answers. The laptop build never shows it.
import { useEffect, useState } from "react";
import { API_URL, client } from "../../api/client";
import { fieldApi } from "../../field/session";
import { useT } from "../../i18n/t";

export const WAKE_NOTICE = import.meta.env.VITE_WAKE_NOTICE === "true";
const SLOW_MS = 3500;

export function ServerWakeNotice() {
  const t = useT();
  const [waiting, setWaiting] = useState(false);

  useEffect(() => {
    if (!WAKE_NOTICE) return undefined;
    const pending = new Map();
    let seq = 0;
    let timer = null;
    const update = () => {
      const now = Date.now();
      const oldest = Math.min(...pending.values(), now);
      setWaiting(now - oldest >= SLOW_MS);
    };
    const begin = () => { const id = ++seq; pending.set(id, Date.now()); return id; };
    const end = (id) => { pending.delete(id); update(); };
    timer = window.setInterval(update, 500);

    // Wake the server as soon as the page opens, before the sign-in.
    const ping = begin();
    fetch(`${API_URL}/health`, { cache: "no-store" }).catch(() => {}).finally(() => end(ping));

    const hooks = [client, fieldApi].map((api) => {
      const req = api.interceptors.request.use((config) => ({ ...config, wakeId: begin() }));
      const res = api.interceptors.response.use(
        (response) => { end(response.config?.wakeId); return response; },
        (error) => { end(error.config?.wakeId); return Promise.reject(error); },
      );
      return () => { api.interceptors.request.eject(req); api.interceptors.response.eject(res); };
    });
    return () => { window.clearInterval(timer); hooks.forEach((off) => off()); };
  }, []);

  if (!waiting) return null;
  return (
    <div className="wake-notice" role="status" aria-live="polite">
      <span className="wake-spinner" aria-hidden="true" />
      <div>
        <strong>{t("wake.title")}</strong>
        <div className="small">{t("wake.detail")}</div>
      </div>
    </div>
  );
}
