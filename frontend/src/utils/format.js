// Date, number, and unit formatting - through Intl, in the UI language (Phase 6).
//
// Every locale formats as `<lang>-IN` with Latin digits (-u-nu-latn): Indian digit grouping
// (17,04,240) and the language's own month names, while scores, codes and counts keep the digits
// every reader already sees on ids and codes. (bn and mr default to their native digits; change
// DIGITS here to switch.)
import i18n from "../i18n";

const DIGITS = "latn";
const cache = new Map();
const supported = new Map();

// A browser may ship no data for a language: Chromium (Edge, Chrome) has none for Odia, and Intl
// then silently formats in English. Such a language formats as en-IN (Indian grouping), with the
// month names taken from the locale file (date.monthShort / date.monthLong).
const hasData = (lang) => {
  if (!supported.has(lang)) supported.set(lang, Intl.DateTimeFormat.supportedLocalesOf([`${lang}-IN`]).length > 0);
  return supported.get(lang);
};

/** The Intl locale for the UI language, e.g. "hi-IN-u-nu-latn" (en-IN where the browser lacks it). */
export const intlLocale = () => {
  const lang = i18n.language || "en";
  return `${hasData(lang) ? lang : "en"}-IN-u-nu-${DIGITS}`;
};

/** Dates in a language the browser has no data for: English parts, months from the locale file. */
class MonthNamesFormat {
  constructor(options) {
    this.inner = new Intl.DateTimeFormat(`en-IN-u-nu-${DIGITS}`, options);
    this.style = options.month === "long" ? "monthLong" : "monthShort";
  }
  format(d) {
    return this.inner.formatToParts(d)
      .map((p) => (p.type === "month" ? i18n.t(`date.${this.style}.${d.getMonth() + 1}`) : p.value)).join("");
  }
}

function formatter(kind, options) {
  const lang = i18n.language || "en";
  const key = `${lang}|${kind}|${JSON.stringify(options)}`;
  if (!cache.has(key)) {
    cache.set(key, kind === "number" ? new Intl.NumberFormat(intlLocale(), options)
      : options.month && !hasData(lang) ? new MonthNamesFormat(options) : new Intl.DateTimeFormat(intlLocale(), options));
  }
  return cache.get(key);
}

const valid = (iso) => {
  if (!iso) return null;
  const d = iso instanceof Date ? iso : new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d;
};

/** A number with Indian grouping; `digits` fixes the decimals (default: up to 1). */
export const fmtNumber = (n, digits = null) => {
  if (n === null || n === undefined || n === "" || Number.isNaN(Number(n))) return "-";
  const options = digits === null ? { maximumFractionDigits: 1 } : { minimumFractionDigits: digits, maximumFractionDigits: digits };
  return formatter("number", options).format(Number(n));
};

export const fmtScore = (n) => (n === null || n === undefined ? "-" : fmtNumber(n, 0));

/** 0.42 -> "42%" (a share); fmtPct(42.5) -> "42.5%" (already a percentage). */
/** A list in the UI language: "a, b and c" (and its own words for "and"). */
export const fmtList = (items) => new Intl.ListFormat(intlLocale(), { style: "long", type: "conjunction" }).format(items);
export const fmtPercent = (n) => formatter("number", { style: "percent", maximumFractionDigits: 0 }).format(n || 0);
export const fmtPct = (n) => (n === null || n === undefined ? "-" : formatter("number", { style: "percent", maximumFractionDigits: 1 }).format(Number(n) / 100));

export const fmtTime = (iso, options = { hour: "2-digit", minute: "2-digit" }) => {
  const d = valid(iso);
  return d ? formatter("date", options).format(d) : "-";
};

/** "28 Sept 13:35"-style: day, short month and time. */
export const fmtDateTime = (iso) => {
  const d = valid(iso);
  return d ? formatter("date", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }).format(d) : "-";
};

/** Day, short month, year and time - for records people cite later. */
export const fmtWhen = (iso) => {
  const d = valid(iso);
  return d ? formatter("date", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }).format(d) : "-";
};

/** A calendar date ("2026-09-28" or a Date), shown as a date only; `options` override the default. */
export const fmtDate = (value, options = { day: "2-digit", month: "short", year: "numeric" }) => {
  if (!value) return "-";
  const d = typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00Z`) : valid(value);
  return d ? formatter("date", { ...options, timeZone: typeof value === "string" && value.length === 10 ? "UTC" : undefined }).format(d) : "-";
};

/** A chart window's start: day, short month and hour. */
export const fmtDayHour = (value) => {
  const d = valid(value);
  return d ? formatter("date", { day: "2-digit", month: "short", hour: "2-digit" }).format(d) : "-";
};

/** A sensor reading: up to 3 decimals (a 0.75 % gas limit must not print as 0.8). */
export const fmtReading = (n) =>
  n === null || n === undefined || Number.isNaN(Number(n)) ? "-" : formatter("number", { maximumFractionDigits: 3 }).format(Number(n));

/** "2026-09" -> "September 2026". */
export const fmtMonth = (month) =>
  month ? formatter("date", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(`${month}-01T00:00:00Z`)) : "-";

/** A file size in KB or MB. */
export const fmtBytes = (bytes) => (bytes >= 1e6 ? `${fmtNumber(bytes / 1e6, 1)} MB` : `${fmtNumber(bytes / 1e3, 0)} KB`);

// "no_safety_vest" -> "No safety vest". The last resort for a token that has no translation key
// yet: English, but never raw snake_case on a dashboard.
export const humanise = (token) => {
  if (!token) return "";
  const words = String(token).replace(/_/g, " ").trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
};

// Scores count sensor breaches over a rolling window (BREACH_WINDOW_HOURS on the backend), so a
// bare "Breaches" beside the number reads as all-time. The API sends the window with the count;
// this puts it in the label. null or 0 = all-time.
export const breachesLabel = (windowHours) => {
  if (!windowHours) return i18n.t("breaches.all");
  const seconds = windowHours * 3600;
  if (seconds < 90) return i18n.t("breaches.live");   // the demo's seconds-long window: breachesHint says so
  if (seconds < 5400) return i18n.t("breaches.lastMinutes", { n: fmtNumber(Math.round(seconds / 60), 0) });
  return i18n.t("breaches.lastHours", { n: fmtNumber(Math.round(windowHours), 0) });
};
/** Tooltip for the "Live sensor breaches" label: the window it counts (null for longer windows). */
export const breachesHint = (windowHours) =>
  windowHours && windowHours * 3600 < 90 ? i18n.t("breaches.liveHint", { n: fmtNumber(Math.round(windowHours * 3600), 0) }) : null;
