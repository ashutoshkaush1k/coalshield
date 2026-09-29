// Translation helpers.
//   t("nav.menu")                      plain function, usable outside components
//   useT()                             hook for components (re-renders on language change)
//   errorMessage(err)                  a normalised API error (api/client.js) -> text
import { useTranslation } from "react-i18next";
import i18n from "./index";

export const t = (key, params) => i18n.t(key, params);

export function useT() {
  return useTranslation().t;
}

/** The name of a field an API error points at: its label, else the production form's, else the name. */
const fieldName = (field) =>
  t(`fieldName.${field}`, { defaultValue: t(`production.field.${field}`, { defaultValue: field.replace(/_/g, " ") }) });

/** Text for an error from api/client.js: {code, params, fields} from the API's error envelope. */
export function errorMessage(error) {
  if (!error) return "";
  if (error.isNetwork) return t("errors.NETWORK");
  const code = error.code;
  let text = code && i18n.exists(`errors.${code}`) ? t(`errors.${code}`, error.params) : t("errors.UNKNOWN");
  const fields = error.fields ? Object.entries(error.fields) : [];
  if (fields.length) {
    text += " " + fields
      .map(([field, codes]) => `${fieldName(field)}: ${codes.map((c) => t(`fields.${c}`, { defaultValue: c })).join(", ")}`)
      .join("; ");
  }
  return text;
}
