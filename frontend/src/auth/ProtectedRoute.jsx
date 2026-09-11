// Route guard. UI convenience only - the API is the real boundary (PRD 4.2).
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "../hooks/useAuth";
import { homeFor } from "./roles";

export function ProtectedRoute({ children, allow }) {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) return <div className="empty">Loading...</div>;
  if (!user) return <Navigate to="/login" state={{ from: location }} replace />;

  // Hiding a route is presentation, not security: the same request made directly against the API
  // is rejected server-side. This only avoids showing a screen that would fail.
  if (allow && !allow.includes(user.role)) return <Navigate to={homeFor(user)} replace />;

  return children;
}
