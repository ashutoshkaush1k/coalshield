// Footer on signed-in screens: appears only when the automated detection is running on the PHP
// fallback (Phase 8) - polled every 30 s, apart from the screens' own 10 s poll. The data credits
// (Global Energy Monitor, DataMeet, OpenStreetMap) stay on the map's own attribution line.
import { getSystemStatus } from "../../api/system";
import { useAuth } from "../../hooks/useAuth";
import { usePolling } from "../../hooks/usePolling";
import { useT } from "../../i18n/t";

function FallbackNotice() {
  const t = useT();
  const { data } = usePolling(getSystemStatus, { interval: 30000 });
  if (!data || data.detection_engine !== "php") return null;
  return (
    <footer className="site-footer">
      <div className="system-warning" role="status" id="detection-fallback">
        <strong>{t("system.fallbackTitle")}</strong>{" "}
        {data.reason === "CONFIGURED_PHP" ? t("system.fallbackConfigured") : t("system.fallbackDown")}
      </div>
    </footer>
  );
}

export function SiteFooter() {
  const { user } = useAuth();
  return user ? <FallbackNotice /> : null;
}
