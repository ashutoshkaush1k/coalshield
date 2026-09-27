// Choose the contractor responsible for a finding: contractors with a contract at this mine.
import { useEffect, useState } from "react";
import { listContractors } from "../../api/contractors";
import { useT } from "../../i18n/t";

export function ContractorSelect({ mineId, value, onChange, id = "contractor-select" }) {
  const t = useT();
  const [options, setOptions] = useState([]);
  useEffect(() => {
    let live = true;
    listContractors({ mine_id: mineId, per_page: 200 })
      .then((rows) => live && setOptions([...rows].sort((a, b) => a.name.localeCompare(b.name))))
      .catch(() => live && setOptions([]));
    return () => { live = false; };
  }, [mineId]);

  return (
    <div>
      <label htmlFor={id}>{t("contractor.responsible")}</label>
      <select id={id} value={value ?? ""} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
        <option value="">{t("contractor.none")}</option>
        {options.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
      </select>
    </div>
  );
}
