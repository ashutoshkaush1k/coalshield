// Reads design tokens out of :root so non-CSS consumers stay on the same source of truth.
//
// Recharts sets stroke and fill as SVG *attributes*, where `var(--x)` does not resolve - so a
// chart cannot simply reference the token by name. Rather than let hex values drift into
// component files, resolve them once from the stylesheet.
const cache = new Map();

export function token(name, fallback = "") {
  if (cache.has(name)) return cache.get(name);
  const value =
    typeof window === "undefined"
      ? fallback
      : getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
  cache.set(name, value);
  return value;
}

export const chartTokens = () => ({
  grid: token("--chart-grid", "#eef0f3"),
  axis: token("--chart-axis", "#9ca3af"),
  line: token("--line", "#e5e7eb"),
  surface: token("--card", "#ffffff"),
  ink: token("--ink", "#111114"),
  muted: token("--muted", "#6b7280"),
  riskHigh: token("--risk-high-dot", "#ef4444"),
});

export const sensorColour = (sensorType) =>
  token(`--sensor-${sensorType}`, token("--muted", "#6b7280"));
