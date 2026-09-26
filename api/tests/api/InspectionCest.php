<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\models\RecordEditLog;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** Inspection records: schedule -> visit -> observe -> promote -> close (locked) -> edit needs a reason. */
class InspectionCest
{
    public function fullInspectionWorkflow(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::INSPECTOR);
        $I->sendPost('/v1/inspections', ['mine_id' => 3, 'inspection_type' => 'spot', 'scheduled_for' => gmdate('Y-m-d')]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['status' => 'scheduled', 'is_locked' => false, 'mine_name' => 'Gevra Coal Mine']);
        $id = $I->grabDataFromResponseByJsonPath('$.id')[0];

        $I->sendPost("/v1/inspections/$id/observations", ['category' => 'ppe', 'severity' => 'high']);
        $I->seeApiError(422, 'INSPECTION_NOT_IN_PROGRESS');

        $I->sendPost("/v1/inspections/$id/close");
        $I->seeApiError(422, 'INVALID_TRANSITION');
        $I->seeResponseContainsJson(['error' => ['params' => ['from' => 'scheduled', 'to' => 'closed']]]);

        $I->sendPost("/v1/inspections/$id/visit");
        $I->seeResponseContainsJson(['status' => 'visited']);

        $I->sendPost("/v1/inspections/$id/observations", ['category' => 'roof_strata', 'severity' => 'high']);
        $I->seeResponseCodeIs(201);
        $observation = $I->grabDataFromResponseByJsonPath('$.id')[0];
        $I->sendPost("/v1/inspections/$id/observations", ['category' => 'astrology', 'severity' => 'high']);
        $I->seeResponseContainsJson(['error' => ['fields' => ['category' => ['INVALID_VALUE']]]]);

        $I->sendPost("/v1/observations/$observation/promote", ['violation_type' => 'inadequate_roof_support']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['observation' => ['status' => 'promoted'], 'violation' => ['source' => 'inspection', 'category' => 'roof_strata', 'inspection_id' => $id]]);
        $violationId = $I->grabDataFromResponseByJsonPath('$.violation.id')[0];
        $I->assertNotNull(Alert::findOne(['code' => 'VIOLATION_RECORDED', 'entity_id' => $violationId]));

        $I->sendPost("/v1/inspections/$id/close");
        $I->seeResponseContainsJson(['status' => 'closed', 'is_locked' => true, 'findings_count' => 1]);

        $I->sendPatch("/v1/inspections/$id", ['inspection_type' => 'regular']);
        $I->seeApiError(422, 'RECORD_LOCKED');
        $I->seeResponseContainsJson(['error' => ['fields' => ['reason' => ['REQUIRED']]]]);

        $I->sendPatch("/v1/inspections/$id", ['inspection_type' => 'regular', 'reason' => 'Recorded as spot by mistake.']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['inspection_type' => 'regular', 'edits' => [['field' => 'inspection_type', 'old_value' => 'spot',
            'new_value' => 'regular', 'reason' => 'Recorded as spot by mistake.']]]);
        $I->assertSame(1, (int) RecordEditLog::find()->where(['entity' => 'inspection', 'entity_id' => $id])->count());

        $I->sendPatch("/v1/inspections/$id", ['status' => 'scheduled', 'reason' => 'x']);
        $I->seeResponseContainsJson(['error' => ['fields' => ['status' => ['READ_ONLY']]]]);

        $I->sendGet("/v1/inspections/$id");
        $I->assertSame(['closed', 'visited'], $I->grabDataFromResponseByJsonPath('$.history[*].to_status'));
    }

    public function mineHeadReadsButCannotManage(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/inspections');
        $I->seeResponseCodeIs(200);
        foreach ($I->grabDataFromResponseByJsonPath('$[*].mine_id') as $mineId) {
            $I->assertSame(5, $mineId);
        }
        $I->sendPost('/v1/inspections', ['mine_id' => 5, 'inspection_type' => 'spot', 'scheduled_for' => gmdate('Y-m-d')]);
        $I->seeApiError(403, 'FORBIDDEN');
    }
}
