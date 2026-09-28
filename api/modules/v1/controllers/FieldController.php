<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\services\FieldSyncService;
use Yii;
use yii\web\UploadedFile;

/**
 * The offline field app (Phase 7B), for inspectors and mine heads (`field.capture`).
 *
 *   GET  /v1/field/bootstrap   what the app keeps offline: the account, the mines in scope (the
 *                              inspector's assigned ones first), open assigned inspections, the
 *                              checklist and its obligations, the categories and their violation
 *                              types, the product settings
 *   POST /v1/field/sync        {device_now, items: [visit | capture, ...]} -> one result per item
 *                              (created | replayed | failed with {code, params, fields}); 200 even
 *                              when some items fail, so the app can mark each one
 *   POST /v1/field/photos      multipart {client_id, capture_client_id, file}: 201 created, 200 replayed
 *
 * Every item carries the phone's own id, so a retried sync never acts twice (FieldSyncService).
 */
class FieldController extends ApiController
{
    protected function verbs(): array
    {
        return ['bootstrap' => ['GET'], 'sync' => ['POST'], 'photo' => ['POST']];
    }

    public function actionBootstrap(): array
    {
        $this->requirePermission('field.capture');
        return FieldSyncService::bootstrap($this->currentUser());
    }

    public function actionSync(): array
    {
        $this->requirePermission('field.capture');
        return FieldSyncService::sync($this->currentUser(), $this->body());
    }

    public function actionPhoto(): array
    {
        $this->requirePermission('field.capture');
        [$status, $result] = FieldSyncService::photo($this->currentUser(), Yii::$app->request->post(), UploadedFile::getInstanceByName('file'));
        Yii::$app->response->statusCode = $status === 'created' ? 201 : 200;
        return ['client_id' => strtolower((string) Yii::$app->request->post('client_id')), 'status' => $status, 'result' => $result];
    }
}
