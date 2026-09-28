// Route table: /login and the public /grievance pages, /gov/* for Government, /mine/* for Mine Head.
import { Navigate, Route, Routes } from "react-router-dom";
import { ProtectedRoute } from "../auth/ProtectedRoute";
import { ROLES, homeFor } from "../auth/roles";

// Roles that see more than one mine share the overview screens; the API scopes the data
// (government and inspector: all mines, corporate: its company's mines).
const MULTI_MINE = [ROLES.GOVERNMENT, ROLES.CORPORATE, ROLES.INSPECTOR];
import { AppShell } from "../components/layout/AppShell";
import { useAuth } from "../hooks/useAuth";
import Login from "../pages/Login";
import NotFound from "../pages/NotFound";
import InspectionPriority from "../pages/government/InspectionPriority";
import MineDetail from "../pages/government/MineDetail";
import Overview from "../pages/government/Overview";
import MineHeadDashboard from "../pages/minehead/Dashboard";
import RaiseGrievance from "../pages/public/RaiseGrievance";
import TrackGrievance from "../pages/public/TrackGrievance";
import Profile from "../pages/Profile";
import FieldApp from "../field/FieldApp";
import { t } from "../i18n/t";

function Home() {
  const { user, loading } = useAuth();
  if (loading) return <div className="empty">{t("common.loading")}</div>;
  return <Navigate to={user ? homeFor(user) : "/login"} replace />;
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      {/* Public, no login (brief Phase 5) */}
      <Route path="/grievance" element={<RaiseGrievance />} />
      <Route path="/grievance/track" element={<TrackGrievance />} />
      {/* Phase 7B: the offline field app - its own sign-in, works with no network after the first */}
      <Route path="/field/*" element={<FieldApp />} />
      <Route path="/" element={<Home />} />

      <Route
        element={
          <ProtectedRoute>
            <AppShell />
          </ProtectedRoute>
        }
      >
        <Route
          path="/gov"
          element={<ProtectedRoute allow={MULTI_MINE}><Overview /></ProtectedRoute>}
        />
        <Route
          path="/gov/inspections"
          element={<ProtectedRoute allow={MULTI_MINE}><InspectionPriority /></ProtectedRoute>}
        />
        <Route
          path="/gov/mines/:mineId"
          element={<ProtectedRoute allow={MULTI_MINE}><MineDetail /></ProtectedRoute>}
        />
        <Route path="/profile" element={<Profile />} />
        <Route
          path="/mine"
          element={<ProtectedRoute allow={[ROLES.MINE_HEAD]}><MineHeadDashboard /></ProtectedRoute>}
        />
      </Route>

      <Route path="*" element={<NotFound />} />
    </Routes>
  );
}
