<?php

declare(strict_types=1);

namespace app\tests\api;

use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/**
 * The per-screen view endpoints (docs/PERFORMANCE.md): each part equals the endpoint it comes
 * from, for the same user, and scoping and permissions carry over.
 */
class ViewCest
{
    public function overviewEqualsDashboardAndContractorSummary(ApiTester $I): void
    {
        foreach ([Auth::GOVERNMENT, Auth::CORPORATE_SECL] as $who) {
            foreach ([[], ['state' => 'Odisha']] as $query) {
                $I->amBearerOf($who);
                $I->sendGet('/v1/dashboard', $query);
                $dashboard = json_decode($I->grabResponse(), true);
                $I->sendGet('/v1/contractors/summary', $query);
                $summary = json_decode($I->grabResponse(), true);
                $I->sendGet('/v1/views/overview', $query);
                $I->seeResponseCodeIs(200);
                $view = json_decode($I->grabResponse(), true);
                $I->assertEquals($dashboard, $view['dashboard'], "$who dashboard");
                $I->assertEquals($summary, $view['contractor_summary'], "$who contractor summary");
            }
        }
    }

    public function overviewForAMineHeadHasNoContractorSummary(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/views/overview');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['contractor_summary' => null]);
        $I->assertSame('mine', $I->grabDataFromResponseByJsonPath('$.dashboard.scope')[0]);
    }

    public function mineViewEqualsTheEightEndpoints(ApiTester $I): void
    {
        $mineId = Auth::user(Auth::MINE_HEAD_BHUBANESWARI)->mine_id;
        $parts = [
            'mine' => ["/v1/mines/$mineId", []],
            'trend' => ["/v1/sensors/$mineId/trend", ['points' => 40]],
            'violations' => ['/v1/violations', ['mine_id' => $mineId, 'per_page' => 50]],
            'alerts' => ['/v1/alerts', ['mine_id' => $mineId, 'per_page' => 30]],
            'audit' => ['/v1/audit', ['mine_id' => $mineId, 'per_page' => 40]],
            'corrective_actions' => ['/v1/corrective-actions', ['mine_id' => $mineId, 'per_page' => 50]],
            'incidents' => ['/v1/incidents', ['mine_id' => $mineId, 'per_page' => 50]],
            'risk' => ["/v1/mines/$mineId/risk", []],   // Phase 7
        ];
        foreach ([Auth::MINE_HEAD_BHUBANESWARI, Auth::GOVERNMENT] as $who) {
            $I->amBearerOf($who);
            $I->sendGet("/v1/views/mine/$mineId");
            $I->seeResponseCodeIs(200);
            $I->dontSeeHttpHeader('X-Total-Count');
            $view = json_decode($I->grabResponse(), true);
            $I->assertSame(array_keys($parts), array_keys($view));
            foreach ($parts as $key => [$url, $query]) {
                $I->sendGet($url, $query);
                $I->assertEquals(json_decode($I->grabResponse(), true), $view[$key], "$who $key");
            }
        }
    }

    public function anotherMinesViewIs404(ApiTester $I): void
    {
        $other = Auth::user(Auth::MINE_HEAD_MOONIDIH)->mine_id;
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet("/v1/views/mine/$other");
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['error' => ['code' => 'NOT_FOUND']]);
        $I->sendGet('/v1/views/mine/999999');
        $I->seeResponseCodeIs(404);
    }

    public function viewsNeedAToken(ApiTester $I): void
    {
        $I->sendGet('/v1/views/overview');
        $I->seeResponseCodeIs(401);
    }
}
