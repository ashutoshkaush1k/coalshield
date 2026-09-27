// Contractors, contracts, workers and monthly documents (Phase 3).
import { client } from "./client";

/** params: mine_id, band, status, per_page. Worst first, each with `compliance`. */
export const listContractors = (params = {}) => client.get("/contractors", { params }).then((r) => r.data);
export const getContractor = (id) => client.get(`/contractors/${id}`).then((r) => r.data);
export const getContractorSummary = (state = null) =>
  client.get("/contractors/summary", { params: state ? { state } : {} }).then((r) => r.data);

/** Register a contractor together with its first contract at the mine head's own mine. */
export const createContractor = (body) => client.post("/contractors", body).then((r) => r.data);
export const updateContractor = (id, body) => client.patch(`/contractors/${id}`, body).then((r) => r.data);
export const changeContractorStatus = (id, status, reason) =>
  client.post(`/contractors/${id}/status`, { status, reason }).then((r) => r.data);

export const createContract = (body) => client.post("/contracts", body).then((r) => r.data);
export const addWorker = (contractId, body) => client.post(`/contracts/${contractId}/workers`, body).then((r) => r.data);
export const updateWorker = (id, body) => client.patch(`/contract-workers/${id}`, body).then((r) => r.data);

export const uploadDocument = (contractId, { docType, period, file }) => {
  const form = new FormData();
  form.append("doc_type", docType);
  form.append("period", period);
  form.append("file", file);
  return client.post(`/contracts/${contractId}/documents`, form).then((r) => r.data);
};
export const verifyDocument = (id) => client.post(`/contractor-docs/${id}/verify`).then((r) => r.data);

export const linkViolationContractor = (violationId, contractorId) =>
  client.patch(`/violations/${violationId}/contractor`, { contractor_id: contractorId }).then((r) => r.data);

export const WORK_TYPES = ["ob_removal", "transport", "loading", "civil", "security", "other"];
export const DOC_TYPES = ["wage_register", "epf_challan", "esi_challan", "insurance", "other"];
