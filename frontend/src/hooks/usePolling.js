// Interval refetch so dashboards feel live during the demo.
import { useCallback, useEffect, useRef, useState } from "react";

const DEFAULT_INTERVAL = 5000;

/**
 * Fetch on mount, then on an interval.
 *
 * Polling rather than websockets: realtime/ws.py exists but polling is what survives a flaky
 * venue network, and a 5s refresh is indistinguishable from live at demo pace. `stale` lets the
 * UI show the previous data while a refresh is in flight instead of blanking the screen.
 */
export function usePolling(fetcher, { interval = DEFAULT_INTERVAL, deps = [], enabled = true } = {}) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [updatedAt, setUpdatedAt] = useState(null);
  const mounted = useRef(true);
  const savedFetcher = useRef(fetcher);
  savedFetcher.current = fetcher;

  const load = useCallback(async () => {
    try {
      const result = await savedFetcher.current();
      if (!mounted.current) return;
      setData(result);
      setError(null);
      setUpdatedAt(new Date());
    } catch (err) {
      if (mounted.current) setError(err);
    } finally {
      if (mounted.current) setLoading(false);
    }
  }, []);

  useEffect(() => {
    mounted.current = true;
    if (!enabled) {
      setLoading(false);
      return () => { mounted.current = false; };
    }
    setLoading(true);
    load();
    const id = setInterval(load, interval);

    // Browsers throttle setInterval to roughly once a MINUTE in a hidden tab, so a dashboard
    // left in the background goes stale and does not catch up for up to a minute after you
    // switch back to it. That is exactly the demo hand-off: an operator uploads footage in the
    // Mine Head tab, then switches to the Government tab expecting it to have already moved.
    // Refetching the moment the tab becomes visible closes that gap.
    const onVisible = () => {
      if (document.visibilityState === "visible") load();
    };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("focus", onVisible);

    return () => {
      mounted.current = false;
      clearInterval(id);
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("focus", onVisible);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [interval, enabled, ...deps]);

  return { data, error, loading, updatedAt, refresh: load };
}
