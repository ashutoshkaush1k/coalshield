<?php

declare(strict_types=1);

namespace app\services\detectors;

/**
 * Late corrective actions - the twin of ai-service/detectors/late_actions.py.
 * payload: {settings: {min_late, p_max}, actions: [{id, mine_id, due, resolved}]}
 */
final class LateActions
{
    public const NAME = 'late_actions';

    public static function detect(array $payload): array
    {
        $cfg = $payload['settings'];
        $actions = $payload['actions'];
        if ($actions === []) {
            return [];
        }
        $lateAll = count(array_filter($actions, fn($a) => (int) $a['resolved'] > (int) $a['due']));
        $rate = $lateAll / count($actions);
        $byMine = [];
        foreach ($actions as $a) {
            $byMine[(int) $a['mine_id']][] = $a;
        }
        $flags = [];
        foreach ($byMine as $mineId => $acts) {
            $late = array_values(array_filter($acts, fn($a) => (int) $a['resolved'] > (int) $a['due']));
            usort($late, fn($x, $y) => (int) $x['resolved'] <=> (int) $y['resolved']);
            $k = count($late);
            $n = count($acts);
            if ($k < (int) $cfg['min_late']) {
                continue;
            }
            $p = Stats::binomSf($k, $n, $rate);
            if ($p < (float) $cfg['p_max']) {
                $ids = array_map(fn($a) => (int) $a['id'], $late);
                sort($ids);
                $flags[] = Stats::flag(self::NAME, $mineId, 'closures', Stats::iso((int) $late[0]['resolved']), Stats::iso((int) end($late)['resolved']),
                    Stats::rnd($k / $n, 2),
                    [['code' => 'LATE_CLOSURES', 'params' => ['late' => $k, 'resolved' => $n, 'share_pct' => Stats::rnd(100 * $k / $n, 1),
                        'fleet_pct' => Stats::rnd(100 * $rate, 1), 'p' => Stats::sig($p)]]],
                    ['corrective_action_ids' => $ids]);
            }
        }
        return Stats::ordered($flags);
    }
}
