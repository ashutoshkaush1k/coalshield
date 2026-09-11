// Deep link to the ranked queue. Renders the same dashboard with the Priority
// Queue tab already selected, so /gov/inspections keeps working without a second
// design of the same screen.
import GovernmentDashboard from "./Overview";

export default function InspectionPriority() {
  return <GovernmentDashboard initialTab="priority" />;
}
