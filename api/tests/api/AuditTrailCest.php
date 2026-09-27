<?php

declare(strict_types=1);

namespace app\tests\api;

use app\components\AuditChain;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** GET /v1/audit: scoped per role, fed by every change, chain stays intact. */
class AuditTrailCest
{
    public function aMinesTrailShowsItsHistoryRightAfterSeeding(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/audit', ['per_page' => 200, 'source' => 'seed_history']);
        $I->seeResponseCodeIs(200);
        $entities = array_unique($I->grabDataFromResponseByJsonPath('$[*].entity'));
        foreach (['violation', 'corrective_action', 'incident', 'inspection', 'alert'] as $entity) {
            $I->assertContains($entity, $entities, "$entity history for OD-TLC-05");
        }
        foreach ($I->grabDataFromResponseByJsonPath('$[*].mine_id') as $mineId) {
            $I->assertSame(5, $mineId);
        }
        // S1: the dangerous occurrence appears with its real report time.
        $I->seeResponseContainsJson([['entity' => 'incident', 'action' => 'reported', 'source' => 'seed_history',
            'new_values' => ['severity' => 'dangerous_occurrence', 'obligation_code' => 'RPT-05']]]);
    }

    public function changesAppearScopedToTheirMine(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost('/v1/alerts/directives', ['mine_id' => 5, 'message' => 'Check bench 3']);
        $I->sendPost('/v1/alerts/directives', ['mine_id' => 1]);

        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/audit');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([['entity' => 'alert', 'action' => 'insert', 'mine_id' => 5, 'actor' => 'DGMS Compliance Authority']]);
        foreach ($I->grabDataFromResponseByJsonPath('$[*].mine_id') as $mineId) {
            $I->assertSame(5, $mineId);
        }
        $I->sendGet('/v1/audit', ['mine_id' => 1]);
        $I->seeApiError(404, 'NOT_FOUND');

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/audit', ['entity' => 'alert', 'source' => 'app']);
        $I->assertEqualsCanonicalizing([1, 5], array_values(array_unique($I->grabDataFromResponseByJsonPath('$[*].mine_id'))));
        $I->assertSame([], AuditChain::verify());
    }
}
