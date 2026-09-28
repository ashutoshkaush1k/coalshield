<?php

declare(strict_types=1);

namespace app\services\detectors;

/**
 * Grievance SLA-breach cluster - the twin of ai-service/detectors/grievance_cluster.py.
 * payload: {settings: {window_days, min_breaches}, breaches: [{id, mine_id, t}]}
 */
final class GrievanceCluster
{
    public const NAME = 'grievance_cluster';

    /** @return array<int, array> per mine: breaches, from, to, grievance_ids, window_days, t0, t1 */
    public static function clusters(array $payload): array
    {
        $cfg = $payload['settings'];
        $window = (int) $cfg['window_days'] * 86400;
        $breaches = $payload['breaches'];
        usort($breaches, fn($a, $b) => [(int) $a['t'], (int) $a['id']] <=> [(int) $b['t'], (int) $b['id']]);
        $byMine = [];
        foreach ($breaches as $b) {
            $byMine[(int) $b['mine_id']][] = $b;
        }
        $out = [];
        foreach ($byMine as $mineId => $bs) {
            $best = [];
            foreach ($bs as $start) {
                $inside = array_values(array_filter($bs, fn($b) => (int) $start['t'] <= (int) $b['t'] && (int) $b['t'] < (int) $start['t'] + $window));
                if (count($inside) > count($best)) {
                    $best = $inside;
                }
            }
            if (count($best) >= (int) $cfg['min_breaches']) {
                $out[$mineId] = ['breaches' => count($best), 'from' => Stats::day((int) $best[0]['t']), 'to' => Stats::day((int) end($best)['t']),
                    'grievance_ids' => array_map(fn($b) => (int) $b['id'], $best), 'window_days' => (int) $cfg['window_days'],
                    't0' => (int) $best[0]['t'], 't1' => (int) end($best)['t']];
            }
        }
        return $out;
    }

    public static function detect(array $payload): array
    {
        $flags = [];
        foreach (self::clusters($payload) as $mineId => $c) {
            $flags[] = Stats::flag(self::NAME, $mineId, 'sla', Stats::iso($c['t0']), Stats::iso($c['t1']), (float) $c['breaches'],
                [['code' => 'GRIEVANCE_SLA_CLUSTER', 'params' => ['breaches' => $c['breaches'], 'window_days' => $c['window_days'],
                    'from' => $c['from'], 'to' => $c['to']]]],
                ['grievance_ids' => $c['grievance_ids']]);
        }
        return Stats::ordered($flags);
    }
}
