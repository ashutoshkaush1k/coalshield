<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\Format;
use app\models\ObligationTask;
use app\services\ObligationService;
use Yii;

/**
 * One request per dashboard screen and polling cycle (docs/PERFORMANCE.md).
 *
 * Each view is assembled from the same actions that serve the individual endpoints - run in
 * this request, with their own permission checks and scoping - so every part has exactly the
 * shape its endpoint documents, and an out-of-scope mine is 404 for the whole view.
 *
 *   GET /v1/views/overview?state=   dashboard + contractor_summary (null without contractor.summary)
 *   GET /v1/views/mine/{id}         mine, trend, violations, alerts, audit, corrective_actions, incidents
 *   GET /v1/views/production?month= mine head: entries, charts, requests of the own mine
 *   GET /v1/views/production-overview?state=&date=   summary (numbers only) + requests
 */
class ViewController extends ApiController
{
    protected function verbs(): array
    {
        return ['overview' => ['GET'], 'mine' => ['GET'], 'production' => ['GET'], 'production-overview' => ['GET'], 'grievances' => ['GET'], 'obligations' => ['GET'], 'map' => ['GET']];
    }

    public function actionOverview(): array
    {
        $state = Yii::$app->request->get('state');
        $query = $state !== null && $state !== '' ? ['state' => $state] : [];
        return [
            'dashboard' => $this->part('v1/dashboard/index', $query),
            'contractor_summary' => Yii::$app->user->can('contractor.summary')
                ? $this->part('v1/contractor/summary', $query) : null,
        ];
    }

    public function actionMine(int $id): array
    {
        $mine = ['mine_id' => $id];
        return [
            'mine' => $this->part('v1/mine/view', [], ['id' => $id]),
            'trend' => $this->part('v1/sensor/trend', ['points' => 40], ['mine_id' => $id]),
            'violations' => $this->part('v1/violation/index', $mine + ['per_page' => 50]),
            'alerts' => $this->part('v1/alert/index', $mine + ['per_page' => 30]),
            'audit' => $this->part('v1/audit/index', $mine + ['per_page' => 40]),
            'corrective_actions' => $this->part('v1/corrective-action/index', $mine + ['per_page' => 50]),
            'incidents' => $this->part('v1/incident/index', $mine + ['per_page' => 50]),
        ];
    }

    /**
     * Mine head's production screen: the month's entries and charts, and the calls for detailed
     * report addressed to the mine. ?month=YYYY-MM, the current month by default.
     */
    public function actionProduction(): array
    {
        $this->requirePermission('production.manage');
        $mineId = $this->currentUser()->mine_id;
        if ($mineId === null) {
            throw \app\components\ApiException::forbidden();
        }
        $today = \app\services\ProductionService::today();
        $month = (string) (Yii::$app->request->get('month') ?: substr($today, 0, 7));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw \app\components\ApiException::fields(['month' => ['INVALID_VALUE']]);
        }
        $from = $month . '-01';
        $to = min((new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d'), $today);
        if ($to < $from) {
            $to = $from;
        }
        $range = ['mine_id' => $mineId, 'from' => $from, 'to' => $to];
        return [
            'month' => $month,
            'today' => $today,
            'entries' => $this->part('v1/production/index', $range),
            'charts' => \app\services\ProductionService::charts((int) $mineId, $from, $to),
            'requests' => $this->part('v1/detail-request/index', ['mine_id' => $mineId]),
        ];
    }

    /** Government / corporate production screen: the numbers-only summary and the requests. */
    public function actionProductionOverview(): array
    {
        $request = Yii::$app->request;
        $query = array_filter(['state' => $request->get('state'), 'date' => $request->get('date')], fn($v) => $v !== null && $v !== '');
        return [
            'summary' => $this->part('v1/production/summary', $query),
            'requests' => $this->part('v1/detail-request/index', []),
        ];
    }

    /**
     * The grievance screen. A mine head: its queue. Multi-mine roles: analytics, the escalated
     * open queue, and the grievances (newest SLA first). ?state= narrows all parts.
     */
    public function actionGrievances(): array
    {
        $this->requirePermission('grievance.view');
        $state = Yii::$app->request->get('state');
        $query = $state !== null && $state !== '' ? ['state' => $state] : [];
        if (!Yii::$app->user->can('grievance.stats')) {
            return ['stats' => null, 'escalated' => null, 'grievances' => $this->part('v1/grievance/index', ['per_page' => 200])];
        }
        return [
            'stats' => $this->part('v1/grievance/stats', $query),
            'escalated' => $this->part('v1/grievance/index', $query + ['escalated' => 1, 'open' => 1, 'per_page' => 100]),
            'grievances' => $this->part('v1/grievance/index', $query + ['per_page' => 200]),
        ];
    }

    /**
     * The obligation register screen. A mine head: its own register (statutory compliance, due soon,
     * overdue, submitted, recently accepted). Multi-mine roles: statutory compliance per mine and
     * company, the most overdue items, and the evidence awaiting review. ?state= narrows.
     */
    public function actionObligations(): array
    {
        $this->requirePermission('obligation.view');
        $state = Yii::$app->request->get('state');
        $query = $state !== null && $state !== '' ? ['state' => $state] : [];
        if (!Yii::$app->user->can('obligation.summary')) {
            return $this->mineRegister();
        }
        return [
            'summary' => $this->part('v1/obligation/summary', $query),
            'pending_review' => $this->part('v1/obligation/tasks', $query + ['view' => 'submitted', 'per_page' => 50]),
        ];
    }

    /**
     * A mine head's register in two queries: every task not yet accepted, and the latest accepted
     * ones, split into the screen's lists here.
     */
    private function mineRegister(): array
    {
        $this->requirePermission('obligation.view');
        ObligationService::checkForRequest();
        $mineId = $this->currentUser()->mine_id;
        $with = ['obligation', 'mine', 'latestSubmission.submitter', 'latestSubmission.reviewer', 'latestSubmission.file'];
        $base = fn() => ObligationTask::find()->forCurrentUser()->with($with);
        $pending = $base()->andWhere(['obligation_task.status' => ['open', 'rejected', 'overdue', 'escalated', 'submitted']])
            ->orderBy(['obligation_task.due_at' => SORT_ASC, 'obligation_task.id' => SORT_ASC])->all();
        $accepted = $base()->andWhere(['obligation_task.status' => 'accepted'])
            ->orderBy(['obligation_task.due_at' => SORT_DESC, 'obligation_task.id' => SORT_ASC])->limit(20)->all();
        $soonBefore = Format::now()->modify('+' . (int) ObligationService::settings()['reminder_days'] . ' days')->getTimestamp();
        $lists = ['due_soon' => [], 'overdue' => [], 'submitted' => [], 'open' => []];
        foreach ($pending as $task) {
            $row = $task->toArray();
            if (in_array($task->status, ObligationTask::LATE, true)) {
                $lists['overdue'][] = $row;
            } elseif ($task->status === 'submitted') {
                $lists['submitted'][] = $row;
            } else {
                $lists['open'][] = $row;
                if (strtotime((string) $task->due_at) <= $soonBefore) {
                    $lists['due_soon'][] = $row;
                }
            }
        }
        return ['summary' => ObligationService::summary($mineId === null ? [] : [(int) $mineId])] + $lists
            + ['accepted' => array_map(fn(ObligationTask $t) => $t->toArray(), $accepted)];
    }

    /** The map: the mines in scope with their score and band (outlines come from /v1/geo/*, once). */
    public function actionMap(): array
    {
        $state = Yii::$app->request->get('state');
        return ['mines' => $this->part('v1/mine/geojson', $state !== null && $state !== '' ? ['state' => $state] : [])];
    }

    /** Run another v1 action with the given query string; its serialized result. */
    private function part(string $route, array $query, array $params = []): mixed
    {
        $request = Yii::$app->request;
        $saved = $request->getQueryParams();
        $request->setQueryParams($query);
        try {
            return Yii::$app->runAction($route, $params);
        } finally {
            $request->setQueryParams($saved);
            // Paged parts set X-Total-Count and friends; they describe no single list here.
            foreach (['X-Total-Count', 'X-Page', 'X-Per-Page', 'X-Pagination-Total-Count', 'X-Pagination-Page-Count',
                'X-Pagination-Current-Page', 'X-Pagination-Per-Page', 'Link'] as $header) {
                Yii::$app->response->headers->remove($header);
            }
        }
    }
}
