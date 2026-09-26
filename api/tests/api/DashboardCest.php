<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Mine;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** GET /v1/dashboard shapes per role, the state filter, the priority queue preview. */
class DashboardCest
{
    public function governmentGetsNationalViewWithQueue(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/dashboard');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['role' => 'government', 'scope' => 'all_mines', 'scope_label_code' => 'NATIONAL']);
        $I->assertCount(3, $I->grabDataFromResponseByJsonPath('$.inspection_queue[*]'));
        $I->assertContains('Odisha', $I->grabDataFromResponseByJsonPath('$.states')[0]);
        $I->seeResponseMatchesJsonType(['compliance' => ['score' => 'float|integer', 'risk_level' => 'string', 'is_demo_value' => 'boolean']], '$.mines[0]');
    }

    public function stateFilterNarrowsStatsAndShowsEveryMine(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/dashboard', ['state' => 'Odisha']);
        $count = (int) Mine::find()->where(['state' => 'Odisha'])->count();
        $I->seeResponseContainsJson(['state' => 'Odisha', 'is_truncated' => false, 'showing' => $count, 'stats' => ['mine_count' => $count]]);
    }

    public function corporateSeesItsSubsidiaryOnly(ApiTester $I): void
    {
        $user = Auth::user(Auth::CORPORATE_SECL);
        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/dashboard');
        $I->seeResponseContainsJson(['scope' => 'subsidiary', 'stats' => ['mine_count' => (int) Mine::find()->where(['subsidiary_id' => $user->subsidiary_id])->count()]]);
        foreach ($I->grabDataFromResponseByJsonPath('$.mines[*].operator') as $operator) {
            $I->assertSame('SECL', $operator);
        }
    }

    public function mineHeadSeesOwnMineAndNoQueue(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/dashboard');
        $I->seeResponseContainsJson(['scope' => 'mine', 'showing' => 1, 'inspection_queue' => [], 'states' => []]);
        $I->seeResponseContainsJson(['mines' => [['code' => 'OD-TLC-05', 'name' => 'Bhubaneswari Coal Mine']]]);
    }

    public function priorityQueueIsForMultiMineRoles(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/inspections/priority', ['limit' => 5]);
        $I->seeResponseCodeIs(200);
        $ranks = $I->grabDataFromResponseByJsonPath('$.candidates[*].rank');
        $I->assertSame([1, 2, 3, 4, 5], $ranks);
        $urgency = $I->grabDataFromResponseByJsonPath('$.candidates[*].urgency');
        $sorted = $urgency;
        rsort($sorted);
        $I->assertSame($sorted, $urgency);
        $I->seeResponseMatchesJsonType(['code' => 'string', 'params' => 'array'], '$.candidates[0].reasons[0]');

        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/inspections/priority');
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function geojsonCoversTheMinesInScope(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/mines/geojson');
        $I->seeResponseContainsJson(['type' => 'FeatureCollection']);
        $I->assertSame(['OD-TLC-05'], $I->grabDataFromResponseByJsonPath('$.features[*].properties.code'));
    }

    public function meCarriesPermissions(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/users/me');
        $permissions = $I->grabDataFromResponseByJsonPath('$.permissions')[0];
        $I->assertContains('correctiveAction.create', $permissions);
        $I->assertNotContains('directive.create', $permissions);
        $I->seeResponseContainsJson(['mine_name' => 'Bhubaneswari Coal Mine', 'subsidiary_code' => 'MCL']);
    }
}
