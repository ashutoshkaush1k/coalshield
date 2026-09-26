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
];

$read = ['user.viewOwn', 'user.updateOwnLanguage', 'mine.view', 'dashboard.view', 'sensor.view', 'violation.view',
    'correctiveAction.view', 'inspection.view', 'alert.view', 'alert.acknowledge', 'audit.view', 'compliance.view',
    'incident.view'];

return [
    'permissions' => $permissions,
    'roles' => [
        'government' => array_merge($read, ['sensor.viewFleet', 'inspection.viewQueue', 'inspection.manage',
            'directive.create', 'directive.reopen', 'incident.create', 'incident.linkViolation', 'vision.analyze',
            'admin.baselineCheck']),
        // Corporate management: every mine of its company, read-only plus the ranking.
        'corporate' => array_merge($read, ['sensor.viewFleet', 'inspection.viewQueue']),
        'mine_head' => array_merge($read, ['correctiveAction.create', 'correctiveAction.resolve', 'alert.resolve',
            'incident.create', 'incident.linkViolation', 'vision.analyze']),
        // Scoped like government for reading; carries out inspections.
        'inspector' => array_merge($read, ['sensor.viewFleet', 'inspection.viewQueue', 'inspection.manage']),
    ],
];
