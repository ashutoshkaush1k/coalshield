// i18next set-up. English only for now; Phase 6 adds hi, bn, or, te, mr (the API's
// preferred_language values). All new UI uses translation keys from here on.
import i18n from "i18next";
import { initReactI18next } from "react-i18next";
import en from "./locales/en.json";

export const LANGUAGES = ["en"];

i18n.use(initReactI18next).init({
  resources: { en: { translation: en } },
  lng: "en",
  fallbackLng: "en",
  interpolation: { escapeValue: false }, // React already escapes
  returnNull: false,
});

export default i18n;
