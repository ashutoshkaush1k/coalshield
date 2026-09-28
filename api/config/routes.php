<?php

/**
 * Every route the API serves. Strict parsing: anything not listed is 404 NOT_FOUND.
 * Contract differences from the FastAPI prototype: docs/API_CHANGES.md.
 */

declare(strict_types=1);

return [
    'OPTIONS v1/<path:.*>' => 'v1/default/options',
    'GET v1/health' => 'v1/default/health',

    // Auth and profile
    'POST v1/auth/login' => 'v1/auth/login',
    'GET v1/auth/me' => 'v1/user/me',
    'GET v1/users/me' => 'v1/user/me',
    'PATCH v1/users/me' => 'v1/user/update-me',

    // Mines, dashboard, compliance
    'GET v1/mines' => 'v1/mine/index',
    'GET v1/mines/geojson' => 'v1/mine/geojson',
    'GET v1/mines/<id:\d+>' => 'v1/mine/view',
    'GET v1/dashboard' => 'v1/dashboard/index',
    // One request per dashboard screen and polling cycle (docs/PERFORMANCE.md)
    'GET v1/views/overview' => 'v1/view/overview',
    'GET v1/views/mine/<id:\d+>' => 'v1/view/mine',
    'GET v1/views/production' => 'v1/view/production',
    'GET v1/views/production-overview' => 'v1/view/production-overview',

    'GET v1/views/grievances' => 'v1/view/grievances',

    // Grievances (Phase 5): public (no login) and staff
    'GET v1/public/mines' => 'v1/grievance-public/mines',
    'POST v1/grievances/public' => 'v1/grievance-public/submit',
    'POST v1/grievances/track' => 'v1/grievance-public/track',
    'GET v1/grievances' => 'v1/grievance/index',
    'GET v1/grievances/stats' => 'v1/grievance/stats',
    'GET v1/grievances/<id:\d+>' => 'v1/grievance/view',
    'GET v1/grievances/<id:\d+>/assignees' => 'v1/grievance/assignees',
    'POST v1/grievances/<id:\d+>/transition' => 'v1/grievance/transition',
    'POST v1/grievances/<id:\d+>/assign' => 'v1/grievance/assign',

    // Obligation register and map (Phase 5B)
    'GET v1/obligations' => 'v1/obligation/index',
    'GET v1/obligations/summary' => 'v1/obligation/summary',
    'GET v1/obligation-tasks' => 'v1/obligation/tasks',
    'GET v1/obligation-tasks/<id:\d+>' => 'v1/obligation/task',
    'POST v1/obligation-tasks/<id:\d+>/submissions' => 'v1/obligation/submit',
    'POST v1/obligation-submissions/<id:\d+>/review' => 'v1/obligation/review',
    'POST v1/obligation-tasks/<id:\d+>/waive' => 'v1/obligation/waive',
    'GET v1/geo/<kind:states|districts>' => 'v1/geo/boundaries',
    'GET v1/views/obligations' => 'v1/view/obligations',
    'GET v1/views/map' => 'v1/view/map',
    // Phase 7: automation findings, the Governance Risk Index, the predictive model
    'GET v1/views/priority' => 'v1/view/priority',
    'GET v1/anomalies' => 'v1/risk/anomalies',
    'GET v1/mines/<id:\d+>/risk' => 'v1/risk/mine',
    'GET v1/risk/model' => 'v1/risk/model',

    // Production reporting (Phase 4)
    'GET v1/production' => 'v1/production/index',
    'POST v1/production' => 'v1/production/create',
    'GET v1/production/summary' => 'v1/production/summary',
    'GET v1/production/detail' => 'v1/production/detail',
    'GET v1/production/<id:\d+>' => 'v1/production/view',
    'PATCH v1/production/<id:\d+>' => 'v1/production/update',
    'POST v1/production/<id:\d+>/submit' => 'v1/production/submit',
    'DELETE v1/production/<id:\d+>' => 'v1/production/delete',
    'GET v1/detail-requests' => 'v1/detail-request/index',
    'POST v1/detail-requests' => 'v1/detail-request/create',
    'GET v1/detail-requests/<id:\d+>' => 'v1/detail-request/view',
    'POST v1/detail-requests/<id:\d+>/respond' => 'v1/detail-request/respond',
    'POST v1/detail-requests/<id:\d+>/close' => 'v1/detail-request/close',
    'GET v1/compliance/<mine_id:\d+>' => 'v1/compliance/view',
    'GET v1/compliance/<mine_id:\d+>/history' => 'v1/compliance/history',

    // Sensors (read paths kept from the prototype) and machine ingest
    'GET v1/sensors' => 'v1/sensor/fleet',
    'GET v1/sensors/breaches' => 'v1/sensor/breaches',
    'GET v1/sensors/thresholds' => 'v1/sensor/thresholds',
    'GET v1/sensors/<mine_id:\d+>' => 'v1/sensor/readings',
    'GET v1/sensors/<mine_id:\d+>/trend' => 'v1/sensor/trend',
    'POST v1/sensor-readings/ingest' => 'v1/ingest/index',
    'GET v1/sensor-readings/baseline' => 'v1/ingest/baseline',

    // Violations and corrective actions
    'GET v1/violations' => 'v1/violation/index',
    'GET v1/violations/<id:\d+>' => 'v1/violation/view',
    'PATCH v1/violations/<id:\d+>/contractor' => 'v1/violation/contractor',

    // Contractors (Phase 3)
    'GET v1/contractors' => 'v1/contractor/index',
    'GET v1/contractors/summary' => 'v1/contractor/summary',
    'POST v1/contractors' => 'v1/contractor/create',
    'GET v1/contractors/<id:\d+>' => 'v1/contractor/view',
    'PATCH v1/contractors/<id:\d+>' => 'v1/contractor/update',
    'POST v1/contractors/<id:\d+>/status' => 'v1/contractor/status',
    'GET v1/contracts' => 'v1/contract/index',
    'POST v1/contracts' => 'v1/contract/create',
    'PATCH v1/contracts/<id:\d+>' => 'v1/contract/update',
    'DELETE v1/contracts/<id:\d+>' => 'v1/contract/delete',
    'POST v1/contracts/<id:\d+>/workers' => 'v1/contract/add-worker',
    'PATCH v1/contract-workers/<id:\d+>' => 'v1/contract/update-worker',
    'DELETE v1/contract-workers/<id:\d+>' => 'v1/contract/delete-worker',
    'POST v1/contracts/<id:\d+>/documents' => 'v1/contract/upload',
    'POST v1/contractor-docs/<id:\d+>/verify' => 'v1/contract/verify',
    'DELETE v1/contractor-docs/<id:\d+>' => 'v1/contract/delete-document',
    'GET v1/corrective-actions' => 'v1/corrective-action/index',
    'POST v1/corrective-actions' => 'v1/corrective-action/create',
    'GET v1/corrective-actions/<id:\d+>' => 'v1/corrective-action/view',
    'POST v1/corrective-actions/<id:\d+>/resolve' => 'v1/corrective-action/resolve',

    // Inspections, observations, priority queue
    'GET v1/inspections/priority' => 'v1/inspection/priority',
    'GET v1/inspections' => 'v1/inspection/index',
    'POST v1/inspections' => 'v1/inspection/create',
    'GET v1/inspections/<id:\d+>' => 'v1/inspection/view',
    'PATCH v1/inspections/<id:\d+>' => 'v1/inspection/update',
    'POST v1/inspections/<id:\d+>/visit' => 'v1/inspection/visit',
    'POST v1/inspections/<id:\d+>/close' => 'v1/inspection/close',
    'POST v1/inspections/<id:\d+>/observations' => 'v1/inspection/observe',
    'GET v1/observations' => 'v1/inspection/observations',
    'POST v1/observations/<id:\d+>/promote' => 'v1/inspection/promote',
    'POST v1/observations/<id:\d+>/dismiss' => 'v1/inspection/dismiss',

    // Alerts and directives
    'GET v1/alerts' => 'v1/alert/index',
    'POST v1/alerts/directives' => 'v1/alert/directive',
    'GET v1/alerts/<id:\d+>' => 'v1/alert/view',
    'POST v1/alerts/<id:\d+>/ack' => 'v1/alert/acknowledge',
    'POST v1/alerts/<id:\d+>/resolve' => 'v1/alert/resolve',
    'POST v1/alerts/<id:\d+>/reopen' => 'v1/alert/reopen',

    // Incidents
    'GET v1/incidents' => 'v1/incident/index',
    'POST v1/incidents' => 'v1/incident/create',
    'GET v1/incidents/<id:\d+>' => 'v1/incident/view',
    'PATCH v1/incidents/<id:\d+>/violation' => 'v1/incident/link',

    // Audit trail, vision, files, admin
    'GET v1/audit' => 'v1/audit/index',
    'POST v1/vision/analyze' => 'v1/vision/analyze',
    'GET v1/files/<id:\d+>/content' => 'v1/file/content',
    'GET v1/admin/baseline-check' => 'v1/admin/baseline-check',
];
