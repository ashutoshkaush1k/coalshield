<?php

/**
 * Web configuration against the test database (DB_TEST_NAME). Used by Codeception.
 */

declare(strict_types=1);

defined('COALSHIELD_TEST') or define('COALSHIELD_TEST', true);

return yii\helpers\ArrayHelper::merge(require __DIR__ . '/web.php', [
    'id' => 'coalshield-api-test',
    'components' => [
        'db' => require __DIR__ . '/db.php',
        'request' => [
            'cookieValidationKey' => 'test-only',
        ],
    ],
]);
