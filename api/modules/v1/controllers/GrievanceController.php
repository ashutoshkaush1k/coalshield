<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ListingQuery;
use app\models\Grievance;
use app\services\GrievanceService;
use Yii;

/**
 * Grievances for signed-in staff (brief Phase 5). Scoped like everything else, and routed by
 * AccessRule: a mine head never sees a sensitive grievance (404 by id), and a complainant's
 * identity is serialised only where AccessRule allows. Reading runs the SLA check first.
 *
 *   GET  /v1/grievances?mine_id=&status=&category=&open=1&escalated=1&sensitive=1&language=
 *   GET  /v1/grievances/stats?state=         analytics (multi-mine roles)
 *   GET  /v1/grievances/{id}                 with its timeline
 *   GET  /v1/grievances/{id}/assignees       who may take it
 *   POST /v1/grievances/{id}/transition      {to, note} - resolving needs a note
 *   POST /v1/grievances/{id}/assign          {user_id}
 */
class GrievanceController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'stats' => ['GET'], 'view' => ['GET'], 'assignees' => ['GET'], 'transition' => ['POST'], 'assign' => ['POST']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('grievance.view');
        GrievanceService::escalateDue();
        $request = Yii::$app->request;
        $query = $this->scopedList(Grievance::find())->with(['mine', 'assignee', 'file']);
        // null when the parameter is absent (filter_var would read an absent value as false).
        $flag = fn(string $name) => ($v = $request->get($name)) === null || $v === '' ? null : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (($state = $request->get('state')) !== null && $state !== '') {
            $query->andWhere(['{{%grievance}}.mine_id' => array_map(fn($m) => (int) $m->id, $this->visibleMines((string) $state))]);
        }
        if ($flag('open') === true) {
            $query->andWhere(['{{%grievance}}.status' => Grievance::OPEN]);
        }
        if ($flag('escalated') === true) {
            $query->andWhere(['>=', '{{%grievance}}.escalation_level', 1]);
        }
        if (($sensitive = $flag('sensitive')) !== null) {
            $query->andWhere(new \yii\db\Expression(($sensitive ? '' : 'NOT ') . \app\components\AccessRule::sensitiveGrievanceSql('{{%grievance}}')));
        }
        // Open and most urgent first: open before done, then the earliest SLA due.
        $query->addOrderBy(new \yii\db\Expression("CASE WHEN {{%grievance}}.status IN ('received', 'acknowledged', 'under_investigation', 'reopened') THEN 0 ELSE 1 END"));
        $rows = ListingQuery::apply($query, ['status', 'category', 'language', 'severity', 'submitter_type'], ['sla_due_at', 'created_at', 'id'], 'sla_due_at');
        return array_map(fn(Grievance $g) => $g->toArray(), $rows);
    }

    public function actionStats(): array
    {
        $this->requirePermission('grievance.stats');
        GrievanceService::escalateDue();
        $mines = $this->visibleMines(Yii::$app->request->get('state'));
        return GrievanceService::stats($this->currentUser(), array_map(fn($m) => (int) $m->id, $mines));
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('grievance.view');
        return Grievance::findScoped($id)->toArray([], ['timeline']);
    }

    public function actionAssignees(int $id): array
    {
        $this->requirePermission('grievance.manage');
        return GrievanceService::assignees(Grievance::findScoped($id));
    }

    public function actionTransition(int $id): array
    {
        $this->requirePermission('grievance.manage');
        $body = $this->body();
        $grievance = GrievanceService::transition(Grievance::findScoped($id), (string) ($body['to'] ?? ''), isset($body['note']) ? (string) $body['note'] : null);
        return $grievance->toArray([], ['timeline']);
    }

    public function actionAssign(int $id): array
    {
        $this->requirePermission('grievance.manage');
        $userId = $this->body()['user_id'] ?? null;
        if (!is_numeric($userId)) {
            throw \app\components\ApiException::fields(['user_id' => ['REQUIRED']]);
        }
        return GrievanceService::assign(Grievance::findScoped($id), (int) $userId, $this->currentUser())->toArray([], ['timeline']);
    }
}
