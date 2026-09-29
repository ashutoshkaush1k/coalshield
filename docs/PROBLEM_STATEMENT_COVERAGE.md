# Problem statement coverage (SIH26024)

Every part of the problem statement, where it lives, what shows it working, and where it stops.
The problem statement (as summarised in `CLAUDE_CODE_TASK.md` section 1 and the PRD): *a centralized
AI-enabled governance and compliance monitoring platform for Indian coal mines covering statutory
compliance, inspections / observations / violations / corrective actions, contractor management,
production reporting, worker / labour compliance, grievance handling, automated alerts / reminders /
escalations, audit trails, dashboards for mine officials, corporate management and regulators,
multilingual access, and scalability across subsidiaries*, plus the PRD's must-haves: computer
vision for PPE, IoT sensor monitoring with threshold alerts, a compliance score, role-based
dashboards, alerts, an audit trail and a predictive risk indicator.

Screenshots are from the browser checks (`node scripts\browser_check.mjs <phase>`), re-run at the end
of Phase 8. Test names are in `api/tests/` (Codeception) and `ai-service/tests/` (pytest); all pass
(`api\run_tests.bat`). Demo figures come from synthetic data calibrated to public statistics - see
`data/DATASETS.md`.

## Coverage

| # | Problem statement | Feature (where) | Screen | Evidence | Honest limitations |
|---|---|---|---|---|---|
| 1 | **Centralized platform, AI-enabled** | One API over one database for 74 real coal mines in 10 companies; AI in a separate stateless service with PHP fallbacks (`docs/architecture.md`) | [overview](screenshots/phase2/02-gov-overview.png) | 111 endpoints, each with its permission (`docs/API.md`, `ApiDocTest`); every dashboard request under 150 ms, slowest p95 52 ms (`docs/PERFORMANCE.md`) | Runs on one laptop; no high-availability setup. Operations data is synthetic. |
| 2 | **Statutory compliance** | Obligation register: 40 duties with instrument, clause, page and quote (39 verified, 1 TODO-VERIFY); due tasks per mine, evidence, review, overdue and escalation (`ObligationService`) | [register](screenshots/phase5b/01-head-register.png), [citation](screenshots/phase5b/02-head-task-citation.png), [review](screenshots/phase5b/06-gov-reject-needs-reason.png) | `ObligationCest`, `ObligationScheduleTest`; cites the OSH Code 2020, OSH (Central) Rules 2026 and Coal Mines Regulations 2017, never the repealed Mines Act 1952 | Only calendar-frequency duties generate tasks; due days not in the law are product settings, labelled as such. RPT-08 (monthly production return) is TODO-VERIFY and never generated. |
| 3 | **Inspections, observations, violations, corrective actions** | Inspection workflow (scheduled -> visited -> closed and locked), observations promoted to violations, corrective actions with proof that close the violation; Risk Ranking tab: mines ranked by the Governance Risk Index | [risk ranking](screenshots/phase7/04-priority-by-index.png), [violations](screenshots/phase2/08-gov-violations.png), [corrective action](screenshots/phase2/16-head-record-action.png), [score recovered](screenshots/phase2/17-head-score-recovered.png) | `InspectionCest`, `CorrectiveActionCest`, `StatusTransitionTest` (invalid transitions 422), `RiskCest` | Closing a PPE finding with a compliant photo is possible for the mine head; the photo is not tied to the finding's place and time (`docs/architecture.md`). |
| 4 | **Field inspection, offline** | Installable PWA `/field`: checklist of 23 items across the 11 categories, severity, photos compressed on the phone, GPS with accuracy, device and receipt time; offline queue; idempotent sync; geo and clock flags | [offline](screenshots/phase7b/02-offline-app-opens.png), [capture](screenshots/phase7b/03-capture-offline.png), [geo flag](screenshots/phase7b/04-geo-check-far.png), [on the dashboard](screenshots/phase7b/09-gov-capture-detail.png), [no duplicates](screenshots/phase7b/11-resent-no-duplicates.png) | `FieldCest` (idempotency, scope, flags, expired token); browser check phase7b: offline capture, sync, both dashboards within one 10 s poll, resend 5 of 5 "already on the server" | Tested in a headless browser emulating a 360 px Android phone, not on a physical phone. Photos upload whole (no resumable chunks). |
| 5 | **Contractor management** | Contractors, contracts, workers, monthly compliance documents, licence / training / medical expiries, worker caps, contractor score; linked to violations | [contractors](screenshots/phase3/01-head-contractor-list.png), [missing documents](screenshots/phase3/03-head-s4-missing-documents.png), [government summary](screenshots/phase3/13-gov-contractors-per-mine.png) | `ContractorCest`, `ContractorServiceTest`; the planted scenario S4 is found by the contractor detector | Document contents are not verified (a file of the right type counts as submitted until a reviewer checks it). |
| 6 | **Production reporting** | Shift-wise entries, submit and lock, corrections only with a reason (edit log), a regulator's "Call for Detailed Report" before detail is visible, anomaly flags | [numbers only](screenshots/phase4/01-gov-production-numbers.png), [detail required](screenshots/phase4/02-gov-detail-required.png), [edit log](screenshots/phase4/09-head-edit-log.png), [detail after request](screenshots/phase4/12-gov-detail-view.png) | `ProductionCest` (403 `DETAIL_REQUEST_REQUIRED` before, 200 after; 422 without a reason), `ProductionAnomalyTest` | Targets and figures are synthetic, calibrated to Ministry of Coal monthly totals. |
| 7 | **Worker / labour compliance** | Contract workers' vocational training and medical examination expiries (alerts), worker caps per contract, contractor wage / EPF / ESI documents, the labour duties in the obligation register | [workers](screenshots/phase3/06-head-s4-workers.png), [alerts](screenshots/phase3/05-head-s4-alerts.png) | `ContractorCest`; alert codes `WORKER_VT_EXPIRED`, `WORKER_MEDICAL_EXPIRED`, `CONTRACT_WORKER_CAP_EXCEEDED` | No attendance or wage calculation; workers are records, not identities. The EPF Act's status is TODO-VERIFY (brief section 7). |
| 8 | **Grievance handling** | Public submission and tracking without login (rate-limited, honeypot), SLA per category with escalation, sensitive cases (harassment, against the mine head) routed away from the mine head with identity hidden, clusters detected | [public form](screenshots/phase5/02-public-form.png), [tracking](screenshots/phase5/04-public-track.png), [analytics](screenshots/phase5/09-gov-analytics.png), [sensitive](screenshots/phase5/12-gov-sensitive.png), [mine head does not see it](screenshots/phase5/14-head-no-sensitive.png) | `GrievanceCest` (public, rate limit, sensitive invisible to the mine head, identity never serialised), `GrievanceClusterTest` | Grievance text is not translated (machine translation is roadmap, `docs/i18n.md`). No SMS or email to the complainant (out of scope). |
| 9 | **Automated alerts, reminders, escalations** | Alerts with `{code, params}`; nine scheduled jobs (reminders, SLAs, escalation after 24 h / 72 h, scores, detectors, checks), idempotent and logged; Task Scheduler registration | [alerts](screenshots/phase7/06-anomaly-and-escalated-alerts.png), [escalated obligation](screenshots/phase5b/11-head-escalated.png) | `AlertCest`, `JobsTest` (idempotent, locked, logged), `docs/SETUP_WINDOWS.md` 7a | Notifications stay in the app (no SMS or email, out of scope). Tasks run only while the user is logged on. |
| 10 | **Audit trails** | Every change of an audited record in `audit_log`, SHA-256 hash-chained; status history per record; edit logs for locked records | [audit trail](screenshots/phase2/11-gov-audit-trail.png) | `AuditChainTest`, `AuditTrailCest`; `yii audit/verify` after every seed, reset and test run (43,808 entries intact after `demo_reset`) | Tamper-evident, not tamper-proof: a database superuser could rewrite the whole chain; anchoring the head hash outside the database is roadmap. |
| 11 | **Dashboards for mine officials, corporate management and regulators** | Government (all mines), corporate (its company's mines), inspector, mine head (own mine); one request per screen per 10 s poll | [government](screenshots/phase2/02-gov-overview.png), [mine head](screenshots/phase2/13-head-overview.png), [corporate](screenshots/phase2/20-corporate-overview.png), [out of scope is 404](screenshots/phase2/21-corporate-out-of-scope.png) | `ScopingCest` (another mine's contractors, production, grievances, alerts: 404), `ViewCest`, `DashboardCest` | Corporate is read-only by design; no area-level role between corporate and the mine. |
| 12 | **Multilingual access** | English, Hindi, Bengali, Odia, Telugu, Marathi across every screen, charts and the field app; Indian number grouping; bundled Noto fonts | [Hindi](screenshots/phase6/00b-head-saved-hindi.png), [Telugu field app](screenshots/phase7b/12-capture-te.png) | `check_locales` (every key in every language), `check_hardcoded_strings`; overflow probe at 360 px: 0 findings | The five non-English translations are machine drafts marked `"reviewed": false` - a native speaker should review them. |
| 13 | **Scalability across subsidiaries** | Company and area in the data model; corporate scope per company; monthly partitioned sensor readings; composite indexes; views that answer a screen in one request | [corporate](screenshots/phase2/20-corporate-overview.png) | 74 mines, 90 days of 3-shift production and sensor data under 150 ms per screen (`docs/PERFORMANCE.md`) | Measured on one laptop with 74 mines; no load test beyond a few concurrent dashboards. |
| 14 | **Computer vision for PPE** (PRD 4.1) | YOLO11n fine-tuned on a public PPE dataset; missing helmet / vest -> violation, annotated frame, clean frame as evidence | no screenshot: the browser checks cannot drive a file picker, so the API test covers it | Held-out test split (213 images): precision 0.818, recall 0.889, mAP50 0.878 (`docs/AI_EVALUATION.md` section 3); `VisionCest` | Trained on generic construction-site PPE images, not underground coal mines; the weights are AGPL-3.0 (licence note in the README). |
| 15 | **IoT monitoring with threshold alerts** (PRD 4.2) | Simulator replays readings through an API-key ingest endpoint; legal limits from `rules.yaml` (each tied to a cited obligation); breaches raise alerts and age out of the score | [sensors](screenshots/phase2/04-gov-sensors.png), [trends](screenshots/phase2/12-gov-sensor-trends.png) | `SensorCest`, `SensorRulesTest` | No real hardware (out of scope); readings are synthetic, calibrated to CPCB air-quality statistics. |
| 16 | **Compliance score and risk bands** (PRD 4.3, 6.1) | `100 - violations x 5 - breaches in window x 3`, bands low 80+, medium 50+, high; the Governance Risk Index beside it | [score and index](screenshots/phase7/01-gov-mine-score-and-index.png) | `ComplianceScoreServiceTest` (the prototype's numbers), `DemoScoreCest` (100 / 80 / 70 / 60 / 45, fleet 83.2), `yii demo/check` | The weights are product settings, not a regulatory formula; the index's weights are not fitted to outcomes. |
| 17 | **AI anomaly detection** | Seven detectors (production spikes, flatlined sensors, night-shift concentration, repeat violations, late corrective actions, contractor outliers, grievance clusters), in the ai-service with identical PHP twins | [findings](screenshots/phase7/05-priority-fleet-findings.png) | Against the planted scenarios: 7 of 7 found, 3 of 3 decoys ignored, 10 unplanted flags explained (`docs/AI_EVALUATION.md` section 1, `AiEvaluationTest`, `DetectorParityTest`) | Evaluated on synthetic data only; the ten extra flags are real patterns the generator did not label. |
| 18 | **Predictive risk indicator** (PRD 4 (7)) | Gradient boosting on real US regulator data (MSHA coal mine-years), time split, calibrated, with top factors in plain language | [predicted risk](screenshots/phase7/02-gov-risk-panel-fallback.png) | Test years 2022-2024: AUC 0.816 against 0.766 for last year's accident rate (`docs/AI_EVALUATION.md` section 2) | **Trained on US data and transferred**; not validated on Indian mines. A prompt, never a finding (said in the demo; the screen shows the figure and its factors only). |

## Questions judges may ask

**Is the data real?** The 74 mines, their companies, districts and locations are real (Global Energy
Monitor, Global Coal Mine Tracker, CC BY 4.0), and so are the legal duties with their citations,
the company production totals, DGMS accident statistics and CPCB air-quality figures. Daily
operations - production entries, sensor readings, violations, grievances - are synthetic, generated
with a fixed seed and calibrated to those public statistics (`data/DATASETS.md`, "Data provenance
for judges"). The screens no longer tag scores as demo values (production-styled UI); the data
sources whose licences require credit are credited on the map's attribution line.

**Which law does it follow?** The OSH Code 2020, in force from 21.11.2025, the OSH (Central) Rules
2026 and the Coal Mines Regulations 2017 (saved under the Code). The Mines Act 1952 and the Contract
Labour Act 1970 are repealed and are not cited anywhere. Each obligation shows its instrument, clause,
page and the quoted text; a value that could not be confirmed is marked TODO-VERIFY
(`CLAUDE_CODE_TASK.md` section 7, `data/reference/obligations.csv`).

**Can a mine head see another mine, or hide a record?** No. Scoping is one query class used by every
record type; another mine's record answers 404 exactly like a record that does not exist
(`docs/access-control.md`, `ScopingCest`). Every change is in a hash-chained audit log that
`yii audit/verify` checks; submitted production and closed inspections are locked, and corrections
need a reason that is kept.

**How accurate is the AI?** PPE detection on a held-out test split: precision 0.818, recall 0.889
(mAP50 0.878). The anomaly detectors find all seven planted scenarios and ignore all three decoys.
The risk model beats last year's accident rate on later US years (AUC 0.816 against 0.766). Details
and limits: `docs/AI_EVALUATION.md`.

**Why a model trained on US data?** No public Indian dataset links mine-level violations to later
accidents. MSHA publishes one for US coal mines. The model is transferred through a category
crosswalk and labelled as such on screen; it ranks, it does not decide.

**What if the AI service or the internet fails?** Everything runs on the laptop, fonts and map
outlines included. If the ai-service stops, the detectors and the model run in the API instead (the
same algorithms, tested for identical output), the footer says so, and the supervisor restarts the
service within about a minute. Only PPE photo analysis waits for it.

**Does it work underground with no signal?** The field app does: after one sign-in it opens, records
findings with photos, GPS (or "no fix") and the phone's time, and syncs later. A resent queue never
creates duplicates, and a finding far from the mine is flagged, not refused.

**How does a regulator get production detail without seeing everything?** Government and corporate see
numbers and anomaly flags. Detail for a date range needs a "Call for Detailed Report" with a deadline;
the mine head answers, and overdue requests escalate. Before that the API answers 403
`DETAIL_REQUEST_REQUIRED` (`ProductionCest`).

**Are grievances safe to raise?** They can be anonymous and need no login. Harassment cases and
complaints against the mine head go to the regulator; the mine head cannot see them at all, and a
complainant's name and contact are never sent to a mine head.

**Does it scale?** 74 mines across 10 companies on one laptop, every dashboard request under 150 ms.
The data model has companies and areas, sensor data is partitioned by month, and each screen is one
request. Beyond that it is untested.

**Who translated the six languages?** English is the source. The other five are machine drafts
using a project glossary, marked unreviewed in each file, for a native speaker to check.

**Is it secure?** It has had a review (`docs/SECURITY.md`): dependency audits (no high or critical
issues left), security headers, sign-in rate limits, uploads checked by content, no error details to
clients, and the API reachable only from the laptop and through the field server.
