<?php

declare(strict_types=1);

namespace app\services\detectors;

/**
 * Flatlined sensor - the twin of ai-service/detectors/flatline.py.
 * payload: {settings: {min_hours, tolerance}, hours: [{mine_id, sensor_type, hour, min, max, count}]}
 */
final class SensorFlatline
{
    public const NAME = 'sensor_flatline';

    public static function detect(array $payload): array
    {
        $cfg = $payload['settings'];
        $minHours = (int) $cfg['min_hours'];
        $tol = (float) $cfg['tolerance'];
        $series = [];
        foreach ($payload['hours'] as $h) {
            if ((float) $h['max'] - (float) $h['min'] <= $tol && (int) $h['count'] >= 1) {
                $series[(int) $h['mine_id'] . '|' . $h['sensor_type']][] = $h;
            }
        }
        $flags = [];
        foreach ($series as $key => $hours) {
            [$mineId, $sensor] = explode('|', $key, 2);
            usort($hours, fn($a, $b) => (int) $a['hour'] <=> (int) $b['hour']);
            $close = function (array $run) use (&$flags, $minHours, $mineId, $sensor): void {
                if (count($run) < $minHours) {
                    return;
                }
                $lo = min(array_map(fn($h) => (float) $h['min'], $run));
                $hi = max(array_map(fn($h) => (float) $h['max'], $run));
                $start = (int) $run[0]['hour'];
                $end = (int) end($run)['hour'] + 3600;
                $flags[] = Stats::flag(self::NAME, (int) $mineId, $sensor . '|' . Stats::iso($start), Stats::iso($start), Stats::iso($end),
                    (float) count($run),
                    [['code' => 'FLATLINE', 'params' => ['sensor_type' => $sensor, 'hours' => count($run), 'value' => Stats::rnd($lo, 3),
                        'readings' => array_sum(array_map(fn($h) => (int) $h['count'], $run))]]],
                    ['sensor_type' => $sensor, 'value_range' => [Stats::rnd($lo, 3), Stats::rnd($hi, 3)]]);
            };
            $run = [];
            foreach ($hours as $h) {
                if ($run !== []) {
                    $last = end($run);
                    $hi = max(max(array_map(fn($x) => (float) $x['max'], $run)), (float) $h['max']);
                    $lo = min(min(array_map(fn($x) => (float) $x['min'], $run)), (float) $h['min']);
                    if ((int) $h['hour'] - (int) $last['hour'] !== 3600 || $hi - $lo > $tol) {
                        $close($run);
                        $run = [];
                    }
                }
                $run[] = $h;
            }
            $close($run);
        }
        return Stats::ordered($flags);
    }
}
