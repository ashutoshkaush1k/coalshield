// Page frame: sidebar, topbar, content outlet.
import { Outlet } from "react-router-dom";
import { useAuth } from "../../hooks/useAuth";
import { ROLES, normalizeRole } from "../../auth/roles";
import { DemoFooter } from "../common/DemoFooter";
import { useT } from "../../i18n/t";

// Scope line under the user name: what the API lets this account see.
function scopeLabel(t, user) {
  const role = normalizeRole(user?.role);
  const name = t(`roles.${role}`, { defaultValue: role || "" });
  if (role === ROLES.MINE_HEAD) return t("shell.scopeMine", { role: name, name: user?.mine_name ?? `#${user?.mine_id}` });
  if (role === ROLES.CORPORATE) return t("shell.scopeSubsidiary", { role: name, code: user?.subsidiary_code ?? "" });
  return t("shell.scopeAll", { role: name });
}

export function AppShell() {
  const { user, signOut } = useAuth();
  const t = useT();

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <strong>Smart Mine Governance</strong>
          <span>Problem statement SIH26024</span>
        </div>
        <div className="foot">
          <div className="who">{user?.full_name || user?.email}</div>
          <div className="role">{scopeLabel(t, user)}</div>
          <button onClick={signOut}>{t("shell.signOut")}</button>
        </div>
      </aside>

      <div className="main">
        <Outlet />
        <DemoFooter />
      </div>
    </div>
  );
}
