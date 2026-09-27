<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;
use Codeception\Scenario;
use Yii;

/**
 * POST /v1/vision/analyze. The failure path runs everywhere (ai-service pointed at a closed port);
 * the detection path runs when ai-service is up (ai-service\run_ai_service.bat), else it is skipped.
 */
class VisionCest
{
    private const SAMPLE = __DIR__ . '/../../../backend/data/samples/images/ppe_sample.jpg';

    public function aiServiceDownDegradesTo503AndAnAlert(ApiTester $I): void
    {
        Yii::$app->params['ai.baseUrl'] = 'http://127.0.0.1:9';
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost('/v1/vision/analyze', ['mine_id' => 5], ['file' => codecept_data_dir('pixel.png')]);
        $I->seeApiError(503, 'AI_SERVICE_UNAVAILABLE');
        $I->assertNotNull(Alert::findOne(['code' => 'AI_SERVICE_UNAVAILABLE', 'mine_id' => 5]));
    }

    public function otherMineIs404(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost('/v1/vision/analyze', ['mine_id' => 1], ['file' => codecept_data_dir('pixel.png')]);
        $I->seeApiError(404, 'NOT_FOUND');
    }

    public function detectionCreatesViolationsAndDropsTheScore(ApiTester $I, Scenario $scenario): void
    {
        $up = @file_get_contents(rtrim(Yii::$app->params['ai.baseUrl'], '/') . '/health', false, stream_context_create(['http' => ['timeout' => 2]]));
        if ($up === false) {
            $scenario->skip('ai-service is not running');
        }
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        $I->sendPost('/v1/vision/analyze', ['mine_id' => 1], ['file' => ['name' => 'ppe_sample.jpg', 'type' => 'image/jpeg',
            'error' => UPLOAD_ERR_OK, 'size' => filesize(self::SAMPLE), 'tmp_name' => self::SAMPLE]]);
        $I->seeResponseCodeIs(201);
        if (json_decode($up, true)['backend'] === 'fixture') {
            // The fixture's sidecar holds two inferred violations: 100 -> 90.
            $I->seeResponseContainsJson(['backend' => 'fixture', 'score_before' => ['score' => 100], 'score_after' => ['score' => 90],
                'score_delta' => -10, 'alerts_raised' => 2, 'resolution' => ['code' => 'VIOLATIONS_DETECTED']]);
        } else {
            // A real model: the numbers depend on the weights, the contract does not.
            $before = $I->grabDataFromResponseByJsonPath('$.score_before.score')[0];
            $after = $I->grabDataFromResponseByJsonPath('$.score_after.score')[0];
            $violations = count($I->grabDataFromResponseByJsonPath('$.violations[*]'));
            $I->assertEquals($before - 5 * $violations, $after);
            $I->assertSame($violations, $I->grabDataFromResponseByJsonPath('$.alerts_raised')[0]);
            $I->assertContains($I->grabDataFromResponseByJsonPath('$.resolution.code')[0], ['VIOLATIONS_DETECTED', 'CLEAN_FRAME', 'NO_WORKERS_SEEN']);
        }
        $url = $I->grabDataFromResponseByJsonPath('$.annotated_url')[0];
        $I->assertStringStartsWith('/v1/files/', $url);

        $I->deleteHeader('Authorization');
        $I->sendGet($url);
        $I->seeResponseCodeIs(200);
        $I->sendGet(preg_replace('/signature=\w+/', 'signature=forged', $url));
        $I->seeApiError(404, 'NOT_FOUND');
    }
}
