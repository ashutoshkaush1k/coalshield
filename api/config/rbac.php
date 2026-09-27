<?php

/**
 * Roles and permissions (brief rule 3), installed by `yii rbac/init` (idempotent).
 * Controllers check permissions, never roles; what a role may see is ScopedActiveQuery's job.
 * The frontend reads the caller's permissions from GET /v1/users/me to hide what would be refused.
 */

declare(strict_types=1);

$permissions = [
    // Phase 1
    'user.viewOwn' => 'Read own profile',
    'user.updateOwnLanguage' => 'Change own preferred language',
    'mine.view' => 'List and read mines in scope',
    // Phase 2
    'dashboard.view' => 'Dashboard for the mines in scope',
    'sensor.view' => 'Sensor readings, trends and breach charts in scope',
    'sensor.viewFleet' => 'Cross-mine sensor standing (more than one mine in scope)',
    'violation.view' => 'Violations in scope',
    'correctiveAction.view' => 'Corrective actions in scope',
    'correctiveAction.create' => 'Record a corrective action for an own violation',
    'correctiveAction.resolve' => 'Close a corrective action with proof',
    'inspection.view' => 'Inspection records in scope',
    'inspection.viewQueue' => 'Ranked inspection priority queue',
    'inspection.manage' => 'Schedule, visit, close and edit inspections; record and promote observations',
    'alert.view' => 'Alerts in scope',
    'alert.acknowledge' => 'Acknowledge an alert',
    'alert.resolve' => 'Close an alert or directive with proof',
    'directive.create' => 'Raise a directive against a mine',
    'directive.reopen' => 'Reopen a resolved directive',
    'audit.view' => 'Audit trail in scope',
    'compliance.view' => 'Compliance score and history in scope',
    'incident.view' => 'Incidents in scope',
    'incident.create' => 'Report an incident',
    'incident.linkViolation' => 'Link an incident to a violation',
    'vision.analyze' => 'Run PPE detection on an image for a mine in scope',
    'admin.baselineCheck' => 'Compare live scores with the seeded baseline (simulator pre-flight)',
    // Phase 3
    'contractor.view' => 'Contractors working at the mines in scope, with their compliance',
    'contractor.summary' => 'Per-mine contractor summary (multi-mine roles, read-only)',
    'contractor.manage' => 'Register contractors, manage contracts, workers and documents at the own mine',
    'violation.linkContractor' => 'Link a violation to the contractor responsible',
    // Phase 4
    'production.manage' => 'Enter, submit and correct (with a reason) the daily production of the own mine',
    'production.viewDetail' => 'Full production detail of the mines in scope (the own mine, for a mine head)',
    'production.summary' => 'Numbers-only production summary across the mines in scope',
    'production.viewRequested' => 'Production detail of a mine and range covered by an answered detail request (AccessRule)',
    'detailRequest.view' => 'Calls for detailed report on the mines in scope',
    'detailRequest.create' => 'Call for a detailed production report (date range, reason, deadline); accept the answer',
    'detailRequest.respond' => 'Answer a call for a detailed report for the own mine',
];

$read = ['user.viewOwn', 'user.updateOwnLanguage', 'mine.view', 'dashboard.view', 'sensor.view', 'violation.view',
    'correctiveAction.view', 'inspection.view', 'alert.view', 'alert.acknowledge', 'audit.view', 'compliance.view',
    'incident.view', 'contractor.view', 'detailRequest.view'];
$productionOversight = ['production.summary', 'production.viewRequested'];

return [
    'permissions' => $permissions,
    'roles' => [
        'government' => array_merge($read, ['sensor.viewFleet', 'inspection.viewQueue', 'inspection.manage',
            'directive.create', 'directive.reopen', 'incident.create', 'incident.linkViolation', 'vision.analyze',
            'admin.baselineCheck', 'contractor.summary', ...$productionOversight, 'detailRequest.create']),
        // Corporate management: every mine of its company, read-only plus the ranking.
        'corporate' => array_merge($read, ['sensor.viewFleet', 'inspection.viewQueue', 'contractor.summary', ...$productionOversight, 'detailRequest.create']),
        'mine_head' => array_merge($read, ['correctiveAction.create', 'correctiveAction.resolve', 'alert.resolve',
            'incident.create', 'incident.linkViolation', 'vision.analyze', 'contractor.manage', 'violation.linkContractor',
            'production.manage', 'production.viewDetail', 'detailRequest.respond']),
        // Scoped like government for reading; carries out inspections.
        'inspector' => array_merge($read, ['sensor.viewFleet', 'inspection.viewQueue', 'inspection.manage', 'contractor.summary', ...$productionOversight]),
    ],
];
