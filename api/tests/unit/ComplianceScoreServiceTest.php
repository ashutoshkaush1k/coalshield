<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\components\Format;
use app\services\ComplianceScoreService;
use Codeception\Test\Unit;
use Yii;

/** The formula must give the prototype's numbers (its tests/test_scoring.py, removed with backend/ in Phase 8). */
class ComplianceScoreServiceTest extends Unit
{
    public function testFormulaAndBands(): void
    {
        $this->assertSame(100.0, ComplianceScoreService::compute(0, 0)->score);
        $this->assertSame(45.0, ComplianceScoreService::compute(11, 0)->score);
        $result = ComplianceScoreService::compute(2, 3);
        $this->assertSame(81.0, $result->score);           // 100 - 10 - 9
        $this->assertSame('low', $result->riskLevel);
        $this->assertSame(10.0, $result->violationPenalty);
        $this->assertSame(9.0, $result->environmentalPenalty);
    }

    public function testBandBoundariesAreInclusiveAtTheBottom(): void
    {
        $this->assertSame('low', ComplianceScoreService::riskLevel(80.0));
        $this->assertSame('medium', ComplianceScoreService::riskLevel(79.9));
        $this->assertSame('medium', ComplianceScoreService::riskLevel(50.0));
        $this->assertSame('high', ComplianceScoreService::riskLevel(49.9));
    }

    public function testScoreIsClampedButRawKept(): void
    {
        $result = ComplianceScoreService::compute(30, 0);
        $this->assertSame(0.0, $result->score);
        $this->assertSame(-50.0, $result->rawScore);
        $this->assertSame('high', $result->riskLevel);
    }

    public function testWeightsComeFromParams(): void
    {
        $params = Yii::$app->params;
        Yii::$app->params['score.weightPpe'] = 10.0;
        try {
            $this->assertSame(80.0, ComplianceScoreService::compute(2, 0)->score);
        } finally {
            Yii::$app->params = $params;
        }
    }

    public function testBreachesCountOnlyInsideTheWindow(): void
    {
        $db = Yii::$app->db;
        $now = Format::now();
        foreach ([2, 30] as $secondsAgo) {   // one inside the 12 s window, one outside
            $db->createCommand('INSERT INTO sensor_reading (mine_id, sensor_type, value, unit, recorded_at, breached, resolved)
                VALUES (1, :t, 2.0, :u, :at, true, false)', [':t' => 'ch4', ':u' => '%', ':at' => Format::sql($now->modify("-$secondsAgo seconds"))])->execute();
        }
        $result = ComplianceScoreService::scoreMine(1, $now);
        $this->assertSame(1, $result->breachCount);
        $this->assertSame(97.0, $result->score);
        // Twenty seconds later both have aged out: the mine recovers without anyone acting.
        $this->assertSame(0, ComplianceScoreService::scoreMine(1, $now->modify('+20 seconds'))->breachCount);
    }

    public function testFleetStats(): void
    {
        $results = [ComplianceScoreService::compute(0, 0), ComplianceScoreService::compute(4, 0), ComplianceScoreService::compute(11, 0)];
        $stats = ComplianceScoreService::fleetStats($results);
        $this->assertSame(75.0, $stats['average_score']);   // (100 + 80 + 45) / 3
        $this->assertSame([1, 0, 2], [$stats['high_risk_count'], $stats['medium_risk_count'], $stats['low_risk_count']]);
    }
}
