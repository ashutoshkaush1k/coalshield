// Role constants shared with the router. Lower case, as the Yii2 API returns them.
// normalizeUser() also accepts the old FastAPI backend's "GOVERNMENT" / "MINE_HEAD" until the
// frontend switches to the new API in Phase 2.
export const ROLES = {
  GOVERNMENT: "government",
  CORPORATE: "corporate",
  MINE_HEAD: "mine_head",
  INSPECTOR: "inspector",
};

export const normalizeRole = (role) => (typeof role === "string" ? role.toLowerCase() : role);
export const normalizeUser = (user) => (user ? { ...user, role: normalizeRole(user.role) } : user);

export const isGovernment = (user) => normalizeRole(user?.role) === ROLES.GOVERNMENT;
export const isCorporate = (user) => normalizeRole(user?.role) === ROLES.CORPORATE;
export const isMineHead = (user) => normalizeRole(user?.role) === ROLES.MINE_HEAD;

// Where each role lands after login. The API decides what they can see; this only decides
// which screen to open first.
export const homeFor = (user) => (isMineHead(user) ? "/mine" : "/gov");
