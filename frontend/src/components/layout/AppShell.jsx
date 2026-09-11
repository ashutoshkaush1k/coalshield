// Page frame: sidebar, topbar, content outlet.
import { Outlet } from "react-router-dom";
import { useAuth } from "../../hooks/useAuth";
import { isGovernment } from "../../auth/roles";

export function AppShell() {
  const { user, signOut } = useAuth();

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <strong>Smart Mine Governance</strong>
          <span>Problem statement SIH26024</span>
        </div>
        <div className="foot">
          <div className="who">{user?.full_name || user?.email}</div>
          <div className="role">
            {isGovernment(user) ? "Government \u00b7 all mines" : `Mine Head \u00b7 mine #${user?.mine_id}`}
          </div>
          <button onClick={signOut}>Sign out</button>
        </div>
      </aside>

      <div className="main">
        <Outlet />
      </div>
    </div>
  );
}
