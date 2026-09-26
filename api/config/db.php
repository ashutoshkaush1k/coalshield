<?php

declare(strict_types=1);

/**
 * PostgreSQL connection from .env. The test configuration swaps DB_NAME for DB_TEST_NAME.
 * Every session runs in UTC so timestamps round-trip unchanged (brief rule 8: ISO-8601 UTC).
 */

$name = defined('COALSHIELD_TEST') ? (string) env('DB_TEST_NAME', 'coalshield_test') : (string) env('DB_NAME', 'coalshield');

return [
    'class' => yii\db\Connection::class,
    'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '5432'), $name),
    'username' => (string) env('DB_USER', 'coalshield'),
    'password' => (string) env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'on afterOpen' => static function ($event): void {
        $event->sender->createCommand("SET TIME ZONE 'UTC'")->execute();
    },
];
