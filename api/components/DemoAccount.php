<?php

declare(strict_types=1);

namespace app\components;

use app\models\User;
use Yii;
use yii\db\Connection;

/**
 * "Continue as admin (demo)" (POST /v1/auth/demo): one dedicated account, Demo Admin (DGMS), with
 * the government role (every mine), separate from every other account. Nobody knows its password
 * (a random one, never recorded); it is only signed in through the demo endpoint, which exists
 * only when DEMO_LOGIN_ENABLED=true, and its sessions last 30 minutes.
 */
final class DemoAccount
{
    public const EMAIL = 'demo.admin@dgms.example';
    public const NAME = 'Demo Admin (DGMS)';
    public const ROLE = 'government';

    public static function enabled(): bool
    {
        return (bool) (Yii::$app->params['demo.loginEnabled'] ?? false);
    }

    public static function is(?User $user): bool
    {
        return $user !== null && strcasecmp((string) $user->email, self::EMAIL) === 0;
    }

    /**
     * Inserts the account if it is missing (by plain SQL: the seed runs this before it writes the
     * audit chain's first entry, so nothing is audited here). The role assignment follows from
     * the user's role column (RbacController::syncAssignments, or assignRole() below).
     * @return int the account's id
     */
    public static function insertIfMissing(Connection $db): int
    {
        $id = $db->createCommand('SELECT id FROM {{%user}} WHERE lower(email) = :e', [':e' => self::EMAIL])->queryScalar();
        if ($id !== false) {
            return (int) $id;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return (int) $db->createCommand(
            'INSERT INTO {{%user}} (email, password_hash, full_name, role, preferred_language, status, created_at, updated_at)
             VALUES (:email, :hash, :name, :role, :lang, :status, :now, :now) RETURNING id',
            [':email' => self::EMAIL, ':name' => self::NAME, ':role' => self::ROLE, ':lang' => 'en', ':status' => User::STATUS_ACTIVE, ':now' => $now,
             ':hash' => Yii::$app->security->generatePasswordHash(Yii::$app->security->generateRandomString(48))],
        )->queryScalar();
    }

    /** The account's RBAC role, when it is not assigned yet. */
    public static function assignRole(int $id): void
    {
        $auth = Yii::$app->authManager;
        $role = $auth->getRole(self::ROLE);
        if ($role !== null && $auth->getAssignment(self::ROLE, (string) $id) === null) {
            $auth->assign($role, (string) $id);
        }
    }
}
