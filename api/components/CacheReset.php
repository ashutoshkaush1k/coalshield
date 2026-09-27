<?php

declare(strict_types=1);

namespace app\components;

use Yii;

/**
 * Flushes the application cache (table schema, RBAC item tree) after anything that changes what
 * it holds: migrations, seeding, RBAC setup. The web app shares the cache directory, so a running
 * API sees the change on its next request.
 */
final class CacheReset
{
    public static function flush(): void
    {
        Yii::$app->cache->flush();
        Yii::$app->db->getSchema()->refresh();
    }
}
