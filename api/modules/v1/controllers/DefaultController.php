<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\services\SystemStatus;
use Yii;

/** CORS pre-flight and a public health check. */
class DefaultController extends ApiController
{
    protected array $publicActions = ['options', 'health'];

    protected function verbs(): array
    {
        return ['health' => ['GET'], 'status' => ['GET'], 'options' => ['OPTIONS']];
    }

    /** Pre-flight: the Cors filter has already written the headers. */
    public function actionOptions(): void
    {
        Yii::$app->response->statusCode = 204;
    }

    /** GET /v1/system/status (signed in): which engine the automated detection uses now (Phase 8). */
    public function actionStatus(): array
    {
        return SystemStatus::get();
    }

    public function actionHealth(): array
    {
        $database = 'ok';
        try {
            Yii::$app->db->createCommand('SELECT 1')->queryScalar();
        } catch (\Throwable) {
            $database = 'unavailable';
        }
        return ['status' => $database === 'ok' ? 'ok' : 'degraded', 'database' => $database, 'time' => gmdate('Y-m-d\TH:i:s\Z')];
    }
}
