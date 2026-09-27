<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\models\Grievance;
use app\models\User;
use app\services\GrievanceService;
use Codeception\Test\Unit;
use Yii;

/**
 * The grievance SLA-breach cluster detector scored against the data generator's ground truth,
 * data/out/<preset>/scenario_expectations.json (detector "grievance SLA"):
 *
 *   S6_GRIEVANCE_SLA_CLUSTER        positive - Kulda (OD-SUN-07): a cluster of breaches, flagged
 *   N2_GRIEVANCE_BURST_WITHIN_SLA   decoy - Jhanjra (WB-BAR-08): a burst of grievances, all handled
 *                                   within SLA - not flagged, none breached
 * Scored on the seeded state, before any SLA check has run in the test.
 */
class GrievanceClusterTest extends Unit
{
    public function testClusterDetectorAgainstScenarioExpectations(): void
    {
        $scenarios = array_filter($this->expectations(), fn($s) => $s['detector'] === 'grievance SLA');
        $this->assertEqualsCanonicalizing(['S6_GRIEVANCE_SLA_CLUSTER', 'N2_GRIEVANCE_BURST_WITHIN_SLA'], array_column($scenarios, 'scenario_code'));
        $government = User::find()->where(['role' => User::ROLE_GOVERNMENT])->orderBy('id')->one();
        $allMines = array_map('intval', Yii::$app->db->createCommand('SELECT id FROM mine')->queryColumn());
        $clusters = GrievanceService::clusters($government, $allMines);

        $scores = ['tp' => 0, 'fn' => 0, 'fp' => 0, 'tn' => 0];
        foreach ($scenarios as $s) {
            $mineId = (int) $s['mine_id'];
            $ids = array_map('intval', $s['entities']['grievance']);
            if ($s['expected_flag']) {
                $hit = isset($clusters[$mineId]);
                $scores[$hit ? 'tp' : 'fn']++;
                $this->assertTrue($hit, "{$s['scenario_code']}: {$s['mine_code']} must be flagged");
                $this->assertGreaterThanOrEqual($s['signal']['min_breaches'], $clusters[$mineId]['breaches']);
                $this->assertEmpty(array_diff($ids, $clusters[$mineId]['grievance_ids']), 'every scenario grievance is in the flagged window');
                $this->assertGreaterThanOrEqual($s['date_from'], $clusters[$mineId]['from']);
                $this->assertLessThanOrEqual($s['date_to'], $clusters[$mineId]['to']);
            } else {
                $flagged = isset($clusters[$mineId]);
                $scores[$flagged ? 'fp' : 'tn']++;
                $this->assertFalse($flagged, "{$s['scenario_code']}: {$s['mine_code']} must not be flagged");
                $breached = Grievance::find()->where(['id' => $ids])->andWhere(['>=', 'escalation_level', 1])->count();
                $this->assertSame($s['signal']['max_breaches'], (int) $breached, 'handled within SLA');
            }
        }
        codecept_debug($scores);
        $this->assertSame(['tp' => 1, 'fn' => 0, 'fp' => 0, 'tn' => 1], $scores);
        $this->assertSame([7], array_keys($clusters), 'no other mine is flagged on the seed');
    }

    private function expectations(): array
    {
        $preset = getenv('TEST_PRESET') ?: 'demo';
        $path = Yii::getAlias('@app') . '/' . Yii::$app->params['dataOutDir'] . "/$preset/scenario_expectations.json";
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
