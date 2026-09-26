<?php

declare(strict_types=1);

namespace app\tests\api;

use app\tests\Support\ApiTester;

class HealthCest
{
    public function healthIsPublic(ApiTester $I): void
    {
        $I->sendGet('/v1/health');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'ok', 'database' => 'ok']);
    }

    public function unknownRouteUsesTheErrorFormat(ApiTester $I): void
    {
        $I->sendGet('/v1/does-not-exist');
        $I->seeApiError(404, 'NOT_FOUND');
    }
}
