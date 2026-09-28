<?php

declare(strict_types=1);

namespace app\commands;

use app\components\JobRunner;
use app\services\AnomalyService;
use app\services\AutomationService;
use app\services\ContractorAlertService;
use app\services\DetailRequestService;
use app\services\GrievanceService;
use app\services\ObligationService;
use app\services\ProductionService;
use app\services\RiskModelService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * Scheduled jobs (Phase 7). Each is idempotent, logged (job_run and runtime/logs/jobs.log) and
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
    public const JOBS = ['reminders', 'sla', 'escalate-alerts', 'score', 'anomaly', 'contractor', 'obligation', 'production', 'grievance'];

    public function actionReminders(): int
    {
        return $this->job('reminders', fn() => [
            'contractor_alerts' => count(ContractorAlertService::run()),
            'obligations' => ObligationService::check(),
            'production' => AutomationService::productionReminders(),
        ]);
    }

    public function actionSla(): int
    {
        return $this->job('sla', fn() => ['grievances' => GrievanceService::escalateDue(), 'detail_requests' => DetailRequestService::escalateDue()]);
    }

    public function actionEscalateAlerts(): int
    {
        return $this->job('escalate-alerts', fn() => AutomationService::escalateAlerts());
    }

    public function actionScore(): int
    {
        return $this->job('score', fn() => ['snapshot' => AutomationService::snapshot(), 'prediction' => RiskModelService::refresh()]);
    }

    public function actionAnomaly(): int
    {
        return $this->job('anomaly', fn() => AnomalyService::run());
    }

    public function actionContractor(): int
    {
        return $this->job('contractor', fn() => ['alerts' => count(ContractorAlertService::run())]);
    }

    public function actionObligation(): int
    {
        return $this->job('obligation', fn() => ObligationService::check());
    }

    public function actionProduction(): int
    {
        return $this->job('production', fn() => ['locked' => ProductionService::lockPastPeriods(), 'detail_requests' => DetailRequestService::escalateDue()]);
    }

    public function actionGrievance(): int
    {
        return $this->job('grievance', fn() => GrievanceService::escalateDue());
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

    private function job(string $name, callable $work): int
    {
        $r = JobRunner::run($name, $work);
        $this->stdout(sprintf("%-16s %-8s %6.2fs  %s\n", $name, $r['status'], $r['seconds'], json_encode($r['summary'])));
        return $r['status'] === 'failed' ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
