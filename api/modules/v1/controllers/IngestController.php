<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiException;
use app\components\Format;
use app\services\BaselineService;
use app\services\SensorService;
use Yii;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\rest\Controller;
use yii\web\Response;

/**
 * POST /v1/sensor-readings/ingest - machine endpoint for the sensor simulator (brief section 2).
 * Authenticated by an API key (X-Api-Key), not a user token; keys are stored hashed in api_key
 * and issued with `yii api-key/issue`.
 *
 * Body: {"readings": [{"mine_id" | "mine_code", "sensor_type", "value", "recorded_at"?}, ...]}
 */
class IngestController extends Controller
{
    public function behaviors(): array
    {
        return [
            'contentNegotiator' => ['class' => ContentNegotiator::class, 'formats' => ['application/json' => Response::FORMAT_JSON]],
            'verbFilter' => ['class' => VerbFilter::class, 'actions' => ['index' => ['POST'], 'baseline' => ['GET']]],
        ];
    }

    public function actionIndex(): array
    {
        $name = $this->authenticate();
        $body = Yii::$app->request->getBodyParams();
        $readings = is_array($body['readings'] ?? null) ? array_values($body['readings']) : null;
        if ($readings === null) {
            throw ApiException::fields(['readings' => ['REQUIRED']]);
        }
        Yii::$app->response->statusCode = 201;
        return SensorService::ingest($readings, 'api_key:' . $name);
    }

    /** GET /v1/sensor-readings/baseline - the simulator's pre-flight (same report as /admin/baseline-check). */
    public function actionBaseline(): array
    {
        $this->authenticate();
        return BaselineService::check();
    }

    private function authenticate(): string
    {
        $key = (string) Yii::$app->request->headers->get('X-Api-Key', '');
        $row = $key === '' ? false : Yii::$app->db->createCommand(
            "SELECT id, name FROM api_key WHERE key_hash = :h AND scope = 'sensor_ingest' AND revoked_at IS NULL",
            [':h' => hash('sha256', $key)],
        )->queryOne();
        if ($row === false) {
            throw new ApiException(401, 'INVALID_API_KEY');
        }
        Yii::$app->db->createCommand()->update('{{%api_key}}', ['last_used_at' => Format::sql(Format::now())], ['id' => $row['id']])->execute();
        return $row['name'];
    }
}
