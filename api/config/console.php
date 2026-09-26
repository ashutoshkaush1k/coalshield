<?php

declare(strict_types=1);

$common = require __DIR__ . '/common.php';

return yii\helpers\ArrayHelper::merge($common, [
    'id' => 'coalshield-console',
    'bootstrap' => ['log', 'queue'],
    'controllerNamespace' => 'app\commands',
    'controllerMap' => [
        'migrate' => [
            'class' => yii\console\controllers\MigrateController::class,
            'migrationPath' => ['@app/migrations', '@yii/rbac/migrations'],
            'migrationNamespaces' => ['yii\queue\db\migrations'],
            'templateFile' => '@yii/views/migration.php',
        ],
    ],
]);
