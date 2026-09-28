// The obligations that are not on the dated register (incident reporting duties are: one task per
// incident), each with its citation and why it has no
// tasks: a limit watched by the sensor rules, a continuous duty, every shift, on an event, or a
// once / renewal duty. Read once from the catalogue (GET /v1/obligations), not polled.
import { useEffect, useState } from "react";
import { getObligations } from "../../api/obligations";
import { useT } from "../../i18n/t";
import { Citation } from "./Citation";
import { frequencyLabel, obligationTitle } from "../../i18n/labels";

function kind(o) {
  if (o.monitored_by?.length) return "monitored";
  if (o.frequency === "continuous") return "continuous";
  if (o.frequency === "every shift") return "shift";
  if (o.frequency === "on event") return "event";
  return "other";
}
const ORDER = ["monitored", "continuous", "shift", "event", "other"];

export function OtherObligations() {
  const t = useT();
  const [rows, setRows] = useState(null);

  useEffect(() => {
    let live = true;
    getObligations().then((all) => live && setRows(all.filter((o) => !o.generates_tasks && !o.from_incidents))).catch(() => live && setRows([]));
    return () => { live = false; };
  }, []);

  if (!rows?.length) return null;
  const sorted = [...rows].sort((a, b) => ORDER.indexOf(kind(a)) - ORDER.indexOf(kind(b)) || a.code.localeCompare(b.code));
  return (
    <section className="panel-block" id="obligations-other">
      <details>
        <summary className="panel-head">
          <h2>{t("obligation.other.title")} <span className="tag">{rows.length}</span></h2>
        </summary>
        <p className="note pad-label">{t("obligation.other.hint")}</p>
        <div className="panel-body flush scroll-x">
          <table className="obligation-table">
            <thead>
              <tr><th>{t("obligation.list.obligation")}</th><th>{t("obligation.other.how")}</th><th>{t("obligation.task.frequency")}</th></tr>
            </thead>
            <tbody>
              {sorted.map((o) => (
                <tr key={o.code} data-code={o.code}>
                  <td>
                    <strong className="mono">{o.code}</strong> {obligationTitle(o)}
                    <div className="small"><Citation obligation={o} /></div>
                  </td>
                  <td className="small">
                    {t(`obligation.other.kind.${kind(o)}`, { sensors: (o.monitored_by ?? []).map((s) => t(`sensor.${s}`, { defaultValue: s })).join(", ") })}
                    {o.applies_to !== "mine" && <div className="faint">{t("obligation.other.appliesTo", { who: t(`obligation.appliesTo.${o.applies_to}`, { defaultValue: o.applies_to }) })}</div>}
                  </td>
                  <td className="small">{frequencyLabel(o.frequency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </section>
  );
}
