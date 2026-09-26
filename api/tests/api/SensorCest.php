<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\models\Mine;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;
use Yii;

/**
 * Sensor reads (scoped) and POST /v1/sensor-readings/ingest (API key), with the legal limits of
 * data/schema/rules.yaml: ch4 > 1.25 %, dust as an 8-hour mean > 2.0 mg/m3, no limit for co.
 */
class SensorCest
{
    private const KEY = 'csk_test_key_for_codeception_only';

    public function _before(ApiTester $I): void
    {
        Yii::$app->db->createCommand()->insert('{{%api_key}}', ['name' => 'codeception', 'key_hash' => hash('sha256', self::KEY), 'scope' => 'sensor_ingest'])->execute();
    }

    public function fleetIsForMultiMineRoles(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/sensors', ['state' => 'Jharkhand']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['mine_count' => (int) Mine::find()->where(['state' => 'Jharkhand'])->count()]);
        $I->seeResponseContainsJson(['thresholds' => ['ch4' => ['limit' => 1.25, 'obligation' => 'SAF-11'], 'co' => ['limit' => null]]]);

        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        $I->sendGet('/v1/sensors');
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function trendAndReadingsAreScoped(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        $I->sendGet('/v1/sensors/1/trend', ['points' => 5]);
        $I->seeResponseCodeIs(200);
        $types = $I->grabDataFromResponseByJsonPath('$.series[*].sensor_type');
        $I->assertContains('ch4', $types, 'underground mine has gas sensors');
        $I->assertCount(5, $I->grabDataFromResponseByJsonPath('$.series[0].points[*]'));

        $I->sendGet('/v1/sensors/1', ['per_page' => 3, 'filter' => ['sensor_type' => 'dust']]);
        $I->seeResponseCodeIs(200);
        $I->assertSame(['dust', 'dust', 'dust'], $I->grabDataFromResponseByJsonPath('$[*].sensor_type'));

        $I->sendGet('/v1/sensors/5/trend');
        $I->seeApiError(404, 'NOT_FOUND');
        $I->sendGet('/v1/sensors/breaches', ['mine_id' => 5]);
        $I->seeApiError(404, 'NOT_FOUND');
    }

    public function ingestNeedsAValidKey(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/v1/sensor-readings/ingest', ['readings' => [['mine_id' => 1, 'sensor_type' => 'ch4', 'value' => 0.3]]]);
        $I->seeApiError(401, 'INVALID_API_KEY');
        $I->haveHttpHeader('X-Api-Key', 'csk_wrong');
        $I->sendPost('/v1/sensor-readings/ingest', ['readings' => [['mine_id' => 1, 'sensor_type' => 'ch4', 'value' => 0.3]]]);
        $I->seeApiError(401, 'INVALID_API_KEY');
    }

    public function breachStoresAlertAndDropsTheScoreInsideTheWindow(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Api-Key', self::KEY);
        $I->sendPost('/v1/sensor-readings/ingest', ['readings' => [
            ['mine_code' => 'JH-DHN-01', 'sensor_type' => 'ch4', 'value' => 1.25],   // at the limit: compliant
            ['mine_code' => 'JH-DHN-01', 'sensor_type' => 'ch4', 'value' => 1.9],    // breach (SAF-11)
            ['mine_code' => 'JH-DHN-01', 'sensor_type' => 'co', 'value' => 400],     // no verified limit
        ]]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['stored' => 3, 'mines' => [['mine_id' => 1, 'score_before' => 100, 'score_after' => 97, 'breaches' => 1]]]);

        $rows = Yii::$app->db->createCommand("SELECT sensor_type, value, breached FROM sensor_reading WHERE mine_id = 1 ORDER BY id DESC LIMIT 3")->queryAll();
        $I->assertSame([null, true, false], array_map(fn($r) => $r['breached'], $rows));

        $alert = Alert::find()->where(['code' => Alert::CODE_SENSOR, 'mine_id' => 1])->orderBy(['id' => SORT_DESC])->one();
        $I->assertSame('ch4', $alert->params['sensor_type']);
        $I->assertSame('SAF-11', $alert->params['obligation']);
        $I->assertSame('high', $alert->severity, '1.9 is > 30 % over 1.25');

        $history = Yii::$app->db->createCommand('SELECT score FROM compliance_score WHERE mine_id = 1 ORDER BY id DESC LIMIT 1')->queryScalar();
        $I->assertSame(97.0, (float) $history, 'score movement recorded for the trend line');
    }

    public function dustIsJudgedAsAnEightHourMean(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Api-Key', self::KEY);
        $at = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        // One high reading after a clean hour of history does not lift the 8-hour mean over 2.0.
        $I->sendPost('/v1/sensor-readings/ingest', ['readings' => [
            ['mine_id' => 2, 'sensor_type' => 'dust', 'value' => 1.0, 'recorded_at' => $at],
            ['mine_id' => 2, 'sensor_type' => 'dust', 'value' => 1.0, 'recorded_at' => $at],
            ['mine_id' => 2, 'sensor_type' => 'dust', 'value' => 3.5],
        ]]);
        $I->seeResponseCodeIs(201);
        $breached = Yii::$app->db->createCommand("SELECT breached FROM sensor_reading WHERE mine_id = 2 AND sensor_type = 'dust' ORDER BY id DESC LIMIT 1")->queryScalar();
        $I->assertFalse($breached, 'mean (1.0 + 1.0 + 3.5) / 3 = 1.83 <= 2.0');
    }

    public function ingestRejectsBadReadings(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Api-Key', self::KEY);
        $I->sendPost('/v1/sensor-readings/ingest', ['readings' => [
            ['mine_id' => 1, 'sensor_type' => 'gas', 'value' => 1],
            ['mine_id' => 99999, 'sensor_type' => 'ch4', 'value' => 1],
            ['mine_id' => 1, 'sensor_type' => 'ch4', 'value' => 'high'],
        ]]);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['fields' => [
            'readings[0].sensor_type' => ['INVALID_VALUE'],
            'readings[1].mine_id' => ['NOT_FOUND'],
            'readings[2].value' => ['INVALID_VALUE'],
        ]]]);
    }
}
