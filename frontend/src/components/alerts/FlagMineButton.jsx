// Government action on a mine's drill-down: flag it for inspection in one click.
//
// No form. The message and severity are composed server-side from the mine's state at
// the moment of the request, so the record still says something specific without the
// official typing it, and it cannot be stale relative to what the page last polled.
import { useState } from "react";
import { raiseDirective } from "../../api/alerts";
import { useToast } from "../overlay/ToastHost";
import { alertText } from "../../i18n/labels";
import { errorMessage, useT } from "../../i18n/t";

export function FlagMineButton({ mineId, referenceId = null, onFlagged }) {
  const [busy, setBusy] = useState(false);
  const [flagged, setFlagged] = useState(false);
  const { notify } = useToast();
  const t = useT();

  async function flag() {
    setBusy(true);
    try {
      // Only the mine id travels. Anything the browser composed here would be a copy of
      // data the server already holds, and a staler one.
      const alert = await raiseDirective({ mineId, referenceId });
      setFlagged(true);
      // Acknowledged in a toast rather than only a button state, so the action reads as
      // having produced a record somewhere rather than just toggling a control.
      notify({ title: t("directive.raised"), body: alertText(alert) });
      onFlagged?.(alert);
    } catch (err) {
      notify({ title: t("directive.raiseFailed"), body: errorMessage(err), tone: "error" });
    } finally {
      setBusy(false);
    }
  }

  return (
    <button className="primary" type="button" onClick={flag} disabled={busy || flagged}>
      {busy ? t("directive.flagging") : flagged ? t("directive.flagged") : t("directive.flag")}
    </button>
  );
}
