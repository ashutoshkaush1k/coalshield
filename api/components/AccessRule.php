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
 */
final class AccessRule
{
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
