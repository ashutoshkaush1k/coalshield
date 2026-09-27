<?php

declare(strict_types=1);

namespace app\components;

use app\models\ProductionDetailRequest;
use Yii;

/**
 * Access rules beyond "which rows are in scope" (ScopedActiveQuery) and "which actions a role may
 * take" (RBAC) - rules that depend on the data (PLAN.md §4). Declared here, once, and applied by
 * the controllers through assert*(), never re-implemented ad hoc.
 *
 * Production detail (brief Phase 4): a mine head sees its own mine's entries in full
 * (production.viewDetail). A multi-mine role sees numbers only (GET /v1/production/summary); the
 * entries of a mine and date range open to it only when a "Call for Detailed Report" covering that
 * range has been answered (status submitted or closed) - otherwise 403 DETAIL_REQUEST_REQUIRED.
 * The mine itself must be in scope first (404 otherwise), so the rule never reveals anything
 * about a mine the account cannot see.
 *
 * Grievances (brief Phase 5):
 *  - Sensitive routing: a grievance about harassment, or against the mine head, goes to the
 *    government and **does not exist for the mine head** - not in lists, not by id (404), not
 *    through its alerts, its timeline or the audit trail.
 *  - Identity: a complainant's name and contact are serialised only to the regulator (government,
 *    inspector) - never to a mine head, and to corporate only for grievances that are not
 *    sensitive.
 */
final class AccessRule
{
    /** SQL condition (on the grievance table, alias optional) that is true for a sensitive grievance. */
    public static function sensitiveGrievanceSql(string $alias = 'grievance'): string
    {
        return "($alias.category = 'harassment' OR $alias.against_mine_head)";
    }

    /** Ids of the sensitive grievances, as a subquery. */
    public static function sensitiveGrievanceIds(): \yii\db\Query
    {
        return (new \yii\db\Query())->select('g.id')->from(['g' => '{{%grievance}}'])
            ->where(new \yii\db\Expression(self::sensitiveGrievanceSql('g')));
    }

    /** Whether this user may see the sensitive grievances of the mines in scope. */
    public static function seesSensitiveGrievances(\app\models\User $user): bool
    {
        return $user->role !== \app\models\User::ROLE_MINE_HEAD;
    }

    /** Whether this user may see who submitted a grievance (name, contact). */
    public static function seesGrievanceIdentity(?\app\models\User $user, bool $sensitive): bool
    {
        return match ($user?->role) {
            \app\models\User::ROLE_GOVERNMENT, \app\models\User::ROLE_INSPECTOR => true,
            \app\models\User::ROLE_CORPORATE => !$sensitive,
            default => false,
        };
    }

    /** A fulfilled request at this mine whose range contains [$from, $to] entirely. */
    public static function detailRequestCovers(int $mineId, string $from, string $to): ?ProductionDetailRequest
    {
        return ProductionDetailRequest::find()
            ->where(['mine_id' => $mineId, 'status' => ProductionDetailRequest::FULFILLED])
            ->andWhere(['<=', 'date_from', $from])->andWhere(['>=', 'date_to', $to])
            ->orderBy(['responded_at' => SORT_DESC, 'id' => SORT_DESC])->one();
    }

    /**
     * @return ProductionDetailRequest|null the covering request (null for the mine's own head)
     * @throws ApiException 403 DETAIL_REQUEST_REQUIRED or FORBIDDEN
     */
    public static function assertProductionDetail(int $mineId, string $from, string $to): ?ProductionDetailRequest
    {
        $user = Yii::$app->user;
        if ($user->can('production.viewDetail')) {
            return null;
        }
        if (!$user->can('production.viewRequested')) {
            throw ApiException::forbidden();
        }
        $request = self::detailRequestCovers($mineId, $from, $to);
        if ($request === null) {
            throw new ApiException(403, 'DETAIL_REQUEST_REQUIRED', ['mine_id' => $mineId, 'from' => $from, 'to' => $to]);
        }
        return $request;
    }
}
