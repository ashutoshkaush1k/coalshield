<?php

declare(strict_types=1);

namespace app\commands;

use app\services\ObligationService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * yii obligation/check [--at=ISO]   new periods' tasks, reminders (OBLIGATION_DUE_SOON), overdue and
 *                                   escalation (OBLIGATION_OVERDUE levels 1-2). Idempotent; run by
 *                                   run_all.bat (the Phase 7 jobs later); reading the register runs it too.
 *                                   --at runs the check as of a later time (rehearsals, the browser
 *                                   check) - what the clock will do anyway when that time comes.
 * yii obligation/summary            statutory compliance per company and the 10 lowest mines
 */
class ObligationController extends Controller
{
    public ?string $at = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'check' ? ['at'] : []);
    }

    public function actionCheck(): int
    {
        $now = $this->at === null ? null : new \DateTimeImmutable($this->at);
        if ($now !== null && $now < new \DateTimeImmutable('now')) {
            $this->stderr("--at must not be in the past.\n");
            return ExitCode::USAGE;
        }
        $done = ObligationService::check($now);
        $this->stdout(sprintf("Obligations%s: %d task(s) created, %d reminder(s), %d overdue, %d escalated.\n",
            $now ? ' as of ' . $now->format('c') : '', $done['created'], $done['reminders'], $done['overdue'], $done['escalated']));
        return ExitCode::OK;
    }

    public function actionSummary(): int
    {
        $ids = array_map('intval', (new \yii\db\Query())->select('id')->from('{{%mine}}')->column());
        $s = ObligationService::summary($ids);
        $this->stdout(sprintf("Statutory compliance, tasks due in the last %d days: %s %% on time (%d of %d)\n",
            $s['window_days'], $s['totals']['compliance_pct'], $s['totals']['on_time'], $s['totals']['due']));
        foreach ($s['by_company'] as $c) {
            $this->stdout(sprintf("  %-6s %5s %%  due %4d  overdue %3d  escalated %3d\n", $c['company'], $c['compliance_pct'], $c['due'], $c['overdue'], $c['escalated']));
        }
        foreach (array_slice($s['by_mine'], 0, 10) as $m) {
            $this->stdout(sprintf("  %-10s %-28s %5s %%\n", $m['code'], mb_substr($m['name'], 0, 28), $m['compliance_pct']));
        }
        return ExitCode::OK;
    }
}
