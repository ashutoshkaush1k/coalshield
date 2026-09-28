// Holds token, role, and mine_id; exposes login/logout, and the account's language.
import { createContext, useCallback, useEffect, useMemo, useState } from "react";
import * as authApi from "../api/auth";
import { getToken } from "../api/client";
import { browserLanguage, setLanguage } from "../i18n";
import { normalizeUser } from "./roles";

export const AuthContext = createContext(null);

// After login the account's saved language wins over the one chosen in this browser.
const applyAccountLanguage = (account) => {
  if (account?.preferred_language) setLanguage(account.preferred_language);
  return account;
};

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
      .then((account) => setUser(applyAccountLanguage(normalizeUser(account))))
      .catch(() => authApi.logout())
      .finally(() => setLoading(false));
  }, []);

  const signIn = useCallback(async (email, password) => {
    const account = applyAccountLanguage(normalizeUser(await authApi.login(email, password)));
    setUser(account);
    return account;
  }, []);

  const signOut = useCallback(() => {
    authApi.logout();
    setUser(null);
    setLanguage(browserLanguage());
  }, []);

  /** Save the account's language (PATCH /v1/users/me) and switch to it at once. */
  const changeLanguage = useCallback(async (code) => {
    const previous = user?.preferred_language;
    setLanguage(code);
    try {
      const account = normalizeUser(await authApi.updateMe({ preferred_language: code }));
      setUser(account);
      return account;
    } catch (e) {
      if (previous) setLanguage(previous);
      throw e;
    }
  }, [user?.preferred_language]);

  const value = useMemo(() => ({ user, loading, signIn, signOut, changeLanguage }), [user, loading, signIn, signOut, changeLanguage]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
