// Date, number, and unit formatting.
export const fmtScore = (n) => (n === null || n === undefined ? "-" : Number(n).toFixed(0));

export const fmtTime = (iso) => {
  if (!iso) return "-";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "-" : d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
};

export const fmtDateTime = (iso) => {
  if (!iso) return "-";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "-";
  return `${d.toLocaleDateString([], { day: "2-digit", month: "short" })} ${fmtTime(iso)}`;
};

// "no_safety_vest" -> "No safety vest". Violation types are stored as machine tokens; nobody
// should have to read snake_case off a dashboard.
export const humanise = (token) => {
  if (!token) return "";
  const words = String(token).replace(/_/g, " ").trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
};

export const fmtPercent = (n) => `${Math.round((n || 0) * 100)}%`;
