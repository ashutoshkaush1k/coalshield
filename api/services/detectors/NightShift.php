<?php

declare(strict_types=1);

namespace app\services\detectors;

/**
 * Night-shift concentration - the twin of ai-service/detectors/night_shift.py.
 * payload: {settings: {night_from, night_to, p_max, min_count}, from, to, violations: [{id, mine_id, hour_ist}]}
 */
final class NightShift
{
    public const NAME = 'night_shift';

    public static function isNight(int $hour, int $from, int $to): bool
    {
        return $from > $to ? ($hour >= $from || $hour < $to) : ($from <= $hour && $hour < $to);
    }

    public static function detect(array $payload): array
    {
        $cfg = $payload['settings'];
        $nf = (int) $cfg['night_from'];
        $nt = (int) $cfg['night_to'];
        $nightHours = 0;
        for ($h = 0; $h < 24; $h++) {
            $nightHours += self::isNight($h, $nf, $nt) ? 1 : 0;
        }
        $base = $nightHours / 24;
        $byMine = [];
        foreach ($payload['violations'] as $v) {
            $byMine[(int) $v['mine_id']][] = $v;
        }
        $flags = [];
        foreach ($byMine as $mineId => $vs) {
            $n = count($vs);
            $night = array_values(array_filter($vs, fn($v) => self::isNight((int) $v['hour_ist'], $nf, $nt)));
            $k = count($night);
            if ($n < (int) $cfg['min_count']) {
                continue;
            }
            $p = Stats::binomSf($k, $n, $base);
            if ($p < (float) $cfg['p_max']) {
                $ids = array_map(fn($v) => (int) $v['id'], $night);
                sort($ids);
                $flags[] = Stats::flag(self::NAME, $mineId, 'night', $payload['from'], $payload['to'], Stats::rnd($k / $n, 2),
                    [['code' => 'NIGHT_CONCENTRATION', 'params' => ['night' => $k, 'total' => $n, 'share_pct' => Stats::rnd(100 * $k / $n, 1),
                        'expected_pct' => Stats::rnd(100 * $base, 1), 'p' => Stats::sig($p)]]],
                    ['violation_ids' => $ids]);
            }
        }
        return Stats::ordered($flags);
    }
}
