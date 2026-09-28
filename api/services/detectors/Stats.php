<?php

declare(strict_types=1);

namespace app\services\detectors;

/**
 * Shared pieces of the detectors - the twin of ai-service/detectors/common.py, line for line, so
 * the PHP fallback returns the same flags (tests/unit/DetectorParityTest.php).
 */
final class Stats
{
    public static function logFactorial(int $n): float
    {
        $total = 0.0;
        for ($j = 2; $j <= $n; $j++) {
            $total += log($j);
        }
        return $total;
    }

    /** P(X >= k) for X ~ Poisson(lam), summed upward from k in log space. */
    public static function poissonSf(int $k, float $lam): float
    {
        if ($k <= 0) {
            return 1.0;
        }
        if ($lam <= 0) {
            return 0.0;
        }
        $total = 0.0;
        $lf = self::logFactorial($k);
        $i = $k;
        while ($i < $k + 1000) {
            $term = exp(-$lam + $i * log($lam) - $lf);
            $total += $term;
            if ($term < 1e-300 || ($i > $lam && $term < $total * 1e-17)) {
                break;
            }
            $i++;
            $lf += log($i);
        }
        return min(1.0, $total);
    }

    /** P(X >= k) for X ~ Binomial(n, p). */
    public static function binomSf(int $k, int $n, float $p): float
    {
        if ($k <= 0) {
            return 1.0;
        }
        if ($k > $n || $p <= 0) {
            return 0.0;
        }
        if ($p >= 1) {
            return 1.0;
        }
        $lfn = self::logFactorial($n);
        $total = 0.0;
        for ($i = $k; $i <= $n; $i++) {
            $total += exp($lfn - self::logFactorial($i) - self::logFactorial($n - $i) + $i * log($p) + ($n - $i) * log(1 - $p));
        }
        return min(1.0, $total);
    }

    /** Round half away from zero (as Python's rnd()). */
    public static function rnd(float $x, int $digits = 0): float
    {
        $f = 10 ** $digits;
        return floor(abs($x) * $f + 0.5) / $f * ($x >= 0 ? 1 : -1);
    }

    /** 3 significant digits. */
    public static function sig(float $x): float
    {
        if ($x == 0.0 || !is_finite($x)) {
            return $x == 0.0 ? 0.0 : $x;
        }
        return (float) sprintf('%.3g', $x);
    }

    public static function iso(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    public static function day(int $t): string
    {
        return gmdate('Y-m-d', $t);
    }

    public static function finite(float $x, int $digits): ?float
    {
        return is_finite($x) ? self::rnd($x, $digits) : null;
    }

    public static function flag(string $detector, int $mineId, string $subject, string $from, string $to, float $score, array $reasons, array $entities): array
    {
        return ['detector' => $detector, 'mine_id' => $mineId, 'subject' => $subject, 'from' => $from, 'to' => $to,
            'score' => $score, 'reasons' => $reasons, 'entities' => $entities];
    }

    /** @param list<array> $flags */
    public static function ordered(array $flags): array
    {
        usort($flags, fn($a, $b) => [$a['mine_id'], $a['subject']] <=> [$b['mine_id'], $b['subject']]);
        return $flags;
    }
}
