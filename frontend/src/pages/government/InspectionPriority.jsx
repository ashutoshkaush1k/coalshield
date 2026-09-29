// Deep link to the ranked queue: /gov/inspections keeps working and opens the government home on
// its Risk Ranking tab (the active tab lives in the URL, components/layout/navTabs.js).
import { Navigate } from "react-router-dom";

export default function InspectionPriority() {
  return <Navigate to="/gov?tab=ranking" replace />;
}
