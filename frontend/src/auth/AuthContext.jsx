// Holds token, role, and mine_id; exposes login/logout, and the account's language.
import { createContext, useCallback, useEffect, useMemo, useState } from "react";
import * as authApi from "../api/auth";
import { DEMO_ENDED_EVENT, clearToken, getToken, isDemoSession } from "../api/client";
import { browserLanguage, setLanguage } from "../i18n";
import { normalizeUser } from "./roles";

export const AuthContext = createContext(null);

// After login the account's saved language wins over the one chosen in this browser.
const applyAccountLanguage = (account) => {
  if (account?.preferred_language) setLanguage(account.preferred_language);
  return account;
};

/** Seconds-since-epoch expiry of a JWT (not verified - the API does that). */
const tokenExpiry = (token) => {
  try {
    const part = token.split(".")[1].replace(/-/g, "+").replace(/_/g, "/");
    return JSON.parse(atob(part.padEnd(part.length + ((4 - (part.length % 4)) % 4), "="))).exp ?? null;
  } catch {
    return null;
  }
};

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  // A demo session (POST /v1/auth/demo) that has run out: components/auth/DemoAccess.jsx offers a new one.
  const [demoEnded, setDemoEnded] = useState(false);

  useEffect(() => {
    const onEnded = () => setDemoEnded(true);
    window.addEventListener(DEMO_ENDED_EVENT, onEnded);
    return () => window.removeEventListener(DEMO_ENDED_EVENT, onEnded);
  }, []);

  // A demo session ends at its token's expiry even if the page makes no request then.
  useEffect(() => {
    if (!user?.is_demo) return undefined;
    const exp = tokenExpiry(getToken() ?? "");
    if (!exp) return undefined;
    const id = window.setTimeout(() => { clearToken(); setDemoEnded(true); }, Math.max(0, exp * 1000 - Date.now()));
    return () => window.clearTimeout(id);
  }, [user]);

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
      // An ended demo session keeps its mark, so the "demo session has ended" popup can offer a new
      // one (api/client.js has already removed the token); any other failure signs out.
      .catch(() => { if (!isDemoSession()) authApi.logout(); })
      .finally(() => setLoading(false));
  }, []);

  const signIn = useCallback(async (email, password) => {
    const account = applyAccountLanguage(normalizeUser(await authApi.login(email, password)));
    setUser(account);
    return account;
  }, []);

  /** "Continue as admin (demo)": the demo admin account, a fresh 30-minute session. */
  const signInDemo = useCallback(async () => {
    const account = applyAccountLanguage(normalizeUser(await authApi.demoLogin()));
    setUser(account);
    setDemoEnded(false);
    return account;
  }, []);

  const signOut = useCallback(() => {
    authApi.logout();
    setUser(null);
    setDemoEnded(false);
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

  const value = useMemo(() => ({ user, loading, signIn, signInDemo, signOut, changeLanguage, demoEnded }),
    [user, loading, signIn, signInDemo, signOut, changeLanguage, demoEnded]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
