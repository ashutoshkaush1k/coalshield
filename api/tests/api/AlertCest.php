<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** Alerts ({code, params}), directives and the proof-backed resolution loop. */
class AlertCest
{
    public function alertsCarryCodesNotText(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/alerts', ['per_page' => 5]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseMatchesJsonType(['code' => 'string:regex(~^[A-Z_]+$~)', 'params' => 'array', 'severity' => 'string', 'status' => 'string'], '$[0]');
        $I->dontSeeResponseJsonMatchesJsonPath('$[0].message');
        foreach ($I->grabDataFromResponseByJsonPath('$[*].mine_id') as $mineId) {
            $I->assertSame(5, $mineId);
        }
        $I->sendGet('/v1/alerts', ['mine_id' => 1]);
        $I->seeApiError(404, 'NOT_FOUND');
    }

    public function sinceReturnsOnlyNewerAlerts(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/alerts', ['since' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseEquals('[]');
    }

    public function directiveLoopRaiseResolveReopen(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost('/v1/alerts/directives', ['mine_id' => 5]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['code' => 'INSPECTION_DIRECTIVE', 'is_directive' => true, 'status' => 'open',
            'severity' => 'high', 'params' => ['score' => 45, 'risk_level' => 'high', 'violation_count' => 11]]);
        $id = $I->grabDataFromResponseByJsonPath('$.id')[0];

        // Directives lead the list.
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/alerts');
        $I->assertSame($id, $I->grabDataFromResponseByJsonPath('$[0].id')[0]);

        $I->sendPost("/v1/alerts/$id/resolve", ['proof_text' => '']);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->sendPost("/v1/alerts/$id/resolve", ['proof_text' => 'Roof bolting completed on bench 3, photos attached.']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'resolved', 'history' => [['from_status' => 'open', 'to_status' => 'resolved',
            'context' => ['proof_text' => 'Roof bolting completed on bench 3, photos attached.']]]]);

        $I->sendPost("/v1/alerts/$id/reopen", ['reason' => 'x']);
        $I->seeApiError(403, 'FORBIDDEN');

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost("/v1/alerts/$id/reopen", ['reason' => 'Photos do not show bench 3.']);
        $I->seeResponseContainsJson(['status' => 'open']);
        $I->assertCount(2, $I->grabDataFromResponseByJsonPath('$.history[*]'), 'earlier proof kept');
        $I->sendPost("/v1/alerts/$id/reopen", ['reason' => 'again']);
        $I->seeApiError(422, 'INVALID_TRANSITION');
    }

    public function onlyGovernmentRaisesDirectives(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        foreach ([Auth::MINE_HEAD_BHUBANESWARI, Auth::CORPORATE_SECL] as $email) {
            $I->amBearerOf($email);
            $I->sendPost('/v1/alerts/directives', ['mine_id' => 5]);
            $I->seeApiError(403, 'FORBIDDEN');
        }
    }

    public function acknowledgeIsOneWay(ApiTester $I): void
    {
        $id = (int) Alert::find()->where(['mine_id' => 5, 'status' => 'open'])->select('id')->scalar();
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost("/v1/alerts/$id/ack");
        $I->seeResponseContainsJson(['status' => 'acknowledged', 'ack_by' => Auth::user(Auth::MINE_HEAD_BHUBANESWARI)->id]);
        $I->sendPost("/v1/alerts/$id/ack");
        $I->seeApiError(422, 'INVALID_TRANSITION');
        $I->seeResponseContainsJson(['error' => ['params' => ['from' => 'acknowledged', 'to' => 'acknowledged']]]);
    }

    public function otherMinesAlertsAre404(ApiTester $I): void
    {
        $id = (int) Alert::find()->where(['mine_id' => 1])->select('id')->scalar();
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost("/v1/alerts/$id/ack");
        $I->seeApiError(404, 'NOT_FOUND');
    }
}
