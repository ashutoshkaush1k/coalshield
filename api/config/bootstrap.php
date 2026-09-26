<?php

/**
 * Loaded first by every entry point (web/index.php, yii, yii_test, tests/_bootstrap.php).
 * Reads api/.env (rule 11: secrets only in .env) and sets aliases.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

/** Read an environment value with a default. */
function env(string $name, mixed $default = null): mixed
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
}

defined('YII_DEBUG') or define('YII_DEBUG', (bool) env('YII_DEBUG', false));
defined('YII_ENV') or define('YII_ENV', (string) env('YII_ENV', 'prod'));

require_once $root . '/vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@app', $root);
