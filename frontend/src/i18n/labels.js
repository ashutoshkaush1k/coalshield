// Turns the API's codes into text (brief rule 7: the API sends {code, params}, the UI words them).
// Every label goes through i18n; unknown tokens fall back to a humanised form so nothing renders
// as raw snake_case.
import { fmtDateTime, humanise } from "../utils/format";
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

/** One alert as a sentence. */
export function alertText(alert) {
  if (!alert) return "";
  const p = alert.params || {};
  const params = {
    ...p,
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
  };
  return t(`alert.source.${byCode[alert.code] || "system"}`);
}

/** A priority-queue reason. */
export const reasonText = (reason) =>
  reason ? t(`priority.${reason.code}`, { ...reason.params, risk: riskText(reason.params?.risk_level) }) : "";

/** The 48-hour reporting check of an incident. */
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
