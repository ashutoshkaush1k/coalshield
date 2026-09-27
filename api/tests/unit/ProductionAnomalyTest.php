<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\services\ProductionService;
use Codeception\Test\Unit;
use Yii;

/**
 * The PHP production anomaly detector scored against the data generator's ground truth,
 * data/out/<preset>/scenario_expectations.json (the preset run_tests.bat seeds, demo by default):
 *
 *   S2_PRODUCTION_SPIKE_BEFORE_INSPECTION  positive - must be flagged on its day
 *   N1_LEGIT_PRODUCTION_INCREASE           decoy - a revised target with an edit-log reason
 *   N3_NIGHT_SHIFT_MAINTENANCE             decoy - a night shift that always produces less
 */
class ProductionAnomalyTest extends Unit
{
    private const SCENARIOS = ['S2_PRODUCTION_SPIKE_BEFORE_INSPECTION', 'N1_LEGIT_PRODUCTION_INCREASE', 'N3_NIGHT_SHIFT_MAINTENANCE'];

    public function testDetectorAgainstScenarioExpectations(): void
    {
        $scores = ['tp' => 0, 'fn' => 0, 'fp' => 0, 'tn' => 0];
        foreach ($this->scenarios() as $code => $s) {
            $flags = ProductionService::anomalies([(int) $s['mine_id']], $s['date_from'], $s['date_to'])[(int) $s['mine_id']] ?? [];
            if ($s['expected_flag']) {
                $day = $s['signal']['day'];
                $hit = isset($flags[$day]);
                $scores[$hit ? 'tp' : 'fn']++;
                $this->assertTrue($hit, "$code: $day must be flagged");
                $spike = array_values(array_filter($flags[$day]['reasons'], fn($r) => $r['code'] === 'ROLLING_MEAN_SPIKE'))[0] ?? null;
                $this->assertNotNull($spike, "$code: flagged as a deviation from the rolling mean");
                $this->assertGreaterThanOrEqual($s['signal']['min_ratio_to_trailing_mean'], $spike['params']['ratio']);
                $this->assertSame('spike', $flags[$day]['direction']);
            } else {
                $scores[$flags === [] ? 'tn' : 'fp']++;
                $this->assertSame([], $flags, "$code: nothing may be flagged at mine {$s['mine_code']} between {$s['date_from']} and {$s['date_to']}");
            }
        }
        codecept_debug($scores);
        $this->assertSame(['tp' => 1, 'fn' => 0, 'fp' => 0, 'tn' => 2], $scores);
    }

    /** Across the whole fleet and period the detector stays quiet: S2 plus a handful of real steps. */
    public function testFewFlagsAcrossTheFleet(): void
    {
        $db = Yii::$app->db;
        [$from, $to] = $db->createCommand('SELECT min(date), max(date) FROM daily_production')->queryOne(\PDO::FETCH_NUM);
        $mineIds = array_map('intval', $db->createCommand('SELECT DISTINCT mine_id FROM daily_production')->queryColumn());
        $mineDays = (int) $db->createCommand('SELECT count(DISTINCT (mine_id, date)) FROM daily_production')->queryScalar();
        $flagged = array_sum(array_map('count', ProductionService::anomalies($mineIds, $from, $to)));
        $this->assertGreaterThanOrEqual(1, $flagged);
        $this->assertLessThanOrEqual(0.002 * $mineDays, $flagged, "$flagged of $mineDays mine-days flagged");
    }

    /** A rise the target explains is not an anomaly, however large; output far above target is. */
    public function testTargetExplainsARise(): void
    {
        $s = $this->scenarios()['N1_LEGIT_PRODUCTION_INCREASE'];
        $this->assertTrue($s['signal']['target_revised']);
        $totals = ProductionService::dailyTotals([(int) $s['mine_id']], $s['date_from'], $s['date_to'])[(int) $s['mine_id']];
        foreach ($totals as $date => $d) {
            $this->assertLessThan(1.5, $d['actual'] / $d['target'], "N1 $date stays near its revised target");
        }
    }

    /** @return array<string, array> the scenarios this detector is scored on, by code */
    private function scenarios(): array
    {
        $preset = getenv('TEST_PRESET') ?: 'demo';
        $path = Yii::getAlias('@app') . '/' . Yii::$app->params['dataOutDir'] . "/$preset/scenario_expectations.json";
        $all = array_column(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), null, 'scenario_code');
        $missing = array_diff(self::SCENARIOS, array_keys($all));
        $this->assertSame([], $missing, 'scenarios present in the seeded preset');
        return array_intersect_key($all, array_flip(self::SCENARIOS));
    }
}
