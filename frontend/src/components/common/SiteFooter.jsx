// Footer on every screen. The data sources whose licences require credit (Global Energy Monitor's
// mine data, DataMeet's boundaries, OpenStreetMap's tiles on the map) sit behind one small "Data
// sources" link. Signed in, it also warns when the automated detection is running on the PHP
// fallback (Phase 8): polled every 30 s, apart from the screens' own 10 s poll.
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

export function SiteFooter() {
  const t = useT();
  const { user } = useAuth();
  return (
    <footer className="site-footer">
      {user && <FallbackNotice />}
      <details className="data-sources" id="data-sources">
        <summary>{t("footer.dataSources")}</summary>
        <ul>
          <li>{t("footer.sourceGem")}</li>
          <li>{t("map.attributionDatameet")}</li>
          <li>{t("footer.sourceOsm")}</li>
        </ul>
      </details>
    </footer>
  );
}
