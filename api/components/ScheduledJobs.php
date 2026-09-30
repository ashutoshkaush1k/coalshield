<?php

declare(strict_types=1);

namespace app\components;

use app\services\AnomalyService;
use app\services\AutomationService;
use app\services\ContractorAlertService;
use app\services\DetailRequestService;
use app\services\GrievanceService;
use app\services\ObligationService;
use app\services\ProductionService;
use app\services\RiskModelService;

/**
 * The scheduled jobs (Phase 7) in one place: `yii jobs/*` on the laptop (Task Scheduler) and
 * POST /v1/system/jobs online (a GitHub Actions schedule, docs/DEPLOYMENT.md) run the same work,
 * each through JobRunner (job_run row, advisory lock, log).
 */
final class ScheduledJobs
{
    /** Every job, in the order jobs/all runs them. */
    public const JOBS = ['reminders', 'sla', 'escalate-alerts', 'score', 'anomaly', 'contractor', 'obligation', 'production', 'grievance'];

    /** The work of one job: a callable that returns its summary. */
    public static function work(string $job): callable
    {
        return match ($job) {
            'reminders' => fn() => [
                'contractor_alerts' => count(ContractorAlertService::run()),
                'obligations' => ObligationService::check(),
                'production' => AutomationService::productionReminders(),
            ],
            'sla' => fn() => ['grievances' => GrievanceService::escalateDue(), 'detail_requests' => DetailRequestService::escalateDue()],
            'escalate-alerts' => fn() => AutomationService::escalateAlerts(),
            'score' => fn() => ['snapshot' => AutomationService::snapshot(), 'prediction' => RiskModelService::refresh()],
            'anomaly' => fn() => AnomalyService::run(),
            'contractor' => fn() => ['alerts' => count(ContractorAlertService::run())],
            'obligation' => fn() => ObligationService::check(),
            'production' => fn() => ['locked' => ProductionService::lockPastPeriods(), 'detail_requests' => DetailRequestService::escalateDue()],
            'grievance' => fn() => GrievanceService::escalateDue(),
            default => throw new \InvalidArgumentException("unknown job $job"),
        };
    }

    /**
     * Run every job in order. @return array<string, array{status: string, summary: array, seconds: float}>
     */
    public static function runAll(): array
    {
        $results = [];
        foreach (self::JOBS as $job) {
            $results[$job] = JobRunner::run($job, self::work($job));
        }
        return $results;
    }
}
