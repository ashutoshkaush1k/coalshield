// Government action on a mine's drill-down: flag it for inspection in one click.
//
// No form. The message and severity are composed server-side from the mine's state at
// the moment of the request, so the record still says something specific without the
// official typing it, and it cannot be stale relative to what the page last polled.
import { useState } from "react";
import { raiseDirective } from "../../api/alerts";
import { useToast } from "../overlay/ToastHost";

export function FlagMineButton({ mineId, referenceId = null, onFlagged }) {
  const [busy, setBusy] = useState(false);
  const [flagged, setFlagged] = useState(false);
  const { notify } = useToast();

  async function flag() {
    setBusy(true);
    try {
      // Only the mine id travels. Anything the browser composed here would be a copy of
      // data the server already holds, and a staler one.
      const alert = await raiseDirective({ mineId, referenceId });
      setFlagged(true);
      // Acknowledged in a toast rather than only a button state, so the action reads as
      // having produced a record somewhere rather than just toggling a control.
      notify({ title: "Directive raised", body: alert.message });
      onFlagged?.(alert);
    } catch (err) {
      notify({ title: "Could not raise directive", body: err.message, tone: "error" });
    } finally {
      setBusy(false);
    }
  }

  return (
    <button className="primary" type="button" onClick={flag} disabled={busy || flagged}>
      {busy ? "Flagging..." : flagged ? "Flagged \u2713" : "Flag for inspection"}
    </button>
  );
}
