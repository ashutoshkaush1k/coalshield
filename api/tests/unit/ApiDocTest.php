<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\commands\DocsController;
use Codeception\Test\Unit;

/** docs/API.md lists exactly what the API serves (Phase 8): regenerate with `php yii docs/api`. */
class ApiDocTest extends Unit
{
    public function testApiDocIsCurrent(): void
    {
        $path = \Yii::getAlias('@app') . DocsController::DOC;
        $this->assertFileExists($path);
        $this->assertSame(DocsController::renderDoc(), str_replace("\r\n", "\n", (string) file_get_contents($path)),
            'docs/API.md is out of date: run php yii docs/api');
    }

    public function testEveryTokenEndpointChecksSomething(): void
    {
        // A signed-in-only endpoint is fine only where every account may use it; list them so a new
        // endpoint without a permission check fails here until it is added on purpose.
        $open = array_map(fn($r) => $r['route'], array_filter(DocsController::routes(),
            fn($r) => $r['auth'] === 'token' && $r['permissions'] === 'any signed-in account'));
        $this->assertSame(['default/status'], array_values($open));
    }
}
