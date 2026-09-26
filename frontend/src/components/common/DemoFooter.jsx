// Standing notice on every screen: the figures are synthetic, and the mine data is GEM's
// (attribution required by CC BY 4.0 wherever mine names or locations are shown).
import { useT } from "../../i18n/t";

export function DemoFooter() {
  const t = useT();
  return (
    <footer className="demo-footer">
      {t("demo.footer")}
      <span className="demo-attribution">{t("demo.attribution")}</span>
    </footer>
  );
}
