<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Violation;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** Corrective actions: create for an own violation, resolve with proof -> violation closed, score up. */
class CorrectiveActionCest
{
    private function openViolationWithoutAction(int $mineId): Violation
    {
        return Violation::find()->where(['mine_id' => $mineId, 'resolved' => false])
            ->andWhere(['not exists', (new \yii\db\Query())->from('corrective_action ca')->where('ca.violation_id = violation.id')])
            ->one() ?? Violation::find()->where(['mine_id' => $mineId, 'resolved' => false])->one();
    }

    public function mineHeadRecordsAndResolvesAnAction(ApiTester $I): void
    {
        $violation = $this->openViolationWithoutAction(5);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost('/v1/corrective-actions', [
            'violation_id' => $violation->id,
            'description' => 'Replace the damaged roof bolts and re-examine the bench.',
            'due_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400),
        ]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['status' => 'open', 'is_overdue' => false, 'mine_id' => 5]);
        $id = $I->grabDataFromResponseByJsonPath('$.id')[0];

        $I->sendGet('/v1/mines/5');
        $before = $I->grabDataFromResponseByJsonPath('$.compliance.score')[0];

        $I->sendPost("/v1/corrective-actions/$id/resolve", ['proof_text' => 'Bolts replaced; examined by the overman.']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'resolved', 'violation' => ['id' => $violation->id, 'resolved' => true]]);
        $I->seeResponseContainsJson(['history' => [['to_status' => 'resolved']]]);

        $I->sendGet('/v1/mines/5');
        $I->assertEquals($before + 5, $I->grabDataFromResponseByJsonPath('$.compliance.score')[0], 'one open violation fewer');

        $I->sendPost("/v1/corrective-actions/$id/resolve", ['proof_text' => 'again']);
        $I->seeApiError(422, 'INVALID_TRANSITION');
    }

    public function validationUsesCodes(ApiTester $I): void
    {
        $violation = $this->openViolationWithoutAction(5);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost('/v1/corrective-actions', ['violation_id' => $violation->id, 'due_at' => 'tomorrow']);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['fields' => ['description' => ['REQUIRED'], 'due_at' => ['INVALID_DATETIME']]]]);
    }

    public function otherMinesViolationIs404AndGovernmentCannotCreate(ApiTester $I): void
    {
        $other = Violation::find()->where(['mine_id' => 1])->one();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost('/v1/corrective-actions', ['violation_id' => $other->id, 'description' => 'x x x', 'due_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $I->seeApiError(404, 'NOT_FOUND');

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost('/v1/corrective-actions', ['violation_id' => $other->id, 'description' => 'x x x', 'due_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function overdueFilter(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/corrective-actions', ['overdue' => 1, 'per_page' => 50]);
        $I->seeResponseCodeIs(200);
        foreach ($I->grabDataFromResponseByJsonPath('$[*].is_overdue') as $overdue) {
            $I->assertTrue($overdue);
        }
    }
}
