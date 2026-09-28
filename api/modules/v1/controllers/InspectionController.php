<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\ListingQuery;
use app\components\StatusTransition;
use app\models\Inspection;
use app\models\Mine;
use app\models\Observation;
use app\services\InspectionPriorityService;
use app\services\InspectionService;
use Yii;

/**
 * GET  /v1/inspections/priority?state=&limit=   ranked queue (multi-mine roles)
 * GET  /v1/inspections, /v1/inspections/{id}   records in scope, detail with observations
 * POST /v1/inspections                          schedule {mine_id, inspection_type, scheduled_for}
 * PATCH /v1/inspections/{id}                    edit; a closed (locked) one needs `reason`
 * POST /v1/inspections/{id}/visit | /close      transitions
 * POST /v1/inspections/{id}/observations        record {category, severity}
 * POST /v1/observations/{id}/promote {violation_type} | /dismiss
 */
class InspectionController extends ApiController
{
    protected function verbs(): array
    {
        return ['priority' => ['GET'], 'index' => ['GET'], 'view' => ['GET'], 'create' => ['POST'], 'update' => ['PATCH'],
            'visit' => ['POST'], 'close' => ['POST'], 'observe' => ['POST'], 'promote' => ['POST'], 'dismiss' => ['POST'],
            'observations' => ['GET']];
    }

    public function actionPriority(): array
    {
        $this->requirePermission('inspection.viewQueue');
        $request = Yii::$app->request;
        $candidates = InspectionPriorityService::queue($this->visibleMines($request->get('state')), null, $this->currentUser());
        $limit = $request->get('limit');
        if ($limit !== null && ctype_digit((string) $limit)) {
            $candidates = array_slice($candidates, 0, max(1, min(100, (int) $limit)));
        }
        return [
            'weight_trend' => (float) Yii::$app->params['priority.weightTrend'],
            'trend_window_hours' => (int) Yii::$app->params['priority.trendWindowHours'],
            'mine_count' => count($candidates),
            'candidates' => $candidates,
        ];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('inspection.view');
        return ListingQuery::apply($this->scopedList(Inspection::find())->with(['mine', 'inspector']),
            ['status', 'inspection_type', 'inspector_id', 'is_locked'], ['scheduled_for', 'id'], '-scheduled_for');
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('inspection.view');
        return Inspection::findScoped($id)->toArray([], ['observations', 'history', 'edits']);
    }

    public function actionCreate(): Inspection
    {
        $this->requirePermission('inspection.manage');
        $body = $this->body();
        if (!isset($body['mine_id']) || !is_numeric($body['mine_id'])) {
            throw ApiException::fields(['mine_id' => ['REQUIRED']]);
        }
        Yii::$app->response->statusCode = 201;
        return InspectionService::schedule(Mine::findScoped((int) $body['mine_id']), $this->currentUser(), $body);
    }

    public function actionUpdate(int $id): array
    {
        $this->requirePermission('inspection.manage');
        return InspectionService::update(Inspection::findScoped($id), $this->currentUser(), $this->body())->toArray([], ['edits']);
    }

    public function actionVisit(int $id): Inspection
    {
        $this->requirePermission('inspection.manage');
        $inspection = Inspection::findScoped($id);
        StatusTransition::apply($inspection, 'visited');
        return $inspection;
    }

    public function actionClose(int $id): Inspection
    {
        $this->requirePermission('inspection.manage');
        $inspection = Inspection::findScoped($id);
        StatusTransition::apply($inspection, 'closed');
        return $inspection;
    }

    public function actionObservations(): array
    {
        $this->requirePermission('inspection.view');
        return ListingQuery::apply($this->scopedList(Observation::find()),
            ['status', 'category', 'severity', 'inspection_id'], ['observed_at', 'id'], '-observed_at');
    }

    public function actionObserve(int $id): Observation
    {
        $this->requirePermission('inspection.manage');
        Yii::$app->response->statusCode = 201;
        return InspectionService::addObservation(Inspection::findScoped($id), $this->body());
    }

    public function actionPromote(int $id): array
    {
        $this->requirePermission('inspection.manage');
        $observation = Observation::findScoped($id);
        $violation = InspectionService::promote($observation, $this->body());
        return ['observation' => $observation->toArray(), 'violation' => $violation->toArray()];
    }

    public function actionDismiss(int $id): Observation
    {
        $this->requirePermission('inspection.manage');
        $observation = Observation::findScoped($id);
        StatusTransition::apply($observation, 'dismissed', array_filter(['reason' => trim((string) ($this->body()['reason'] ?? ''))]));
        return $observation;
    }
}
