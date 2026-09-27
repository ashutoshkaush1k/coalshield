<?php

declare(strict_types=1);

namespace app\commands;

use app\components\CacheReset;

/**
 * `yii migrate` that flushes the schema and RBAC cache afterwards (docs/PERFORMANCE.md), so the
 * API never answers from a table layout the migration just changed.
 */
final class MigrateController extends \yii\console\controllers\MigrateController
{
    public function afterAction($action, $result)
    {
        $result = parent::afterAction($action, $result);
        CacheReset::flush();
        return $result;
    }
}
