<?php

declare(strict_types=1);

namespace app\services;

use app\components\Format;
use DateTimeImmutable;
use Yii;
use yii\db\Query;

/**
 * Compliance score (PRD 6.1), ported from the FastAPI prototype's app/services/compliance/scoring.py with the same
 * numbers:
 *
 *   score = 100 - (open violations x WEIGHT_PPE + in-window breaches x WEIGHT_ENV), clamped 0..100,
 *   rounded to 0.1; band low >= 80, medium >= 50, else high (from the rounded score).
 *
 * Open violations count until resolved. Breaches (breached and not resolved) count only while
 * younger than BREACH_WINDOW_HOURS, so a mine recovers on its own once its sensors run clean.
 * Always recomputed from current rows, never incremented. The scores are demo values computed from
 * synthetic data (data/DATASETS.md), not safety assessments.
 */
final class ComplianceScoreService
{
    public static function compute(int $violationCount, int $breachCount): ComplianceResult
    {
        $params = Yii::$app->params;
        $weightPpe = (float) $params['score.weightPpe'];
        $weightEnv = (float) $params['score.weightEnv'];
        $violationPenalty = $violationCount * $weightPpe;
        $environmentalPenalty = $breachCount * $weightEnv;
        $raw = 100.0 - ($violationPenalty + $environmentalPenalty);
        $score = round(min(100.0, max(0.0, $raw)), 1);
        return new ComplianceResult(
            $score, self::riskLevel($score), $violationCount, $breachCount,
            $violationPenalty, $environmentalPenalty, round($raw, 1), $weightPpe, $weightEnv, self::windowHours(),
        );
    }

    public static function riskLevel(float $score): string
    {
        $params = Yii::$app->params;
        if ($score >= $params['score.bandLowMin']) {
            return 'low';
        }
        return $score >= $params['score.bandMediumMin'] ? 'medium' : 'high';
    }

    /** Breach window in hours, or null when every breach on record counts. */
    public static function windowHours(): ?float
    {
        $hours = (float) Yii::$app->params['score.breachWindowHours'];
        return $hours > 0 ? $hours : null;
    }

    public static function windowStart(?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $hours = self::windowHours();
        if ($hours === null) {
            return null;
        }
        $now ??= Format::now();
        return $now->modify(sprintf('-%d microseconds', (int) round($hours * 3600 * 1_000_000)));
    }

    /**
     * @param int[] $mineIds
     * @return array<int, ComplianceResult> keyed by mine id, in the given order
     */
    public static function scoreMines(array $mineIds, ?DateTimeImmutable $now = null): array
    {
        if ($mineIds === []) {
            return [];
        }
        $violations = (new Query())->select(['n' => 'count(*)', 'mine_id'])->from('{{%violation}}')
            ->where(['mine_id' => $mineIds, 'resolved' => false])->groupBy('mine_id')
            ->indexBy('mine_id')->column();

        $breachQuery = (new Query())->select(['n' => 'count(*)', 'mine_id'])->from('{{%sensor_reading}}')
            ->where(['mine_id' => $mineIds, 'breached' => true, 'resolved' => false])->groupBy('mine_id')->indexBy('mine_id');
        $since = self::windowStart($now);
        if ($since !== null) {
            $breachQuery->andWhere(['>=', 'recorded_at', Format::sql($since)]);
            if ($now !== null) {
                $breachQuery->andWhere(['<=', 'recorded_at', Format::sql($now)]);
            }
        }
        $breaches = $breachQuery->column();

        $out = [];
        foreach ($mineIds as $id) {
            $out[(int) $id] = self::compute((int) ($violations[$id] ?? 0), (int) ($breaches[$id] ?? 0));
        }
        return $out;
    }

    public static function scoreMine(int $mineId, ?DateTimeImmutable $now = null): ComplianceResult
    {
        return self::scoreMines([$mineId], $now)[$mineId];
    }

    /** Headline numbers across a set of results (average rounded to 0.1). */
    public static function fleetStats(array $results): array
    {
        $scores = array_map(fn(ComplianceResult $r) => $r->score, $results);
        $levels = array_count_values(array_map(fn(ComplianceResult $r) => $r->riskLevel, $results));
        return [
            'mine_count' => count($results),
            'average_score' => $scores ? round(array_sum($scores) / count($scores), 1) : 0.0,
            'high_risk_count' => $levels['high'] ?? 0,
            'medium_risk_count' => $levels['medium'] ?? 0,
            'low_risk_count' => $levels['low'] ?? 0,
            'total_violations' => array_sum(array_map(fn($r) => $r->violationCount, $results)),
            'total_breaches' => array_sum(array_map(fn($r) => $r->breachCount, $results)),
            'breach_window_hours' => self::windowHours(),
        ];
    }

    /** Append a history point (the trend line). Never updated, so retuning keeps the past. */
    public static function record(int $mineId, ComplianceResult $result): void
    {
        Yii::$app->db->createCommand()->insert('{{%compliance_score}}', [
            'mine_id' => $mineId,
            'score' => $result->score,
            'risk_level' => $result->riskLevel,
            'violation_count' => $result->violationCount,
            'breach_count' => $result->breachCount,
            'weight_ppe' => $result->weightPpe,
            'weight_env' => $result->weightEnv,
            'computed_at' => Format::sql(Format::now()),
        ])->execute();
    }

    /** Record a point only when the score differs from the mine's newest recorded point. */
    public static function recordIfMoved(int $mineId, ComplianceResult $result, ?ComplianceResult $before = null): bool
    {
        $last = (new Query())->select('score')->from('{{%compliance_score}}')
            ->where(['mine_id' => $mineId])->orderBy(['id' => SORT_DESC])->limit(1)->scalar();
        $reference = $last !== false ? (float) $last : $before?->score;
        if ($reference !== null && abs($reference - $result->score) < 0.05) {
            return false;
        }
        self::record($mineId, $result);
        return true;
    }

    /** Open (not resolved) alerts per mine. */
    public static function openAlertCounts(array $mineIds): array
    {
        if ($mineIds === []) {
            return [];
        }
        $query = (new Query())->select(['n' => 'count(*)', 'mine_id'])->from('{{%alert}}')
            ->where(['mine_id' => $mineIds, 'status' => 'open']);
        // The count must match the list the caller can open: a mine head does not see the alerts
        // of sensitive grievances (AccessRule), so they are not counted for it either.
        $identity = \Yii::$app->has('user', true) ? \Yii::$app->user->identity : null;
        if ($identity instanceof \app\models\User && !\app\components\AccessRule::seesSensitiveGrievances($identity)) {
            $query->andWhere(['not', ['and', ['entity_type' => 'grievance'], ['in', 'entity_id', \app\components\AccessRule::sensitiveGrievanceIds()]]]);
        }
        return array_map('intval', $query->groupBy('mine_id')->indexBy('mine_id')->column());
    }
}
