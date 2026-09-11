// GOVERNMENT and MINE_HEAD constants shared with the router.
export const ROLES = { GOVERNMENT: "GOVERNMENT", MINE_HEAD: "MINE_HEAD" };

export const isGovernment = (user) => user?.role === ROLES.GOVERNMENT;
export const isMineHead = (user) => user?.role === ROLES.MINE_HEAD;

// Where each role lands after login. The API decides what they can see; this only decides
// which screen to open first.
export const homeFor = (user) => (isGovernment(user) ? "/gov" : "/mine");
