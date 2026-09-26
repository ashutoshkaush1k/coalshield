<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\ListingQuery;
use app\models\Mine;
use app\models\SensorReading;
use app\models\User;
use app\services\SensorService;
use Yii;

/**
 * Sensor reads. Paths kept from the prototype (/sensors...) so the charts change little;
 * ingest is POST /v1/sensor-readings/ingest (IngestController, API key).
 */
class SensorController extends ApiController
{
    protected function verbs(): array
    {
        return ['fleet' => ['GET'], 'breaches' => ['GET'], 'readings' => ['GET'], 'trend' => ['GET'], 'thresholds' => ['GET']];
    }

    /** GET /v1/sensors?state= - latest reading per sensor for every mine in scope. */
    public function actionFleet(): array
    {
        $this->requirePermission('sensor.viewFleet');
        $standing = SensorService::fleetStanding($this->visibleMines(Yii::$app->request->get('state')));
        return [
            'mine_count' => count($standing),
            'breaching_mines' => count(array_filter($standing, fn($m) => $m['breaching_now'] > 0)),
            'thresholds' => SensorService::thresholds(),
            'mines' => $standing,
        ];
    }

    /** GET /v1/sensors/breaches?state=&mine_id= - breach frequency in 6-hour buckets. */
    public function actionBreaches(): array
    {
        $this->requirePermission('sensor.view');
        $mine = $this->mineParam();
        $mines = $mine !== null ? [$mine] : $this->visibleMines(Yii::$app->request->get('state'));
        $ids = array_map(fn(Mine $m) => (int) $m->id, $mines);
        return [
            'bucket_hours' => (int) Yii::$app->params['sensor.breachBucketHours'],
            'mine_count' => count($ids),
            'buckets' => SensorService::breachBuckets($ids),
        ];
    }

    /** GET /v1/sensors/{mine_id} - readings, newest first (paged; filter[sensor_type], breached_only). */
    public function actionReadings(int $mine_id): array
    {
        $this->requirePermission('sensor.view');
        $mine = Mine::findScoped($mine_id);
        $query = SensorReading::find()->where(['sensor_reading.mine_id' => $mine->id]);
        if (Yii::$app->request->get('breached_only')) {
            $query->andWhere(['sensor_reading.breached' => true]);
        }
        return ListingQuery::apply($query, ['sensor_type', 'breached'], ['recorded_at', 'value'], '-recorded_at');
    }

    /** GET /v1/sensors/{mine_id}/trend?points=40 - one series per sensor type. */
    public function actionTrend(int $mine_id): array
    {
        $this->requirePermission('sensor.view');
        $mine = Mine::findScoped($mine_id);
        $points = (int) Yii::$app->request->get('points', 40);
        if ($points < 1 || $points > 500) {
            throw ApiException::fields(['points' => ['OUT_OF_RANGE']]);
        }
        return SensorService::trend((int) $mine->id, $points);
    }

    /** GET /v1/sensors/thresholds - the legal limits in use (rules.yaml). */
    public function actionThresholds(): array
    {
        $this->requirePermission('sensor.view');
        return SensorService::thresholds();
    }
}
