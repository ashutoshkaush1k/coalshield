// Marks a figure as a demo value: the scores come from synthetic data (data/DATASETS.md).
import { useT } from "../../i18n/t";

export function DemoTag() {
  const t = useT();
  return (
    <span className="demo-tag" title={t("demo.valueTitle")}>
      {t("demo.valueTag")}
    </span>
  );
}
