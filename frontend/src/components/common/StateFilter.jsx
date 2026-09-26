// Scope selector. Same control, same position, on every Government tab.
//
// The selection lives in the dashboard page so it survives tab switches: an official
// investigating one state should not have to reselect it on each tab.
import { useT } from "../../i18n/t";

export function StateFilter({ states, value, onChange, id = "state-filter" }) {
  const t = useT();
  if (!states?.length) return null;

  return (
    <div className="state-filter">
      <label htmlFor={id}>{t("stateFilter.label")}</label>
      <select id={id} value={value ?? ""} onChange={(e) => onChange(e.target.value || null)}>
        <option value="">{t("stateFilter.all")}</option>
        {states.map((state) => (
          <option key={state} value={state}>{state}</option>
        ))}
      </select>
    </div>
  );
}
