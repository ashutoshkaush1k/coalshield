// One mine's standing: score, band, alerts and its records - no cross-mine comparison.
//
// Detail opens in drawers and actions open in modals, so the page stays a summary the
// operator can read at a glance rather than a long scroll.
import { useState } from "react";
import { isOpenDirective } from "../../../api/alerts";
import { can } from "../../../auth/permissions";
import { AlertDetailDrawer } from "../../../components/alerts/AlertDetailDrawer";
import { AlertList } from "../../../components/alerts/AlertList";
import { ResolveDirectiveForm } from "../../../components/alerts/ResolveDirectiveForm";
import { MineRecords } from "../../../components/records/MineRecords";
import { RiskPanel } from "../../../components/risk/RiskPanel";
import { useAuth } from "../../../hooks/useAuth";
import { useT } from "../../../i18n/t";
import { ComplianceSummary } from "../../government/MineDetail";

export function MineOverviewPanel({ bundle, mineId, onAnalysed, onChanged }) {
  const t = useT();
  const { user } = useAuth();
  const { mine, alerts } = bundle;
  const [selectedAlert, setSelectedAlert] = useState(null);

  const openDirectives = alerts.filter(isOpenDirective).length;
  // Read from the live list so a drawer left open updates with the next poll.
  const liveAlert = selectedAlert && alerts.find((a) => a.id === selectedAlert.id);

  return (
    <div className="stack">
      <div className="grid-12">
        <section className="panel-block fill span-8">
          <div className="panel-body">
            <ComplianceSummary mine={mine} gri={bundle.risk?.governance_risk} />
            <MineRecords bundle={bundle} onChanged={onChanged} />
          </div>
        </section>

        <section className="panel-block fill span-4">
          <div className="panel-head">
            <div>
              <h2>{t("mine.alertsAndDirectives")}</h2>
              <div className="hint">{t("mine.openDirectivesFromDgms", { count: openDirectives })}</div>
            </div>
          </div>
          <div className="panel-body flush alerts-body">
            <AlertList
              alerts={alerts}
              onSelect={setSelectedAlert}
              actionFor={(alert) =>
                isOpenDirective(alert) && can(user, "alert.resolve")
                  ? <ResolveDirectiveForm alert={alert} onResolved={() => onChanged?.()} />
                  : null
              }
            />
          </div>
        </section>
      </div>

      <RiskPanel risk={bundle.risk} />

      <AlertDetailDrawer alert={liveAlert} onClose={() => setSelectedAlert(null)} />
    </div>
  );
}
