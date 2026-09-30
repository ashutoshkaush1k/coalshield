<?php

declare(strict_types=1);

namespace app\commands;

use app\components\JobRunner;
use app\components\ScheduledJobs;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * Scheduled jobs (Phase 7; the work itself is in components/ScheduledJobs.php, shared with
 * POST /v1/system/jobs online). Each is idempotent, logged (job_run and runtime/logs/jobs.log) and
 * safe to start while another copy runs (it is then skipped). Registered in Windows Task
 * Scheduler by scripts/register_tasks.ps1; run once at startup by run_all.bat (jobs/all).
 *
 *   yii jobs/reminders        contractor licence, document, VT and medical expiries; statutory
 *                             obligations due soon; yesterday's production shifts not submitted
 *   yii jobs/sla              grievance response times and "Call for Detailed Report" deadlines ->
 *                             escalations
 *   yii jobs/escalate-alerts  unacknowledged alerts after 24 h -> level 1, after 72 h -> level 2
 *   yii jobs/score            the day's compliance score and Governance Risk Index per mine
 *                             (mine_risk_snapshot); the predictive model's predictions
 *   yii jobs/anomaly          the seven anomaly detectors (ai-service, PHP fallback)
 *   yii jobs/contractor       contractor alerts            yii jobs/obligation   the obligation register
 *   yii jobs/production       lock past periods, detail-request deadlines
 *   yii jobs/grievance        grievance SLA
 *   yii jobs/all              every job above, in that order
 *   yii jobs/status           the last run of each job
 */
class JobsController extends Controller
{
    public const JOBS = ScheduledJobs::JOBS;

    public function actionReminders(): int
    {
        return $this->job('reminders');
    }

    public function actionSla(): int
    {
        return $this->job('sla');
    }

    public function actionEscalateAlerts(): int
    {
        return $this->job('escalate-alerts');
    }

    public function actionScore(): int
    {
        return $this->job('score');
    }

    public function actionAnomaly(): int
    {
        return $this->job('anomaly');
    }

    public function actionContractor(): int
    {
        return $this->job('contractor');
    }

    public function actionObligation(): int
    {
        return $this->job('obligation');
    }

    public function actionProduction(): int
    {
        return $this->job('production');
    }

    public function actionGrievance(): int
    {
        return $this->job('grievance');
    }

    public function actionAll(): int
    {
        $failed = 0;
        foreach (self::JOBS as $job) {
            $failed += $this->runAction($job) === ExitCode::OK ? 0 : 1;
        }
        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    public function actionStatus(): int
    {
        foreach (self::JOBS as $job) {
            $last = (new Query())->from('{{%job_run}}')->where(['job' => $job])->orderBy(['id' => SORT_DESC])->one();
            $this->stdout(sprintf("%-16s %s\n", $job, $last === null ? 'never run'
                : sprintf('%s  %s  %s', $last['started_at'], $last['status'], mb_substr((string) ($last['error'] ?? $last['summary']), 0, 110))));
        }
        return ExitCode::OK;
    }

    private function job(string $name): int
    {
        $r = JobRunner::run($name, ScheduledJobs::work($name));
        $this->stdout(sprintf("%-16s %-8s %6.2fs  %s\n", $name, $r['status'], $r['seconds'], json_encode($r['summary'])));
        return $r['status'] === 'failed' ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
