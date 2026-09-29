// Turns the API's codes into text (brief rule 7: the API sends {code, params}, the UI words them).
// Every label goes through i18n; unknown tokens fall back to a humanised form so nothing renders
// as raw snake_case.
import { fmtDate, fmtDateTime, fmtNumber, humanise } from "../utils/format";
import { t } from "./t";

const label = (key, token) => (token ? t(key + token, { defaultValue: humanise(token) }) : "");

export const sensorLabel = (type) => label("sensor.", type);
export const categoryLabel = (key) => label("category.", key);
export const sourceLabel = (key) => label("violationSource.", key);
export const statusLabel = (key) => label("status.", key);
export const severityLabel = (key) => label("severity.", String(key || "").toLowerCase());
export const incidentTypeLabel = (key) => label("incident.type.", key);
export const incidentSeverityLabel = (key) => label("incident.severity.", key);
export const descriptionCodeLabel = (code) => (code ? t(`incidentCause.${code}`, { defaultValue: humanise(String(code).toLowerCase()) }) : "");
export const violationTypeLabel = (type) => label("violationType.", type);
/** "every 6 months" -> the UI language (frequency values of the obligation catalogue). */
export const frequencyLabel = (f) => (f ? t(`frequency.${f.replace(/[^a-z0-9]+/gi, "_").replace(/^_|_$/g, "")}`, { defaultValue: f }) : "");
/** An obligation's title in the UI language (the citation and quote stay as written). */
export const obligationTitle = (o) => (o ? t(`obligationTitle.${o.code}`, { defaultValue: o.title ?? o.code }) : "");
export const riskText = (level) => label("risk.", String(level || "").toLowerCase());

/** A label used mid-sentence: "Roof and strata" -> "roof and strata"; "PPE" stays (scripts without case are unchanged). */
export const midSentence = (text) => (/^\p{Lu}\p{Ll}/u.test(text) ? text[0].toLocaleLowerCase() + text.slice(1) : text);

/** A p-value for people: "< 0.001" below that, else three decimals. */
const pValue = (p) => (p < 0.001 ? "< 0.001" : fmtNumber(p, 3));

/**
 * One reason a detector gives, as a sentence (Phase 7). `flag` supplies the finding's context
 * (its date, contractor, window) where the reason's own params do not carry it.
 */
export function anomalyReasonText(reason, flag = {}) {
  if (!reason) return "";
  const p = reason.params || {};
  const n = (v, d = 0) => fmtNumber(v, d);
  switch (reason.code) {
    case "OVER_TARGET":
    case "ROLLING_MEAN_SPIKE":
    case "ROLLING_MEAN_DROP":
      return t(`production.anomaly.${reason.code}`, { ...p, date: fmtDate(flag.entities?.date ?? flag.subject),
        achievement_pct: n(p.achievement_pct, 1), ratio: p.ratio == null ? "-" : n(p.ratio, 2), z: p.z == null ? "-" : n(p.z, 1) });
    case "FLATLINE":
      return t("anomaly.reason.FLATLINE", { sensor: sensorLabel(p.sensor_type), hours: n(p.hours), value: fmtNumber(p.value, 2), readings: n(p.readings) });
    case "NIGHT_CONCENTRATION":
      return t("anomaly.reason.NIGHT_CONCENTRATION", { night: n(p.night), total: n(p.total), share: n(p.share_pct), expected: n(p.expected_pct), p: pValue(p.p) });
    case "REPEAT_IMPROBABLE":
      return t("anomaly.reason.REPEAT_IMPROBABLE", { category: midSentence(categoryLabel(p.category)), count: n(p.count), of: n(p.of), days: n(p.days),
        expected: n(p.expected, 1), p: pValue(p.p) });
    case "REPEAT_THEN_INCIDENT":
      return t("anomaly.reason.REPEAT_THEN_INCIDENT", { category: midSentence(categoryLabel(p.category)), count: n(p.count), days: n(p.days),
        date: fmtDate(p.incident_date), id: p.incident_id });
    case "LATE_CLOSURES":
      return t("anomaly.reason.LATE_CLOSURES", { late: n(p.late), resolved: n(p.resolved), share: n(p.share_pct), fleet: n(p.fleet_pct), p: pValue(p.p) });
    case "CONTRACTOR_MISSING_DOCS":
      return t("anomaly.reason.CONTRACTOR_MISSING_DOCS", { id: flag.entities?.contractor_id ?? "-", missing: n(p.missing), median: n(p.median, 1) });
    case "CONTRACTOR_VIOLATION_RATE":
      return t("anomaly.reason.CONTRACTOR_VIOLATION_RATE", { id: flag.entities?.contractor_id ?? "-", rate: n(p.per_worker, 2), median: n(p.median, 2) });
    case "GRIEVANCE_SLA_CLUSTER":
      return t("anomaly.reason.GRIEVANCE_SLA_CLUSTER", { breaches: n(p.breaches), days: n(p.window_days), from: fmtDate(p.from), to: fmtDate(p.to) });
    default:
      return humanise(String(reason.code).toLowerCase());
  }
}

/** A finding's window: one day, or from - to. */
export function anomalyWindow(flag) {
  if (!flag?.from) return "";
  const from = fmtDate(flag.from);
  const to = fmtDate(flag.to);
  return from === to ? from : t("anomaly.window", { from, to });
}

/** One alert as a sentence. */
export function alertText(alert) {
  if (!alert) return "";
  const p = alert.params || {};
  if (alert.code === "ANOMALY_DETECTED") {
    const flag = { subject: p.subject, from: p.from, to: p.to, entities: { date: p.subject, contractor_id: String(p.subject || "").replace("contractor:", "") } };
    return t("alert.ANOMALY_DETECTED", { detector: t(`anomaly.detector.${p.detector}`, { defaultValue: p.detector }),
      summary: anomalyReasonText({ code: p.reason, params: p.reason_params || {} }, flag) });
  }
  if (alert.code === "PRODUCTION_ENTRY_PENDING") {
    // An empty part is left out rather than shown as "drafts: -".
    const missing = (p.missing_shifts || []).join(", ");
    const drafts = (p.draft_shifts || []).join(", ");
    const key = missing && drafts ? "PRODUCTION_ENTRY_PENDING" : drafts ? "PRODUCTION_ENTRY_PENDING_DRAFTS" : "PRODUCTION_ENTRY_PENDING_MISSING";
    return t(`alert.${key}`, { date: fmtDate(p.date), missing: missing || "-", drafts });
  }
  // List parameters (e.g. obligation codes) read "HLT-03, SAF-01" rather than i18next's "HLT-03,SAF-01".
  const lists = Object.fromEntries(Object.entries(p).filter(([, v]) => Array.isArray(v)).map(([k, v]) => [k, v.join(", ")]));
  const params = {
    ...p,
    ...lists,
    sensor: sensorLabel(p.sensor_type),
    type: violationTypeLabel(p.violation_type),
    category: categoryLabel(p.category),
    source: sourceLabel(p.source),
    risk: riskText(p.risk_level),
    doc_types: Array.isArray(p.doc_types) ? p.doc_types.map((d) => t(`contractor.docType.${d}`, { defaultValue: humanise(d) })).join(", ") : p.doc_types,
    obligation: p.obligation ?? p.obligation_code ?? "",
    due_at: p.due_at ? fmtDateTime(p.due_at) : p.due_at,
    licence_valid_to: p.licence_valid_to,
    code: alert.code,
  };
  let text = t(`alert.${alert.code}`, { ...params, defaultValue: t("alert.UNKNOWN", params) });
  if (alert.code === "INSPECTION_DIRECTIVE" && p.note) text += ` ${t("alert.directiveNote", { note: p.note })}`;
  return text;
}

/** Short source tag for an alert row. */
export function alertSource(alert) {
  if (alert.is_directive) return t("alert.source.directive");
  const byCode = {
    SENSOR_THRESHOLD_BREACHED: "sensor",
    VIOLATION_RECORDED: alert.params?.source || "vision",
    CORRECTIVE_ACTION_OVERDUE: "action",
    CONTRACTOR_LICENCE_EXPIRING: "contractor",
    WORKER_VT_EXPIRED: "contractor",
    WORKER_MEDICAL_EXPIRED: "contractor",
    CONTRACTOR_DOC_MISSING: "contractor",
    CONTRACT_WORKER_CAP_EXCEEDED: "contractor",
    GRIEVANCE_SLA_BREACHED: "grievance",
    DANGEROUS_OCCURRENCE_REPORTED: "incident",
    DETAIL_REQUEST_OVERDUE: "production",
    PRODUCTION_ENTRY_PENDING: "production",
    ANOMALY_DETECTED: "anomaly",
  };
  return t(`alert.source.${byCode[alert.code] || "system"}`);
}

/** A priority-queue reason. */
export const reasonText = (reason) =>
  reason ? t(`priority.${reason.code}`, { ...reason.params, risk: riskText(reason.params?.risk_level), band: riskText(reason.params?.band),
    component: reason.params?.component ? t(`gri.component.${reason.params.component}`) : "" }) : "";

/** The reporting-time check of an incident (the law's time per type). */
export const reportingCheckText = (check) => (check ? t(`incident.check.${check.code}`, check.params) : "");

/** A corrective action's description: system codes are translated, people's words are shown as typed. */
export const actionDescription = (text) =>
  /^[A-Z][A-Z0-9_]+$/.test(text || "") ? t(`correctiveAction.system.${text}`, { defaultValue: humanise(text.toLowerCase()) }) : text;

/** "Violation #12 changed" style headline for an audit entry. */
export function auditHeadline(entry) {
  const entity = t(`audit.entity.${entry.entity}`, { defaultValue: humanise(entry.entity) });
  const action = t(`audit.action.${entry.action}`, { defaultValue: humanise(entry.action) });
  return `${entity}${entry.entity_id ? ` #${entry.entity_id}` : ""} ${action}`;
}

/** Field-level summary of what changed: "status: open -> resolved". */
export function auditChanges(entry) {
  const oldV = entry.old_values || {};
  const newV = entry.new_values || {};
  if (entry.action === "update") {
    return Object.keys(newV).map((k) => `${fieldLabel(k)}: ${fmtValue(oldV[k])} → ${fmtValue(newV[k])}`);
  }
  const values = entry.action === "delete" ? oldV : newV;
  return Object.entries(values)
    .filter(([k, v]) => v !== null && v !== "" && !["id", "params", "context"].includes(k))
    .slice(0, 8)
    .map(([k, v]) => `${fieldLabel(k)}: ${fmtValue(v)}`);
}

/** A record field's name in an audit diff (common fields translated, others humanised). */
const fieldLabel = (k) => t(`audit.field.${k}`, { defaultValue: humanise(k) });

function fmtValue(v) {
  if (v === null || v === undefined || v === "") return "-";
  if (typeof v === "object") return JSON.stringify(v);
  return String(v);
}

/** " nationwide" / " across SECL" - where a multi-mine view is looking, for running text. */
export const scopeWhere = (user) =>
  user?.role === "corporate" ? t("scopeWhere.company", { code: user?.subsidiary_code ?? "" }) : t("scopeWhere.national");
