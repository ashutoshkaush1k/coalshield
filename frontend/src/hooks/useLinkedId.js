// A record named in the URL (e.g. /gov?tab=contractors&contractor=12, from the search panel), so a
// panel can open its drawer for it. `clear()` removes the parameter when the drawer closes, keeping
// the tab.
import { useSearchParams } from "react-router-dom";

export function useLinkedId(name) {
  const [params, setParams] = useSearchParams();
  const value = params.get(name);
  const clear = () => {
    if (!params.has(name)) return;
    const next = new URLSearchParams(params);
    next.delete(name);
    setParams(next, { replace: true });
  };
  return { value, clear };
}
