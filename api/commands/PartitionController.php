<?php

declare(strict_types=1);

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * yii partition/ensure [months]  create the monthly sensor_reading partitions for the current
 * month and the next N (default 3) if they are missing. Safe to run repeatedly (e.g. monthly).
 * Rows outside every monthly partition land in sensor_reading_default.
 */
class PartitionController extends Controller
{
    public function actionEnsure(int $months = 3): int
    {
        $db = Yii::$app->db;
        $month = new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('UTC'));
        for ($i = 0; $i <= $months; $i++) {
            $next = $month->modify('+1 month');
            $name = 'sensor_reading_' . $month->format('Y_m');
            $exists = $db->createCommand('SELECT to_regclass(:n) IS NOT NULL', [':n' => $name])->queryScalar();
            if (!$exists) {
                $db->createCommand(sprintf("CREATE TABLE %s PARTITION OF sensor_reading FOR VALUES FROM ('%s') TO ('%s')",
                    $name, $month->format('Y-m-d'), $next->format('Y-m-d')))->execute();
                $this->stdout("created $name\n");
            }
            $month = $next;
        }
        $this->stdout("Partitions ensured through " . $month->modify('-1 month')->format('Y-m') . ".\n");
        return ExitCode::OK;
    }
}
