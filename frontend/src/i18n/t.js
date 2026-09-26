// Translation helpers.
//   t("demo.footer")                   plain function, usable outside components
//   useT()                             hook for components (re-renders on language change)
//   errorMessage(err)                  a normalised API error (api/client.js) -> text
import { useTranslation } from "react-i18next";
import i18n from "./index";

export const t = (key, params) => i18n.t(key, params);

export function useT() {
  return useTranslation().t;
}

/** Text for an error from api/client.js: {code, params, fields} from the API's error envelope. */
export function errorMessage(error) {
  if (!error) return "";
  if (error.isNetwork) return t("errors.NETWORK");
  const code = error.code;
  let text = code && i18n.exists(`errors.${code}`) ? t(`errors.${code}`, error.params) : t("errors.UNKNOWN");
  const fields = error.fields ? Object.entries(error.fields) : [];
  if (fields.length) {
    text += " " + fields
      .map(([field, codes]) => `${field.replace(/_/g, " ")}: ${codes.map((c) => t(`fields.${c}`, { defaultValue: c })).join(", ")}`)
      .join("; ");
  }
  return text;
}
