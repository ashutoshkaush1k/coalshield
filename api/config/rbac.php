<?php

/**
 * Roles and permissions (brief rule 3), installed by `yii rbac/init` (idempotent).
 * Controllers check permissions, never roles. Later phases add their permissions here.
 */

declare(strict_types=1);

$all = ['user.viewOwn', 'user.updateOwnLanguage', 'mine.view'];

return [
    'permissions' => [
        'user.viewOwn' => 'Read own profile',
        'user.updateOwnLanguage' => 'Change own preferred language',
        'mine.view' => 'List and read mines in scope',
    ],
    'roles' => [
        'government' => $all,
        'corporate' => $all,
        'mine_head' => $all,
        // Defined but unused for now (brief Phase 1); scoped like government for reading.
        'inspector' => $all,
    ],
];
