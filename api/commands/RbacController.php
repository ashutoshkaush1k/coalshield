<?php

declare(strict_types=1);

namespace app\commands;

use app\components\CacheReset;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Connection;
use yii\rbac\DbManager;

/**
 * yii rbac/init  - create or update roles and permissions from config/rbac.php (idempotent),
 *                  then sync assignments.
 * yii rbac/sync  - assign each user the role named in user.role (the column is the source of truth).
 */
class RbacController extends Controller
{
    public function actionInit(): int
    {
        self::install();
        $count = self::syncAssignments(Yii::$app->db);
        CacheReset::flush();   // the item tree is cached (config/common.php)
        $this->stdout("RBAC installed; $count users assigned.\n");
        return ExitCode::OK;
    }

    public function actionSync(): int
    {
        $count = self::syncAssignments(Yii::$app->db);
        $this->stdout("$count users assigned.\n");
        return ExitCode::OK;
    }

    /** Roles and permissions from config/rbac.php; children not listed there are removed. */
    public static function install(): void
    {
        $auth = self::auth();
        $config = require Yii::getAlias('@app/config/rbac.php');

        foreach ($config['permissions'] as $name => $description) {
            $permission = $auth->getPermission($name);
            if ($permission === null) {
                $permission = $auth->createPermission($name);
                $permission->description = $description;
                $auth->add($permission);
            } elseif ($permission->description !== $description) {
                $permission->description = $description;
                $auth->update($name, $permission);
            }
        }
        foreach ($config['roles'] as $roleName => $permissions) {
            $role = $auth->getRole($roleName);
            if ($role === null) {
                $role = $auth->createRole($roleName);
                $auth->add($role);
            }
            $current = array_keys($auth->getChildren($roleName));
            foreach (array_diff($permissions, $current) as $name) {
                $auth->addChild($role, $auth->getPermission($name));
            }
            foreach (array_diff($current, $permissions) as $name) {
                $auth->removeChild($role, $auth->getPermission($name) ?? $auth->getRole($name));
            }
        }
    }

    /** Replace all assignments with one per user from user.role. Returns the number assigned. */
    public static function syncAssignments(Connection $db): int
    {
        $auth = self::auth();
        if ($auth->getRoles() === []) {
            self::install();
        }
        $table = $auth->assignmentTable;
        $db->createCommand()->delete($table)->execute();
        return $db->createCommand(
            "INSERT INTO $table (item_name, user_id, created_at)
             SELECT role, id::text, extract(epoch FROM now())::int FROM \"user\"
              WHERE role IN (SELECT name FROM {$auth->itemTable} WHERE type = 1)"
        )->execute();
    }

    private static function auth(): DbManager
    {
        /** @var DbManager $auth */
        $auth = Yii::$app->authManager;
        return $auth;
    }
}
