// Mine Head dashboard: own mine only, two tabs.
//
// There is deliberately no Priority Queue tab. Cross-mine ranking is authority-only
// (PRD 4.1), and it is refused server-side as well - GET /api/v1/inspections returns
// 403 for this role. Hiding the tab removes the entry point; the API is the boundary.
import { useState } from "react";
import { ErrorNotice } from "../../components/common/ErrorNotice";
import { Loader } from "../../components/common/Loader";
import { TabPanel } from "../../components/common/Tabs";
import { Masthead } from "../../components/layout/Masthead";
import { useAuth } from "../../hooks/useAuth";
import { usePolling } from "../../hooks/usePolling";
import { loadMineBundle } from "../government/MineDetail";
import { TrendsPanel } from "../government/panels/TrendsPanel";
import { MineOverviewPanel } from "./panels/MineOverviewPanel";
import { SensorPerformancePanel } from "./panels/SensorPerformancePanel";

const TABS = [
  { id: "overview", label: "Overview" },
  { id: "sensors", label: "Sensors" },
  { id: "trends", label: "Trends" },
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
        subtitle={`${data.mine.location}, operated by ${data.mine.operator}`}
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
