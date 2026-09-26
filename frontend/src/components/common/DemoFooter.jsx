// Standing notice on every screen that the figures are synthetic.
import { useT } from "../../i18n/t";

export function DemoFooter() {
  const t = useT();
  return <footer className="demo-footer">{t("demo.footer")}</footer>;
}
