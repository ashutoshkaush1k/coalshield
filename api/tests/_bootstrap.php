<?php

/**
 * Tests run against the TEST database (DB_TEST_NAME), prepared by run_tests.bat:
 * migrations from scratch + `yii_test seed small`. Each test runs in a transaction that is rolled
 * back (Yii2 module `transaction: true`), so tests never see each other's writes.
 */

declare(strict_types=1);

define('COALSHIELD_TEST', true);
define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);

require dirname(__DIR__) . '/config/bootstrap.php';
