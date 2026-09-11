// Holds token, role, and mine_id; exposes login/logout.
import { createContext, useCallback, useEffect, useMemo, useState } from "react";
import * as authApi from "../api/auth";
import { getToken } from "../api/client";

export const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  // A token in localStorage is not proof of a valid session - it may be expired or the account
  // may be gone - so it is always re-validated against /auth/me on boot.
  useEffect(() => {
    if (!getToken()) {
      setLoading(false);
      return;
    }
    authApi
      .me()
      .then(setUser)
      .catch(() => authApi.logout())
      .finally(() => setLoading(false));
  }, []);

  const signIn = useCallback(async (email, password) => {
    const account = await authApi.login(email, password);
    setUser(account);
    return account;
  }, []);

  const signOut = useCallback(() => {
    authApi.logout();
    setUser(null);
  }, []);

  const value = useMemo(() => ({ user, loading, signIn, signOut }), [user, loading, signIn, signOut]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
