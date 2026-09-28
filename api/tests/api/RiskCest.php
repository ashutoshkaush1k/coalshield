<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Grievance;
use app\services\AiEvaluation;
use app\services\AnomalyService;
use app\services\RiskModelService;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/**
 * Phase 7 on the API: the Governance Risk Index orders the inspection queue, the mine's risk part
 * (index, prediction, findings) and the findings list keep to the caller's scope, and the model
 * card says where the model comes from.
 */
class RiskCest
{
    public function _before(ApiTester $I): void
    {
        AnomalyService::run(AiEvaluation::asOf(), 'php');
        RiskModelService::refresh(null, 'php');
    }

    public function priorityQueueIsOrderedByTheIndex(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/views/priority');
        $I->seeResponseCodeIs(200);
        $queue = $I->grabDataFromResponseByJsonPath('$.queue.candidates')[0];
        $I->assertCount(74, $queue);
        $gri = array_map(fn($c) => $c['governance_risk']['gri'], $queue);
        $sorted = $gri;
        rsort($sorted);
        $I->assertSame($sorted, $gri, 'highest index first');
        $I->assertSame('PRIORITY_GRI', $queue[0]['reasons'][0]['code']);
        $I->assertSame($queue[0]['governance_risk']['gri'], $queue[0]['reasons'][0]['params']['gri']);
        // The compliance score is unchanged and still shown.
        $I->assertArrayHasKey('score', $queue[0]['compliance']);
        $patterns = $I->grabDataFromResponseByJsonPath('$.patterns')[0];
        $I->assertNotEmpty($patterns);
    }

    public function indexComponentsAddUp(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/mines/5/risk');
        $I->seeResponseCodeIs(200);
        $gri = $I->grabDataFromResponseByJsonPath('$.governance_risk')[0];
        $sum = 0.0;
        foreach ($gri['components'] as $c) {
            $I->assertEquals(min($c['cap'], $c['count'] * $c['points']), $c['value'], $c['key']);
            $sum += $c['value'];
        }
        $I->assertEquals($sum, $gri['raw']);
        $I->assertSame((int) min(100, round($sum * $gri['multiplier'])), $gri['gri']);
    }

    public function mineHeadSeesOnlyTheirMine(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/mines/5/risk');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['mine_id' => 5, 'prediction' => ['model_version' => RiskModelService::model()['version']]]);
        $I->sendGet('/v1/mines/1/risk');
        $I->seeApiError(404, 'NOT_FOUND');

        $I->sendGet('/v1/anomalies', ['status' => 'all', 'per_page' => 200]);
        $I->seeResponseCodeIs(200);
        $mines = array_unique(array_map(fn($f) => $f['mine_id'], json_decode($I->grabResponse(), true)));
        $I->assertContains($mines, [[], [5]]);

        $I->sendGet('/v1/views/priority');
        $I->seeResponseCodeIs(403);
    }

    public function mineHeadIndexLeavesOutSensitiveGrievances(ApiTester $I): void
    {
        $count = function (string $who) use ($I): int {
            $I->amBearerOf($who);
            $I->sendGet('/v1/mines/5/risk');
            return $this->component($I, 'grievances_past_sla')['count'];
        };
        [$gov, $head] = [$count(Auth::GOVERNMENT), $count(Auth::MINE_HEAD_BHUBANESWARI)];
        // A sensitive grievance past its deadline counts for the regulator, not in the mine head's view.
        $g = Grievance::find()->where(['mine_id' => 5, 'status' => ['resolved', 'closed'], 'against_mine_head' => false])
            ->andWhere(['<>', 'category', 'harassment'])->one();
        $I->assertNotNull($g);
        Grievance::updateAll(['category' => 'harassment', 'status' => 'received', 'sla_due_at' => '2026-01-01 00:00:00+00'], ['id' => $g->id]);
        $I->assertSame($gov + 1, $count(Auth::GOVERNMENT));
        $I->assertSame($head, $count(Auth::MINE_HEAD_BHUBANESWARI));
    }

    public function modelCardStatesTheTransfer(ApiTester $I): void
    {
        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/risk/model');
        $I->seeResponseCodeIs(200);
        $card = json_decode($I->grabResponse(), true);
        $I->assertStringContainsString('MSHA', $card['trained_on']);
        $I->assertStringContainsString('Indian', $card['transfer']);
        $I->assertGreaterThan($card['test']['baseline']['auc'], $card['test']['model']['auc']);
    }

    public function mineViewCarriesTheRiskPart(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/views/mine/5');
        $I->seeResponseCodeIs(200);
        $risk = $I->grabDataFromResponseByJsonPath('$.risk')[0];
        $I->assertSame(5, $risk['mine_id']);
        $I->assertArrayHasKey('governance_risk', $risk);
        $I->assertArrayHasKey('patterns', $risk);
    }

    private function component(ApiTester $I, string $key): array
    {
        foreach ($I->grabDataFromResponseByJsonPath('$.governance_risk.components')[0] as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        throw new \RuntimeException("component $key missing");
    }
}
