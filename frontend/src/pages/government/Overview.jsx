// Government dashboard: one question per tab rather than one dense page.
import { useState } from "react";
import { getOverviewView } from "../../api/views";
import { ErrorNotice } from "../../components/common/ErrorNotice";
import { Loader } from "../../components/common/Loader";
import { TabPanel } from "../../components/common/Tabs";
import { Masthead } from "../../components/layout/Masthead";
import { usePolling } from "../../hooks/usePolling";
import { OverviewPanel } from "./panels/OverviewPanel";
import { useAuth } from "../../hooks/useAuth";
import { scopeWhere } from "../../i18n/labels";
import { PriorityPanel } from "./panels/PriorityPanel";
import { SensorRiskPanel } from "./panels/SensorRiskPanel";
import { TrendsPanel } from "./panels/TrendsPanel";
import { ContractorsPanel } from "./panels/ContractorsPanel";
import { ProductionPanel } from "./panels/ProductionPanel";
import { GrievancesPanel } from "./panels/GrievancesPanel";
import { ObligationsPanel } from "./panels/ObligationsPanel";
import { MapPanel } from "../../components/map/MapPanel";
import { t } from "../../i18n/t";

const TABS = [
  { id: "overview", label: "Overview" },
  { id: "priority", label: "Priority Queue" },
  { id: "sensors", label: "Sensors" },
  { id: "trends", label: "Trends" },
  { id: "production", label: t("production.tabLabel") },
  { id: "contractors", label: t("contractor.tabLabel") },
  { id: "grievances", label: t("grievance.tabLabel") },
  { id: "obligations", label: t("obligation.tabLabel") },
  { id: "map", label: t("map.tabLabel") },
];

export default function GovernmentDashboard({ initialTab = "overview" }) {
  const [tab, setTab] = useState(initialTab);
  const { user } = useAuth();
  // Held here rather than in each panel so the selection survives tab switches.
  const [state, setState] = useState(null);
  // One request per polling cycle for the dashboard and the contractor summary
  // (GET /v1/views/overview). Tab state is local, so switching never refetches.
  const { data: view, error, loading } = usePolling(() => getOverviewView(state), { deps: [state] });
  const data = view?.dashboard;
  const contractorSummary = view?.contractor_summary ?? null;

  if (loading && !view) return <Loader label="Loading mines..." />;

  return (
    <>
      <Masthead
        title="Multi-mine overview"
        subtitle={
          state
            ? `${data?.stats?.mine_count ?? 0} monitored mines in ${state}`
            : `${data?.stats?.mine_count ?? 0} mines monitored ${scopeWhere(user)}`
        }
        tabs={TABS}
        active={tab}
        onTabChange={setTab}
      />

      <div className="content">
        <ErrorNotice error={error} />

        <TabPanel id="overview" active={tab}>
          <OverviewPanel data={data} contractorSummary={contractorSummary} state={state} onStateChange={setState} onOpenContractors={() => setTab("contractors")} />
        </TabPanel>

        <TabPanel id="priority" active={tab}>
          <PriorityPanel state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="sensors" active={tab}>
          <SensorRiskPanel state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="production" active={tab}>
          <ProductionPanel state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="grievances" active={tab}>
          <GrievancesPanel state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="obligations" active={tab}>
          <ObligationsPanel state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="map" active={tab}>
          <MapPanel state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="contractors" active={tab}>
          <ContractorsPanel summary={contractorSummary} state={state} states={data?.states} onStateChange={setState} />
        </TabPanel>

        <TabPanel id="trends" active={tab}>
          <TrendsPanel
            state={state}
            title={state ? `${state} breach frequency` : `Breach frequency ${scopeWhere(user)}`}
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
