<?php

declare(strict_types=1);

namespace app\tests\api;

use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;
use app\tests\unit\SeedTest;
use Codeception\Scenario;

/**
 * The demo script's numbers after `yii seed demo` (HANDOFF "Scores"): the five demo mines score
 * 100 / 80 / 70 / 60 / 45, the fleet splits 6 high / 21 medium / 47 low, the national average is
 * 83.2 - and no breach is inside the 12 s window, since the seeded readings are historical.
 */
class DemoScoreCest
{
    private const DEMO_MINES = [
        'JH-DHN-01' => [100.0, 'low'],    // Moonidih, BCCL
        'MP-SGR-02' => [80.0, 'low'],     // Jayant, NCL
        'CG-KRB-03' => [70.0, 'medium'],  // Gevra, SECL
        'WB-RNG-04' => [60.0, 'medium'],  // Sonepur Bazari, ECL
        'OD-TLC-05' => [45.0, 'high'],    // Bhubaneswari, MCL
    ];

    public function _before(ApiTester $I, Scenario $scenario): void
    {
        if (SeedTest::seededPreset() !== 'demo') {
            $scenario->skip('needs the demo preset (run_tests.bat seeds it by default)');
        }
        $I->amBearerOf(Auth::GOVERNMENT);
    }

    public function demoMinesScoreAsInTheScript(ApiTester $I): void
    {
        foreach (self::DEMO_MINES as $code => [$score, $band]) {
            $id = (int) \app\models\Mine::find()->where(['code' => $code])->select('id')->scalar();
            $I->sendGet("/v1/mines/$id");
            $I->seeResponseCodeIs(200);
            $I->assertSame($score, (float) $I->grabDataFromResponseByJsonPath('$.compliance.score')[0], $code);
            $I->assertSame($band, $I->grabDataFromResponseByJsonPath('$.compliance.risk_level')[0], $code);
            $I->assertSame(0, $I->grabDataFromResponseByJsonPath('$.compliance.breach_count')[0], $code);
        }
    }

    public function nationalStatsAreSixTwentyOneFortySevenAverage832(ApiTester $I): void
    {
        $I->sendGet('/v1/dashboard');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['stats' => [
            'mine_count' => 74,
            'average_score' => 83.2,
            'high_risk_count' => 6,
            'medium_risk_count' => 21,
            'low_risk_count' => 47,
            'total_breaches' => 0,
        ]]);
    }

    public function nationalBoardShowsTheFiveWorstWithBhubaneswariAmongThem(ApiTester $I): void
    {
        $I->sendGet('/v1/dashboard');
        $I->seeResponseContainsJson(['is_truncated' => true, 'showing' => 5]);
        $scores = $I->grabDataFromResponseByJsonPath('$.mines[*].compliance.score');
        $sorted = $scores;
        sort($sorted);
        $I->assertSame($sorted, $scores, 'worst first');
        $I->assertContains('OD-TLC-05', $I->grabDataFromResponseByJsonPath('$.mines[*].code'));
    }
}
