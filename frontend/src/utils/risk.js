// Shared risk band to label and colour mapping (mirrors the backend).
export const RISK_LABEL = { LOW: "Low risk", MEDIUM: "Medium risk", HIGH: "High risk" };
export const RISK_ORDER = { HIGH: 0, MEDIUM: 1, LOW: 2 };

// Class name, not a colour value: the colours live in theme.css so the palette
// changes in one place.
export const riskClass = (level) => `risk-${String(level || "LOW").toLowerCase()}`;
export const riskLabel = (level) => RISK_LABEL[level] || level;

export const TREND_SYMBOL = { RISING: "\u25B2", FALLING: "\u25BC", STEADY: "\u2013" };
export const trendSymbol = (direction) => TREND_SYMBOL[direction] || "\u2013";
