<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\models\Mine;
use app\models\ProductionDetailRequest;
use app\services\DetailRequestService;
use Yii;
use yii\web\UploadedFile;

/**
 * "Call for Detailed Report" (brief Phase 4).
 *
 *   GET  /v1/detail-requests?mine_id=&status=   in scope, newest first
 *   POST /v1/detail-requests                    {mine_id, date_from, date_to, reason, due_at}
 *   GET  /v1/detail-requests/{id}               with its history
 *   POST /v1/detail-requests/{id}/respond       mine head: response_note, optional file (multipart)
 *   POST /v1/detail-requests/{id}/close         the requesting side accepts the answer
 * Reading runs the deadline check first (DetailRequestService::escalateDue).
 */
class DetailRequestController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'create' => ['POST'], 'view' => ['GET'], 'respond' => ['POST'], 'close' => ['POST']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('detailRequest.view');
        DetailRequestService::escalateDue();
        $query = $this->scopedList(ProductionDetailRequest::find())->with(['mine', 'requester', 'responder', 'responseFile']);
        if (($status = Yii::$app->request->get('status')) !== null && $status !== '') {
            $query->andWhere(['production_detail_request.status' => explode(',', (string) $status)]);
        }
        $requests = $query->orderBy(['production_detail_request.created_at' => SORT_DESC, 'production_detail_request.id' => SORT_DESC])
            ->limit(200)->all();
        return array_map(fn(ProductionDetailRequest $r) => $r->toArray(), $requests);
    }

    public function actionCreate(): array
    {
        $this->requirePermission('detailRequest.create');
        $body = $this->body();
        if (!isset($body['mine_id']) || !ctype_digit((string) $body['mine_id'])) {
            throw ApiException::fields(['mine_id' => ['REQUIRED']]);
        }
        $mine = Mine::findScoped((int) $body['mine_id']);
        Yii::$app->response->statusCode = 201;
        return DetailRequestService::create($this->currentUser(), $mine, $body)->toArray();
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('detailRequest.view');
        DetailRequestService::escalateDue();
        return ProductionDetailRequest::findScoped($id)->toArray([], ['history']);
    }

    public function actionRespond(int $id): array
    {
        $this->requirePermission('detailRequest.respond');
        $request = ProductionDetailRequest::findScoped($id);
        $note = (string) (Yii::$app->request->post('response_note') ?? $this->body()['response_note'] ?? '');
        return DetailRequestService::respond($request, $this->currentUser(), $note, UploadedFile::getInstanceByName('file'))
            ->toArray([], ['history']);
    }

    public function actionClose(int $id): array
    {
        $this->requirePermission('detailRequest.create');
        $request = ProductionDetailRequest::findScoped($id);
        $note = $this->body()['note'] ?? null;
        return DetailRequestService::close($request, $note === null ? null : (string) $note)->toArray([], ['history']);
    }
}
