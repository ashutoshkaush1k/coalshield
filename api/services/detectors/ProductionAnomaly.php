<?php

declare(strict_types=1);

namespace app\services\detectors;

/**
 * Production anomaly - the twin of ai-service/detectors/production.py (see there for the rule).
 * payload: {settings, from, to, days: [{mine_id, date, target, actual}]}
 */
final class ProductionAnomaly
{
    public const NAME = 'production_anomaly';

    public static function detect(array $payload): array
    {
        $cfg = $payload['settings'];
        $over = (float) $cfg['over_target_pct'] / 100;
        $window = (int) $cfg['rolling_days'];
        $minBase = (int) $cfg['min_baseline_days'];
        $ratioMin = (float) $cfg['ratio'];
        $zMin = (float) $cfg['z'];
        $from = $payload['from'];
        $to = $payload['to'];

        $byMine = [];
        foreach ($payload['days'] as $d) {
            $byMine[(int) $d['mine_id']][$d['date']] = ['target' => (float) $d['target'], 'actual' => (float) $d['actual']];
        }
        $flags = [];
        foreach ($byMine as $mineId => $days) {
            ksort($days, SORT_STRING);
            $dates = array_keys($days);
            foreach ($dates as $i => $date) {
                if ($date < $from || $date > $to || $days[$date]['target'] <= 0) {
                    continue;
                }
                $actual = $days[$date]['actual'];
                $target = $days[$date]['target'];
                $reasons = [];
                $direction = null;
                if ($actual > $target * (1 + $over)) {
                    $direction = 'spike';
                    $reasons[] = ['code' => 'OVER_TARGET', 'params' => [
                        'actual_t' => Stats::rnd($actual, 1), 'target_t' => Stats::rnd($target, 1),
                        'achievement_pct' => self::pct($actual, $target), 'threshold_pct' => (int) $cfg['over_target_pct']]];
                }
                $windowStart = (new \DateTimeImmutable($date))->modify("-{$window} days")->format('Y-m-d');
                $base = [];
                for ($j = $i - 1; $j >= 0 && $dates[$j] >= $windowStart; $j--) {
                    $b = $days[$dates[$j]];
                    if ($b['actual'] > 0 && $b['target'] > 0) {
                        $base[] = $b;
                    }
                }
                $ratio = null;
                if (count($base) >= $minBase) {
                    [$r1, $z1, $mean] = self::deviation($actual, array_column($base, 'actual'));
                    [$r2, $z2] = self::deviation($actual / $target, array_map(fn($b) => $b['actual'] / $b['target'], $base));
                    $params = ['actual_t' => Stats::rnd($actual, 1), 'rolling_mean_t' => Stats::rnd($mean, 1), 'ratio' => Stats::finite($r1, 2),
                        'z' => Stats::finite($z1, 1), 'days' => count($base)];
                    if ($r1 >= $ratioMin && $z1 >= $zMin && $r2 >= $ratioMin && $z2 >= $zMin) {
                        $direction = 'spike';
                        $reasons[] = ['code' => 'ROLLING_MEAN_SPIKE', 'params' => $params];
                        $ratio = $r1;
                    } elseif ($r1 <= 1 / $ratioMin && $z1 <= -$zMin && $r2 <= 1 / $ratioMin && $z2 <= -$zMin) {
                        $direction ??= 'drop';
                        $reasons[] = ['code' => 'ROLLING_MEAN_DROP', 'params' => $params];
                        $ratio = $r1;
                    }
                }
                if ($reasons !== []) {
                    $score = $ratio !== null ? Stats::finite($ratio, 2) : Stats::rnd($actual / $target, 2);
                    $flags[] = Stats::flag(self::NAME, $mineId, $date, "{$date}T00:00:00Z", "{$date}T23:59:59Z", (float) $score, $reasons,
                        ['direction' => $direction, 'date' => $date]);
                }
            }
        }
        return Stats::ordered($flags);
    }

    /** @return array{0: float, 1: float, 2: float} ratio to the mean, z-score, mean */
    private static function deviation(float $value, array $base): array
    {
        $mean = array_sum($base) / count($base);
        $sd = sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $base)) / count($base));
        $ratio = $mean > 0 ? $value / $mean : INF;
        $z = $sd > 0 ? ($value - $mean) / $sd : ($value == $mean ? 0.0 : ($value > $mean ? INF : -INF));
        return [$ratio, $z, $mean];
    }

    private static function pct(float $actual, float $target): ?float
    {
        return $target > 0 ? Stats::rnd(100 * $actual / $target, 1) : null;
    }
}
