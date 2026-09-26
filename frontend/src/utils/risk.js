// Risk band -> label and CSS class. The API sends lower-case bands (low / medium / high);
// both cases are accepted so older payloads still render.
import { t } from "../i18n/t";

const norm = (level) => String(level || "low").toLowerCase();

export const RISK_ORDER = { high: 0, medium: 1, low: 2 };

// Class name, not a colour value: the colours live in theme.css.
export const riskClass = (level) => `risk-${norm(level)}`;
export const riskLabel = (level) => t(`risk.${norm(level)}`);

export const TREND_SYMBOL = { rising: "▲", falling: "▼", steady: "–" };
export const trendSymbol = (direction) => TREND_SYMBOL[String(direction || "").toLowerCase()] || "–";
