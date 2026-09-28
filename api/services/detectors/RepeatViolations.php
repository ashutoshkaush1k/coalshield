<?php

declare(strict_types=1);

namespace app\services\detectors;

/**
 * Repeat violations - the twin of ai-service/detectors/repeat.py.
 * payload: {settings: {window_days, min_repeats, p_max, hazard_incident_types},
 *           violations: [{id, mine_id, category, t}], incidents: [{id, mine_id, type, t}]}
 */
final class RepeatViolations
{
    public const NAME = 'repeat_violations';

    public static function detect(array $payload): array
    {
        $cfg = $payload['settings'];
        $window = (int) $cfg['window_days'] * 86400;
        $minRep = (int) $cfg['min_repeats'];
        $pMax = (float) $cfg['p_max'];
        $hazards = $cfg['hazard_incident_types'] ?? [];

        $fleet = [];
        $groups = [];
        $byMine = [];
        foreach ($payload['violations'] as $v) {
            $fleet[$v['category']] = ($fleet[$v['category']] ?? 0) + 1;
            $groups[(int) $v['mine_id'] . '|' . $v['category']][] = $v;
            $byMine[(int) $v['mine_id']][] = (int) $v['t'];
        }
        $total = count($payload['violations']);
        $incidents = [];
        foreach ($payload['incidents'] as $i) {
            $incidents[(int) $i['mine_id']][] = $i;
        }
        $flags = [];
        foreach ($groups as $key => $vs) {
            [$mineId, $category] = explode('|', $key, 2);
            $mineId = (int) $mineId;
            usort($vs, fn($a, $b) => [(int) $a['t'], (int) $a['id']] <=> [(int) $b['t'], (int) $b['id']]);
            $best = [0, 0, 0];
            $lo = 0;
            for ($hi = 0, $n = count($vs); $hi < $n; $hi++) {
                while ((int) $vs[$hi]['t'] - (int) $vs[$lo]['t'] > $window) {
                    $lo++;
                }
                if ($hi - $lo + 1 > $best[0]) {
                    $best = [$hi - $lo + 1, $lo, $hi];
                }
            }
            [$count, $a, $b] = $best;
            if ($count < $minRep) {
                continue;
            }
            $start = (int) $vs[$a]['t'];
            $end = (int) $vs[$b]['t'];
            $share = $fleet[$category] / $total;
            $mineTotal = count(array_filter($byMine[$mineId], fn($t) => $start <= $t && $t <= $end));
            $p = Stats::binomSf($count, $mineTotal, $share);
            $reasons = [];
            if ($p < $pMax) {
                $reasons[] = ['code' => 'REPEAT_IMPROBABLE', 'params' => ['category' => $category, 'count' => $count, 'of' => $mineTotal,
                    'days' => intdiv($window, 86400), 'expected' => Stats::rnd($mineTotal * $share, 1), 'p' => Stats::sig($p)]];
            }
            $types = $hazards[$category] ?? [];
            $linked = array_values(array_filter($incidents[$mineId] ?? [],
                fn($i) => in_array($i['type'], $types, true) && $start <= (int) $i['t'] && (int) $i['t'] <= $end + $window));
            usort($linked, fn($x, $y) => [(int) $x['t'], (int) $x['id']] <=> [(int) $y['t'], (int) $y['id']]);
            if ($linked !== []) {
                $reasons[] = ['code' => 'REPEAT_THEN_INCIDENT', 'params' => ['category' => $category, 'count' => $count,
                    'days' => intdiv($end - $start, 86400) + 1, 'incident_id' => (int) $linked[0]['id'], 'incident_date' => Stats::day((int) $linked[0]['t'])]];
            }
            if ($reasons !== []) {
                $flags[] = Stats::flag(self::NAME, $mineId, $category, Stats::iso($start), Stats::iso($end), (float) $count, $reasons, [
                    'category' => $category,
                    'violation_ids' => array_map(fn($v) => (int) $v['id'], array_slice($vs, $a, $b - $a + 1)),
                    'incident_ids' => array_map(fn($i) => (int) $i['id'], $linked),
                ]);
            }
        }
        return Stats::ordered($flags);
    }
}
