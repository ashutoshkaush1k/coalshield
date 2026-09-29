// Page frame: navigation drawer (opened from the hamburger in each page's top bar), content outlet.
// The content uses the full width; nothing is docked on the left.
import { Outlet } from "react-router-dom";
import { useAuth } from "../../hooks/useAuth";
import { ROLES, normalizeRole } from "../../auth/roles";
import { SiteFooter } from "../common/SiteFooter";
import { useT } from "../../i18n/t";
import { NavDrawer, NavProvider } from "./Nav";

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
    <NavProvider>
      <div className="shell">
        <NavDrawer who={user?.full_name || user?.email} scope={scopeLabel(t, user)} onSignOut={signOut} />
        <div className="main">
          <Outlet />
          <SiteFooter />
        </div>
      </div>
    </NavProvider>
  );
}
