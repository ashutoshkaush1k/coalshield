<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\components\JobRunner;
use app\models\Alert;
use app\models\AnomalyFlag;
use app\services\AiEvaluation;
use app\services\AnomalyService;
use app\services\AutomationService;
use app\services\GovernanceRiskService;
use app\services\RiskModelService;
use Codeception\Test\Unit;
use Yii;
use yii\db\Query;

/**
 * The scheduled jobs (Phase 7): each is idempotent - run twice, the second run finds nothing to do -
 * each is logged in job_run, and a copy started while another runs skips itself.
 */
class JobsTest extends Unit
{
    public function testAnomalyRunIsIdempotentAndRaisesOneAlertPerFinding(): void
    {
        $asOf = AiEvaluation::asOf();
        $first = AnomalyService::run($asOf, 'php');
        $new = array_sum(array_column($first, 'new'));
        $this->assertGreaterThan(0, $new);
        $this->assertSame($new, (int) AnomalyFlag::find()->where(['status' => 'active'])->count());
        $this->assertSame($new, (int) Alert::find()->where(['code' => 'ANOMALY_DETECTED'])->count());
        $this->assertSame(['php'], array_values(array_unique(array_column($first, 'engine'))));

        $second = AnomalyService::run($asOf, 'php');
        $this->assertSame(0, array_sum(array_column($second, 'new')));
        $this->assertSame(0, array_sum(array_column($second, 'cleared')));
        $this->assertSame($new, (int) Alert::find()->where(['code' => 'ANOMALY_DETECTED'])->count(), 'no duplicate alerts');
    }

    public function testAFindingNoLongerMadeIsClearedAndItsAlertResolved(): void
    {
        AnomalyService::run(AiEvaluation::asOf(), 'php');
        // A year later the 90-day windows hold none of the demo data: the dated findings clear
        // (a contractor's documents still missing then is still a finding).
        $later = AnomalyService::run(AiEvaluation::asOf()->modify('+1 year'), 'php');
        $cleared = AnomalyFlag::find()->where(['status' => 'cleared'])->all();
        $this->assertSame(array_sum(array_column($later, 'cleared')), count($cleared));
        $this->assertGreaterThan(0, count($cleared));
        $this->assertSame(0, $later['production_anomaly']['flags'] + $later['night_shift']['flags'] + $later['sensor_flatline']['flags']);
        foreach ($cleared as $flag) {
            $this->assertNotNull($flag->cleared_at);
            $this->assertSame(0, (int) Alert::find()->where(['code' => 'ANOMALY_DETECTED', 'entity_type' => 'anomaly_flag', 'entity_id' => $flag->id])
                ->andWhere(['<>', 'status', Alert::STATUS_RESOLVED])->count(), "alert of cleared flag {$flag->id} resolved");
        }
    }

    public function testEscalationMovesEachAlertOnceAndIsIdempotent(): void
    {
        $now = new \DateTimeImmutable('2026-09-28T12:00:00Z');
        $first = AutomationService::escalateAlerts($now);
        $this->assertGreaterThan(0, $first['to_level_1'] + $first['to_level_2']);
        $this->assertSame(['to_level_1' => 0, 'to_level_2' => 0], AutomationService::escalateAlerts($now));
        // Level 2 only for open alerts older than 72 h.
        $cutoff = $now->modify('-72 hours')->format('Y-m-d H:i:sP');
        $this->assertSame(0, (int) Alert::find()->where(['escalation_level' => 2])->andWhere(['>', 'created_at', $cutoff])->count());
        // A system action: the history row has no user.
        $row = (new Query())->from('{{%status_history}}')->where(['entity' => 'alert'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNull($row['user_id']);
    }

    public function testProductionRemindersAreRaisedOnceAndResolvedWhenEntered(): void
    {
        $now = new \DateTimeImmutable('2026-09-27T03:00:00Z');   // 08:30 IST: yesterday is the demo data's last day
        $first = AutomationService::productionReminders($now);
        $this->assertSame('2026-09-26', $first['date']);
        $second = AutomationService::productionReminders($now);
        $this->assertSame(0, $second['raised']);
        $this->assertSame((int) Alert::find()->where(['code' => 'PRODUCTION_ENTRY_PENDING'])->count(), $first['raised']);
    }

    public function testScoreJobStoresOneSnapshotPerMineAndDay(): void
    {
        $now = new \DateTimeImmutable('2026-09-28T01:00:00Z');
        $first = AutomationService::snapshot($now);
        AutomationService::snapshot($now);
        $mines = (int) (new Query())->from('{{%mine}}')->count();
        $this->assertSame($mines, (int) (new Query())->from('{{%mine_risk_snapshot}}')->count());
        $refreshed = RiskModelService::refresh($now, 'php');
        $this->assertSame($mines, (int) (new Query())->from('{{%mine_risk_prediction}}')->count());
        $this->assertNotEmpty($first);
        $this->assertNotEmpty($refreshed);
        // The snapshot's index is the live one.
        $row = (new Query())->from('{{%mine_risk_snapshot}}')->where(['mine_id' => 5])->one();
        $this->assertSame(GovernanceRiskService::forMines([5], null, $now)[5]['gri'], (int) $row['gri']);
    }

    public function testJobRunnerLogsAndSkipsWhenAlreadyRunning(): void
    {
        $result = JobRunner::run('test-job', fn() => ['done' => 1]);
        $this->assertSame('ok', $result['status']);
        $row = (new Query())->from('{{%job_run}}')->where(['job' => 'test-job'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame('ok', $row['status']);
        $this->assertSame(1, json_decode($row['summary'], true)['done']);

        // Another session holds the job's lock, as a still-running copy would. A raw PDO: the test
        // module hands every Yii connection the same PDO (one transaction), and advisory locks are per session.
        $db = Yii::$app->db;
        $other = new \PDO($db->dsn, $db->username, $db->password);
        $lock = (int) crc32('coalshield-job:test-job');
        $this->assertTrue((bool) $other->query("SELECT pg_try_advisory_lock($lock)")->fetchColumn());
        try {
            $ran = false;
            $skipped = JobRunner::run('test-job', function () use (&$ran) {
                $ran = true;
                return [];
            });
            $this->assertSame('skipped', $skipped['status']);
            $this->assertFalse($ran);
        } finally {
            $other->query("SELECT pg_advisory_unlock($lock)");
            $other = null;
        }

        $failed = JobRunner::run('test-job', fn() => throw new \RuntimeException('boom'));
        $this->assertSame('failed', $failed['status']);
        $row = (new Query())->from('{{%job_run}}')->where(['job' => 'test-job'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertStringContainsString('boom', $row['error']);
    }
}
