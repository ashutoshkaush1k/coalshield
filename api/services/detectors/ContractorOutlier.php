<?php

declare(strict_types=1);

namespace app\services\detectors;

/**
 * Contractor outlier - the twin of ai-service/detectors/contractor.py.
 * payload: {settings: {z, min_missing_docs, min_violations}, from, to,
 *           contractors: [{contractor_id, mine_id, active_workers, violations, missing_docs}]}
 */
final class ContractorOutlier
{
    public const NAME = 'contractor_outlier';

    private static function median(array $xs): float
    {
        sort($xs);
        $n = count($xs);
        return $n % 2 ? (float) $xs[intdiv($n, 2)] : ($xs[intdiv($n, 2) - 1] + $xs[intdiv($n, 2)]) / 2;
    }

    /** @return list<float> */
    private static function zscores(array $xs): array
    {
        $med = self::median($xs);
        $dev = array_map(fn($x) => abs($x - $med), $xs);
        $scale = 1.4826 * self::median($dev);
        if ($scale == 0.0) {
            $scale = 1.2533 * array_sum($dev) / count($dev);
        }
        return array_map(fn($x) => $scale == 0.0 ? 0.0 : ($x - $med) / $scale, $xs);
    }

    public static function detect(array $payload): array
    {
        $cfg = $payload['settings'];
        $cs = array_values($payload['contractors']);
        if (count($cs) < 3) {
            return [];
        }
        $docs = array_map(fn($c) => (float) $c['missing_docs'], $cs);
        $vpw = array_map(fn($c) => (int) $c['active_workers'] > 0 ? (float) $c['violations'] / $c['active_workers'] : 0.0, $cs);
        $zDocs = self::zscores($docs);
        $zVpw = self::zscores($vpw);
        $medDocs = self::median($docs);
        $medVpw = self::median($vpw);
        $zMin = (float) $cfg['z'];
        $flags = [];
        foreach ($cs as $i => $c) {
            $reasons = [];
            if ($zDocs[$i] >= $zMin && (int) $c['missing_docs'] >= (int) $cfg['min_missing_docs']) {
                $reasons[] = ['code' => 'CONTRACTOR_MISSING_DOCS', 'params' => ['missing' => (int) $c['missing_docs'],
                    'median' => Stats::rnd($medDocs, 1), 'z' => Stats::rnd($zDocs[$i], 1)]];
            }
            if ($zVpw[$i] >= $zMin && (int) $c['violations'] >= (int) $cfg['min_violations']) {
                $reasons[] = ['code' => 'CONTRACTOR_VIOLATION_RATE', 'params' => ['per_worker' => Stats::rnd($vpw[$i], 2),
                    'median' => Stats::rnd($medVpw, 2), 'z' => Stats::rnd($zVpw[$i], 1)]];
            }
            if ($reasons !== [] && ($c['mine_id'] ?? null) !== null) {
                $flags[] = Stats::flag(self::NAME, (int) $c['mine_id'], 'contractor:' . (int) $c['contractor_id'], (string) ($payload['from'] ?? ''),
                    (string) ($payload['to'] ?? ''), Stats::rnd(max($zDocs[$i], $zVpw[$i]), 1), $reasons, ['contractor_id' => (int) $c['contractor_id']]);
            }
        }
        return Stats::ordered($flags);
    }
}
