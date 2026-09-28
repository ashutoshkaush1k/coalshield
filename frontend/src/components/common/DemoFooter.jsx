// Standing notice on every screen: the figures are synthetic, and the mine data is GEM's
// (attribution required by CC BY 4.0 wherever mine names or locations are shown). Signed in, it
// also warns when the automated detection is running on the PHP fallback (Phase 8): polled every
// 30 s, apart from the screens' own 10 s poll.
import { getSystemStatus } from "../../api/system";
import { useAuth } from "../../hooks/useAuth";
import { usePolling } from "../../hooks/usePolling";
import { useT } from "../../i18n/t";

function FallbackNotice() {
  const t = useT();
  const { data } = usePolling(getSystemStatus, { interval: 30000 });
  if (!data || data.detection_engine !== "php") return null;
  return (
    <div className="system-warning" role="status" id="detection-fallback">
      <strong>{t("system.fallbackTitle")}</strong>{" "}
      {data.reason === "CONFIGURED_PHP" ? t("system.fallbackConfigured") : t("system.fallbackDown")}
    </div>
  );
}

export function DemoFooter() {
  const t = useT();
  const { user } = useAuth();
  return (
    <footer className="demo-footer">
      {user && <FallbackNotice />}
      {t("demo.footer")}
      <span className="demo-attribution">{t("demo.attribution")}</span>
    </footer>
  );
}
