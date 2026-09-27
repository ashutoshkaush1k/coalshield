// Mine Head dashboard: own mine only, two tabs.
//
// There is deliberately no Priority Queue tab. Cross-mine ranking is for multi-mine roles
// (PRD 4.1), and it is refused server-side as well - GET /v1/inspections/priority returns
// 403 for this role. Hiding the tab removes the entry point; the API is the boundary.
import { useState } from "react";
import { ErrorNotice } from "../../components/common/ErrorNotice";
import { Loader } from "../../components/common/Loader";
import { TabPanel } from "../../components/common/Tabs";
import { Masthead } from "../../components/layout/Masthead";
import { useAuth } from "../../hooks/useAuth";
import { usePolling } from "../../hooks/usePolling";
import { loadMineBundle, mineSubtitle } from "../government/MineDetail";
import { TrendsPanel } from "../government/panels/TrendsPanel";
import { MineOverviewPanel } from "./panels/MineOverviewPanel";
import { SensorPerformancePanel } from "./panels/SensorPerformancePanel";
import { ContractorsPanel } from "./panels/ContractorsPanel";
import { ProductionPanel } from "./panels/ProductionPanel";
import { GrievancePanel } from "./panels/GrievancePanel";
import { ObligationsPanel } from "./panels/ObligationsPanel";
import { MapPanel } from "../../components/map/MapPanel";
import { t } from "../../i18n/t";

const TABS = [
  { id: "overview", label: "Overview" },
  { id: "sensors", label: "Sensors" },
  { id: "trends", label: "Trends" },
  { id: "production", label: t("production.tabLabel") },
  { id: "contractors", label: t("contractor.tabLabel") },
  { id: "grievances", label: t("grievance.tabLabel") },
  { id: "obligations", label: t("obligation.tabLabel") },
  { id: "map", label: t("map.tabLabel") },
];

export default function MineHeadDashboard() {
  const { user } = useAuth();
  const [tab, setTab] = useState("overview");
  // Bumped after a detection so the view refetches immediately rather than waiting
  // out the polling interval.
  const [refreshToken, setRefreshToken] = useState(0);
  const mineId = user?.mine_id;

  const { data, error, loading } = usePolling(() => loadMineBundle(mineId), {
    deps: [mineId, refreshToken],
    enabled: Boolean(mineId),
  });

  if (!mineId) {
    return (
      <div className="content">
        <div className="notice error">
          This account is not mapped to a mine. Contact the administrator.
        </div>
      </div>
    );
  }

  if (loading && !data) return <Loader label="Loading your mine..." />;
  if (error && !data) {
    return (
      <>
        <Masthead title="My mine" />
        <div className="content"><ErrorNotice error={error} /></div>
      </>
    );
  }

  return (
    <>
      <Masthead
        title={data.mine.name}
        subtitle={mineSubtitle(data.mine)}
        tabs={TABS}
        active={tab}
        onTabChange={setTab}
      />

      <div className="content">
        <ErrorNotice error={error} />

        <TabPanel id="overview" active={tab}>
          <MineOverviewPanel
            bundle={data}
            mineId={mineId}
            onAnalysed={() => setRefreshToken((n) => n + 1)}
            onChanged={() => setRefreshToken((n) => n + 1)}
          />
        </TabPanel>

        <TabPanel id="sensors" active={tab}>
          <SensorPerformancePanel mineId={mineId} />
        </TabPanel>

        <TabPanel id="production" active={tab}>
          <ProductionPanel />
        </TabPanel>

        <TabPanel id="contractors" active={tab}>
          <ContractorsPanel mineId={mineId} />
        </TabPanel>

        <TabPanel id="grievances" active={tab}>
          <GrievancePanel />
        </TabPanel>

        <TabPanel id="obligations" active={tab}>
          <ObligationsPanel />
        </TabPanel>

        <TabPanel id="map" active={tab}>
          <MapPanel onOpenMine={() => setTab("overview")} />
        </TabPanel>

        <TabPanel id="trends" active={tab}>
          <TrendsPanel
            mineId={mineId}
            title="Breach frequency at this site"
            caption="This chart covers your mine only."
          />
        </TabPanel>
      </div>
    </>
  );
}
