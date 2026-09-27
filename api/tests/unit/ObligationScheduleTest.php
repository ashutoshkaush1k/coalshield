<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\models\Obligation;
use app\services\ObligationService;
use Codeception\Test\Unit;
use Yii;

/**
 * The register's schedule is defined twice: in the data track (gen_obligations.periods(), which
 * wrote the seeded history) and here (ObligationService::periods(), which creates the tasks of new
 * periods). They must agree exactly - every seeded task's period, dates, due time and due basis.
 */
class ObligationScheduleTest extends Unit
{
    public function testPhpScheduleReproducesTheDataTrack(): void
    {
        $preset = getenv('TEST_PRESET') ?: 'demo';
        $manifest = json_decode((string) file_get_contents(Yii::getAlias('@app') . '/' . Yii::$app->params['dataOutDir'] . "/$preset/_manifest.json"), true);
        [$first, $last] = $manifest['window'];
        $checked = 0;
        foreach (Obligation::find()->where(['generates_tasks' => true])->all() as $o) {
            $want = [];
            foreach (ObligationService::periods($o->schedule, $o->code, $first, $last) as [$label, $start, $end, $dueDay, $basis]) {
                $want[$label] = [$start, $end, gmdate('Y-m-d\TH:i:s\Z', strtotime(ObligationService::dueAt($dueDay))), $basis];
            }
            $mine = (int) Yii::$app->db->createCommand('SELECT min(mine_id) FROM obligation_task WHERE obligation_id = :o', [':o' => $o->id])->queryScalar();
            $got = [];
            foreach (Yii::$app->db->createCommand('SELECT period, period_start, period_end, due_at, due_basis FROM obligation_task
                WHERE obligation_id = :o AND mine_id = :m', [':o' => $o->id, ':m' => $mine])->queryAll() as $r) {
                $got[$r['period']] = [$r['period_start'], $r['period_end'], gmdate('Y-m-d\TH:i:s\Z', strtotime($r['due_at'])), $r['due_basis']];
            }
            ksort($want);
            ksort($got);
            $this->assertSame($want, $got, "{$o->code} ({$o->schedule})");
            $checked += count($got);
        }
        $this->assertGreaterThan(40, $checked);
        $this->assertSame([], Obligation::find()->select('code')->where(['generates_tasks' => true, 'verified' => false])->column(), 'only verified obligations get tasks');
        $this->assertFalse((bool) Obligation::find()->where(['code' => 'RPT-08'])->one()->generates_tasks, 'RPT-08 (TODO-VERIFY) never gets tasks');
    }

    public function testDueDatesNamedInTheRuleAreLaw(): void
    {
        // ENV-03: "on or before 30 September, for the financial year ending 31 March"
        $env = ObligationService::periods('annual', 'ENV-03', '2026-06-28', '2026-09-25');
        $this->assertContains(['FY2025-26', '2025-04-01', '2026-03-31', '2026-09-30', 'law'], $env);
        // RPT-06: "on or before 28/29 February following each calendar year"
        $this->assertContains(['2027', '2027-01-01', '2027-12-31', '2028-02-29', 'law'], ObligationService::periods('annual', 'RPT-06', '2027-06-01', '2027-06-30'));
        // Anything else: the product setting, the period's last day.
        $this->assertContains(['2026-W39', '2026-09-21', '2026-09-27', '2026-09-27', 'product'], ObligationService::periods('weekly', 'SAF-07', '2026-09-20', '2026-09-25'));
        $this->assertSame('2026-09-27 18:29:59.000000+00:00', ObligationService::dueAt('2026-09-27'), '23:59:59 IST');
    }
}
