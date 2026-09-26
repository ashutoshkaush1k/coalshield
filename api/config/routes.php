<?php

/**
 * Every route the API serves. Strict parsing: anything not listed is 404 NOT_FOUND.
 */

declare(strict_types=1);

return [
    'OPTIONS v1/<path:.*>' => 'v1/default/options',
    'GET v1/health' => 'v1/default/health',

    // Phase 1 - auth and profile
    'POST v1/auth/login' => 'v1/auth/login',
    'GET v1/auth/me' => 'v1/user/me',            // alias of /users/me until the frontend switches (PLAN 7)
    'GET v1/users/me' => 'v1/user/me',
    'PATCH v1/users/me' => 'v1/user/update-me',

    // Phase 1 - mines (read-only; Phase 2 adds compliance fields and GeoJSON)
    'GET v1/mines' => 'v1/mine/index',
    'GET v1/mines/<id:\d+>' => 'v1/mine/view',
];
