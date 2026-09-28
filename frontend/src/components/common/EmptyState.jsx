// Zero-data state.
import { useT } from "../../i18n/t";

export function EmptyState({ children }) {
  const t = useT();
  return <div className="empty">{children ?? t("common.nothingYet")}</div>;
}
