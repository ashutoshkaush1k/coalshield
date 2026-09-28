// 404 fallback.
import { Link } from "react-router-dom";
import { useT } from "../i18n/t";

export default function NotFound() {
  const t = useT();
  return (
    <div className="content">
      <h1>{t("notFound.title")}</h1>
      <p className="muted">{t("notFound.body")} <Link to="/">{t("notFound.back")}</Link></p>
    </div>
  );
}
