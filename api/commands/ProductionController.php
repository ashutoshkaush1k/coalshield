<?php

declare(strict_types=1);

namespace app\commands;

use app\services\DetailRequestService;
use app\services\ProductionService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * yii production/check              close past reporting periods (submitted -> locked) and run the
 *                                   detail-request deadlines (overdue, escalated, with alerts).
 *                                   Idempotent; run by run_all.bat (the daily job from Phase 7).
 * yii production/anomalies [from] [to]   the anomaly detector's flagged days (default: last 90 days)
 */
class ProductionController extends Controller
{
    public function actionCheck(): int
    {
        $locked = ProductionService::lockPastPeriods();
        $deadlines = DetailRequestService::escalateDue();
        $this->stdout(sprintf("%d entries locked; detail requests: %d overdue, %d escalated.\n",
            $locked, $deadlines['overdue'], $deadlines['escalated']));
        return ExitCode::OK;
    }

    public function actionAnomalies(?string $from = null, ?string $to = null): int
    {
        $to ??= ProductionService::today();
        $from ??= (new \DateTimeImmutable($to))->modify('-90 days')->format('Y-m-d');
        $mines = (new Query())->select(['id', 'code', 'name'])->from('{{%mine}}')->indexBy('id')->all();
        $count = 0;
        foreach (ProductionService::anomalies(array_keys($mines), $from, $to) as $mineId => $days) {
            foreach ($days as $date => $flag) {
                $count++;
                $this->stdout(sprintf("  %s  %-10s %-28s %-5s %s\n", $date, $mines[$mineId]['code'], mb_substr($mines[$mineId]['name'], 0, 28),
                    $flag['direction'], implode(', ', array_map(fn($r) => $r['code'] . ' ' . json_encode($r['params']), $flag['reasons']))));
            }
        }
        $this->stdout("$count flagged mine-day(s) between $from and $to.\n");
        return ExitCode::OK;
    }
}
