# Product Requirements Document (PRD)

## AI-Based Smart Governance and Compliance Monitoring System for Coal Mines
**Problem Statement:** SIH26024
**Team:** REGEX (Ashutosh, Yug)
**Document Version:** 1.0
**Stage:** Round 3 — Prototype Development

---

## 1. Overview

### 1.1 Problem
Coal mine safety and regulatory compliance today rely on manual inspections, delayed paperwork, and periodic reporting. This leads to unsafe conditions going unnoticed, PPE violations being detected only after incidents, and no real-time visibility for government authorities monitoring multiple mines.

### 1.2 Solution
A centralized, AI-powered platform that continuously ingests data from IoT sensors, CCTV feeds, inspection records, and environmental monitors — using AI/ML and Computer Vision to detect violations, score compliance, predict risk, and give authorities, inspectors, and mine operators real-time dashboards and alerts.

### 1.3 One-Line Pitch
An AI-powered centralized governance platform that continuously monitors coal mines, detects safety and regulatory violations, predicts risks, and enables authorities to take timely, data-driven compliance actions.

---

## 2. Goals & Success Metrics

| Goal | Metric (for prototype demo) |
|---|---|
| Detect PPE/safety violations automatically | Working CV model flags violations from sample video/image input |
| Monitor environmental risk in real time | Simulated IoT data (gas/dust/temp) triggers threshold-based alerts |
| Score mine compliance | Compliance score computed from rule-matching logic |
| Centralize visibility | Functional dashboard showing mine-wise status, alerts, and scores |
| Role-based access | At least 2 distinct dashboard views (e.g., Authority, Operator) |

---

## 3. Target Users & Roles

Two login types, each with a distinct dashboard and data scope:

| Role | Login | Data Access |
|---|---|---|
| **Government** | Government Login | Full access — all mines, cross-mine comparison, full audit trail |
| **Mine Head** | Mine Head Login | Restricted — own mine only, no visibility into other mines' data |

---

## 4. Core Features (Prototype Scope)

### Must-Have (Round 3 minimum viable prototype)
1. **Computer Vision Module** — detect PPE violations (helmet/vest/etc.) from image or video input, using a **pretrained model** (e.g., pretrained YOLO for PPE detection) — no custom training needed for prototype stage.
2. **IoT Data Simulation** — a **prepared dataset of 150–200 realistic sensor readings** (gas, dust, temperature) fed into the system to simulate real-time IoT input, with threshold-based alerting. Real sensor integration is out of scope for this stage.
3. **Compliance Scoring Engine** — rule-based logic that converts detected violations + sensor data into a compliance/risk score per mine (see Section 6.1 for formula).
4. **Two Role-Based Dashboards** (see Section 4.1 for full breakdown):
   - **Government Login** — full access to all mines, cross-mine comparison
   - **Mine Head Login** — restricted to own mine's data only
5. **Alerts** — visual/system alert when a violation or threshold breach occurs.

### Should-Have (if time permits)
6. **Digital audit trail** — log of detected violations with timestamps.
7. **Basic predictive risk indicator** — simple trend-based flag (e.g., rising violation frequency = higher risk).

### 4.1 Dashboard Breakdown by Role

**Government Login (Full Access)**
- Multi-mine overview grid — compliance scores, risk levels for every mine
- Cross-mine comparison (sort/filter by risk, score, location)
- Drill-down into any individual mine's full data (violations, sensor history, audit trail)
- Inspection prioritization list (auto-ranked high-risk mines)
- Full audit trail across all mines
- Aggregated alerts feed across all mines

**Mine Head Login (Restricted to Own Mine)**
- Own mine's compliance score + risk level only
- Own mine's active violations and alerts
- Own mine's sensor data (gas/dust/temperature trend)
- Own mine's historical compliance trend
- Corrective action log/tracker for own site
- No visibility into other mines' data or comparisons

### 4.2 Access Control Logic (implementation note)
- Each mine has a unique `mine_id`.
- Each Mine Head account is mapped to exactly one `mine_id`.
- Government account has no `mine_id` restriction — queries return all mines.
- Access must be enforced **server-side** (API/query level), not just hidden in the UI — otherwise a Mine Head could technically access other mines' data by bypassing the frontend.

### Out of Scope (for prototype, future roadmap)
- Real hardware IoT integration
- Full regulatory rulebook mapping
- Production-grade security/auth
- Multi-mine, multi-region scale testing

---

## 5. Tech Stack (proposed — finalize with team)

| Layer | Technology |
|---|---|
| Computer Vision | Python, OpenCV, YOLO (or similar pretrained model) |
| Backend / AI logic | Python (Flask/FastAPI) |
| IoT simulation | Mock data generator / MQTT simulation |
| Dashboard/Frontend | React or Streamlit (faster for prototype) |
| Database | Firebase / SQLite / PostgreSQL |
| Hosting (demo) | Local / free-tier cloud (Render, Vercel, etc.) |

*(To be confirmed based on team's existing skills — flag if anyone has a stack preference.)*

---

## 6. User Flow (Prototype Demo)

1. System ingests a sample video/image feed → CV model flags PPE violation.
2. Simulated IoT dataset (150–200 sample readings) feeds gas/dust/temperature values → threshold breach triggers alert.
3. Compliance engine combines both inputs → generates/updates compliance score for the mine.
4. **Dashboard shows multiple mines side-by-side** — status, active alerts, and current score per mine, enabling direct comparison.
5. Authority/Inspector view shows violation log with timestamp (audit trail).

### 6.1 Compliance Scoring Formula (proposed)

```
Compliance Score (0–100) = 100 − (Violation Penalty + Environmental Penalty)

Violation Penalty     = (PPE violations detected × weight_ppe)
Environmental Penalty = (sensor readings beyond threshold × weight_env)

Risk Level:
  80–100 → Low Risk (Green)
  50–79  → Medium Risk (Yellow)
  0–49   → High Risk (Red)
```

Simple, transparent, and easy to explain live to judges. Weights (`weight_ppe`, `weight_env`) can be tuned during development; can be made more sophisticated (severity/frequency-based) post-prototype if time permits.

---

## 7. Team Roles (draft — confirm with teammates)

| Area | Owner |
|---|---|
| Computer Vision model | TBD |
| IoT simulation + alert logic | TBD |
| Compliance scoring engine | TBD |
| Dashboard/frontend | TBD |
| Integration + demo prep | Ashutosh & Yug |

---

## 8. Decisions Locked In
- **CV Model:** Pretrained model for PPE detection (no custom training for prototype).
- **IoT Data:** Simulated dataset of 150–200 realistic sensor values (gas/dust/temperature) — no real hardware integration at this stage.
- **Scoring Formula:** Simple weighted rule-based model (see Section 6.1).
- **Demo Scenario:** Multi-mine dashboard to demonstrate cross-mine comparison — this is the primary judge-facing scenario.
- **Access Model:** Two separate logins — Government (full access, all mines) and Mine Head (restricted to own mine only).

## 8.1 Remaining Open Questions
- Live feed vs recorded video for the CV demo — which is more reliable to show live?
- Exact weight values (`weight_ppe`, `weight_env`) — to be tuned once sample data is ready.
- Number of mines to show on the dashboard (e.g., 3–5) for a clean but convincing comparison.

---

## 9. Timeline (fill in based on Round 3 deadline)

| Milestone | Target Date |
|---|---|
| Finalize tech stack & roles | |
| CV module working (standalone) | |
| IoT simulation + scoring engine | |
| Dashboard integrated with backend | |
| End-to-end demo run-through | |
| Final polish + submission | |
