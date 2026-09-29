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
import { fmtNumber } from "../../utils/format";

// Built at render, so the labels follow a language switch.
// Five tabs on the bar, the rest in the navigation drawer.
const BAR_TABS = ["overview", "priority", "obligations", "production", "map"];
const tabs = () => [
  { id: "overview", label: t("tabs.overview") },
  { id: "priority", label: t("tabs.priority") },
  { id: "sensors", label: t("tabs.sensors") },
  { id: "trends", label: t("tabs.trends") },
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

  if (loading && !view) return <Loader label={t("overview.loading")} />;

  return (
    <>
      <Masthead
        title={t("overview.title")}
        subtitle={
          state
            ? t("overview.subtitleState", { n: fmtNumber(data?.stats?.mine_count ?? 0, 0), state })
            : t("overview.subtitleScope", { n: fmtNumber(data?.stats?.mine_count ?? 0, 0), where: scopeWhere(user) })
        }
        tabs={tabs()}
        barIds={BAR_TABS}
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
            title={state ? t("trends.titleState", { state }) : t("trends.titleScope", { where: scopeWhere(user) })}
            caption={state ? t("trends.captionState", { state }) : t("trends.captionScope")}
          />
        </TabPanel>
      </div>
    </>
  );
}
