// i18next set-up (Phase 6): English plus hi, bn, or, te, mr - the API's preferred_language values.
//
// Which language shows:
//   - signed in: the account's saved preferred_language (AuthContext applies it; the Profile page
//     changes it through PATCH /v1/users/me);
//   - signed out (login, public grievance pages): the language chosen in the switcher, kept in this
//     browser (localStorage), else English.
// The non-English locales are drafts (each has "_meta": {"reviewed": false}); a key missing in a
// locale falls back to English, and scripts/check_locales.mjs fails the test run if one is missing.
import i18n from "i18next";
import { initReactI18next } from "react-i18next";
import bn from "./locales/bn.json";
import en from "./locales/en.json";
import hi from "./locales/hi.json";
import mr from "./locales/mr.json";
import or from "./locales/or.json";
import te from "./locales/te.json";

/** Each language in its own script, so a reader finds theirs without reading English. */
export const LANGUAGES = [
  { code: "en", native: "English" },
  { code: "hi", native: "हिन्दी" },
  { code: "bn", native: "বাংলা" },
  { code: "or", native: "ଓଡ଼ିଆ" },
  { code: "te", native: "తెలుగు" },
  { code: "mr", native: "मराठी" },
];
const CODES = LANGUAGES.map((l) => l.code);
const STORAGE_KEY = "smg.lang";

export const isLanguage = (code) => CODES.includes(code);

/** The language chosen on this browser before login (or English). */
export function browserLanguage() {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);
    return isLanguage(stored) ? stored : "en";
  } catch {
    return "en";
  }
}

/** Switch the UI language; `remember` keeps it in this browser for the signed-out pages. */
export function setLanguage(code, { remember = false } = {}) {
  if (!isLanguage(code)) return;
  if (remember) {
    try {
      window.localStorage.setItem(STORAGE_KEY, code);
    } catch {
      // private mode: the choice lasts for this page only
    }
  }
  if (i18n.language !== code) i18n.changeLanguage(code);
}

i18n.use(initReactI18next).init({
  resources: Object.fromEntries(Object.entries({ en, hi, bn, or, te, mr }).map(([code, translation]) => [code, { translation }])),
  lng: browserLanguage(),
  fallbackLng: "en",
  interpolation: { escapeValue: false }, // React already escapes
  returnNull: false,
});

// <html lang> follows the UI language: screen readers pronounce it, and CSS can select on it.
const applyLang = (lng) => document.documentElement.setAttribute("lang", lng);
applyLang(i18n.language);
i18n.on("languageChanged", applyLang);

export default i18n;
