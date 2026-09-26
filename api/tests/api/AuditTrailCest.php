<?php

declare(strict_types=1);

namespace app\tests\api;

use app\components\AuditChain;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** GET /v1/audit: scoped per role, fed by every change, chain stays intact. */
class AuditTrailCest
{
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
        $I->sendGet('/v1/audit', ['entity' => 'alert']);
        $I->assertEqualsCanonicalizing([1, 5], array_values(array_unique($I->grabDataFromResponseByJsonPath('$[*].mine_id'))));
        $I->assertSame([], AuditChain::verify());
    }
}
