// Scope selector. Same control, same position, on every Government tab.
//
// The selection lives in the dashboard page so it survives tab switches: an official
// investigating one state should not have to reselect it on each tab.
export function StateFilter({ states, value, onChange, id = "state-filter" }) {
  if (!states?.length) return null;

  return (
    <div className="state-filter">
      <label htmlFor={id}>Region</label>
      <select id={id} value={value ?? ""} onChange={(e) => onChange(e.target.value || null)}>
        <option value="">All India</option>
        {states.map((state) => (
          <option key={state} value={state}>{state}</option>
        ))}
      </select>
    </div>
  );
}
