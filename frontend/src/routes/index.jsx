// Route table: /login, /gov/* for Government, /mine/* for Mine Head.
import { Navigate, Route, Routes } from "react-router-dom";
import { ProtectedRoute } from "../auth/ProtectedRoute";
import { ROLES, homeFor } from "../auth/roles";
import { AppShell } from "../components/layout/AppShell";
import { useAuth } from "../hooks/useAuth";
import Login from "../pages/Login";
import NotFound from "../pages/NotFound";
import InspectionPriority from "../pages/government/InspectionPriority";
import MineDetail from "../pages/government/MineDetail";
import Overview from "../pages/government/Overview";
import MineHeadDashboard from "../pages/minehead/Dashboard";

function Home() {
  const { user, loading } = useAuth();
  if (loading) return <div className="empty">Loading...</div>;
  return <Navigate to={user ? homeFor(user) : "/login"} replace />;
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
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
          element={<ProtectedRoute allow={[ROLES.GOVERNMENT]}><Overview /></ProtectedRoute>}
        />
        <Route
          path="/gov/inspections"
          element={<ProtectedRoute allow={[ROLES.GOVERNMENT]}><InspectionPriority /></ProtectedRoute>}
        />
        <Route
          path="/gov/mines/:mineId"
          element={<ProtectedRoute allow={[ROLES.GOVERNMENT]}><MineDetail /></ProtectedRoute>}
        />
        <Route
          path="/mine"
          element={<ProtectedRoute allow={[ROLES.MINE_HEAD]}><MineHeadDashboard /></ProtectedRoute>}
        />
      </Route>

      <Route path="*" element={<NotFound />} />
    </Routes>
  );
}
