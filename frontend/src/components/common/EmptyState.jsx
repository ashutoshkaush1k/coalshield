// Zero-data state.
import { Inbox } from "lucide-react";
import { useT } from "../../i18n/t";

/** Compact: an icon and one line. */
export function EmptyState({ children }) {
  const t = useT();
  return <div className="empty"><Inbox size={18} aria-hidden="true" /><span>{children ?? t("common.nothingYet")}</span></div>;
}
