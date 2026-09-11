// Government dashboard: one question per tab rather than one dense page.
import { useState } from "react";
import { getDashboard } from "../../api/dashboard";
import { ErrorNotice } from "../../components/common/ErrorNotice";
import { Loader } from "../../components/common/Loader";
import { TabPanel } from "../../components/common/Tabs";
import { Masthead } from "../../components/layout/Masthead";
import { usePolling } from "../../hooks/usePolling";
import { OverviewPanel } from "./panels/OverviewPanel";
import { PriorityPanel } from "./panels/PriorityPanel";
import { SensorRiskPanel } from "./panels/SensorRiskPanel";
import { TrendsPanel } from "./panels/TrendsPanel";

const TABS = [
  { id: "overview", label: "Overview" },
  { id: "priority", label: "Priority Queue" },
  { id: "sensors", label: "Sensors" },
  { id: "trends", label: "Trends" },
];

export default function GovernmentDashboard({ initialTab = "overview" }) {
  const [tab, setTab] = useState(initialTab);
  // Held here rather than in each panel so the selection survives tab switches.
  const [state, setState] = useState(null);
  // Unchanged: the same poll that has always driven this screen. Tab state is
  // local, so switching never refetches or re-enters the router.
  const { data, error, loading } = usePolling(() => getDashboard(state), { deps: [state] });

  if (loading && !data) return <Loader label="Loading mines..." />;

  return (
    <>
      <Masthead
        title="Multi-mine overview"
        subtitle={
          state
            ? `${data?.stats?.mine_count ?? 0} monitored mines in ${state}`
            : `${data?.stats?.mine_count ?? 0} mines monitored nationwide`
        }
        tabs={TABS}
        active={tab}
        onTabChange={setTab}
      />

      <div className="content">
        <ErrorNotice error={error} />

        <TabPanel id="overview" active={tab}>
          <OverviewPanel data={data} state={state} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="priority" active={tab}>
          <PriorityPanel state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="sensors" active={tab}>
          <SensorRiskPanel state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="trends" active={tab}>
          <TrendsPanel
            state={state}
            title={state ? `${state} breach frequency` : "National breach frequency"}
            caption={
              state
                ? `This chart covers every monitored mine in ${state}.`
                : "This chart aggregates every monitored mine in the country."
            }
          />
        </TabPanel>
      </div>
    </>
  );
}
