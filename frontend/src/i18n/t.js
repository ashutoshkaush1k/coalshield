// Translation helpers.
//   t("demo.footer")                   plain function, usable outside components
//   useT()                             hook for components (re-renders on language change)
//   errorMessage(err)                  API error envelope {"error": {"code", "params"}} -> text
import { useTranslation } from "react-i18next";
import i18n from "./index";

export const t = (key, params) => i18n.t(key, params);

export function useT() {
  return useTranslation().t;
}

export function errorMessage(error) {
  const code = error?.response?.data?.error?.code;
  const params = error?.response?.data?.error?.params;
  if (code && i18n.exists(`errors.${code}`)) return t(`errors.${code}`, params);
  return t("errors.UNKNOWN");
}
