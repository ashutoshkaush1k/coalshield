<?php

declare(strict_types=1);

namespace app\services;

use app\components\Format;
use app\models\Mine;
use DateTimeImmutable;
use Yii;
use yii\db\Query;

/**
 * Auto-ranked inspection queue, ported from backend/app/services/risk/prioritisation.py and
 * trend.py with the same arithmetic:
 *
 *   urgency = (100 - score) + max(0, recent events - previous events) x WEIGHT_TREND
 *
 * Events are violations (detected_at) plus breached readings (recorded_at) in the last
 * TREND_WINDOW_HOURS against the window before it. Phase 7: the queue is ordered by the
 * Governance Risk Index first (GovernanceRiskService - open violations, breaches, overdue
 * obligations and contractor documents, grievances past SLA, ageing corrective actions, repeat
 * violations); ties: urgency, then severity, then recent events, then mine id - so the queue never
 * reorders between identical requests. Reasons are {code, params} for the frontend to translate.
 */
final class InspectionPriorityService
{
    /**
     * @param Mine[] $mines
     * @return list<array> ranked candidates
     */
    public static function queue(array $mines, ?DateTimeImmutable $now = null, ?\app\models\User $viewer = null): array
    {
        if ($mines === []) {
            return [];
        }
        $ids = array_map(fn(Mine $m) => (int) $m->id, $mines);
        $scores = ComplianceScoreService::scoreMines($ids);
        $trends = self::trends($ids, $now);
        $gri = GovernanceRiskService::forMines($ids, $viewer);
        $weight = (float) Yii::$app->params['priority.weightTrend'];

        $candidates = [];
        foreach ($mines as $mine) {
            $score = $scores[$mine->id];
            $trend = $trends[$mine->id];
            $severity = round(100.0 - $score->score, 1);
            $candidates[] = [
                'mine_id' => (int) $mine->id,
                'code' => $mine->code,
                'name' => $mine->name,
                'district' => $mine->district,
                'state' => $mine->state,
                'region' => $mine->region,
                'urgency' => round($severity + max(0, $trend['delta']) * $weight, 1),
                'severity' => $severity,
                'compliance' => $score->toArray(),
                'governance_risk' => $gri[$mine->id],
                'trend' => $trend,
                'reasons' => [self::griReason($gri[$mine->id]), ...self::reasons($score, $trend)],
            ];
        }
        usort($candidates, fn($a, $b) => [$b['governance_risk']['gri'], $b['urgency'], $b['severity'], $b['trend']['recent_events'], $a['mine_id']]
            <=> [$a['governance_risk']['gri'], $a['urgency'], $a['severity'], $a['trend']['recent_events'], $b['mine_id']]);
        foreach ($candidates as $i => &$candidate) {
            $candidate = ['rank' => $i + 1] + $candidate;
        }
        return $candidates;
    }

    /** @return array<int, array> trend signal per mine */
    public static function trends(array $mineIds, ?DateTimeImmutable $now = null): array
    {
        $hours = (int) Yii::$app->params['priority.trendWindowHours'];
        $now ??= Format::now();
        $recentStart = $now->modify("-{$hours} hours");
        $previousStart = $now->modify('-' . ($hours * 2) . ' hours');

        $count = function (string $table, string $column, DateTimeImmutable $from, DateTimeImmutable $to, array $extra = []) use ($mineIds): array {
            return array_map('intval', (new Query())->select(['n' => 'count(*)', 'mine_id'])->from($table)
                ->where(['mine_id' => $mineIds])->andWhere($extra)
                ->andWhere(['>=', $column, Format::sql($from)])->andWhere(['<', $column, Format::sql($to)])
                ->groupBy('mine_id')->indexBy('mine_id')->column());
        };
        $recentV = $count('{{%violation}}', 'detected_at', $recentStart, $now);
        $previousV = $count('{{%violation}}', 'detected_at', $previousStart, $recentStart);
        $recentB = $count('{{%sensor_reading}}', 'recorded_at', $recentStart, $now, ['breached' => true]);
        $previousB = $count('{{%sensor_reading}}', 'recorded_at', $previousStart, $recentStart, ['breached' => true]);

        $out = [];
        foreach ($mineIds as $id) {
            $recent = ($recentV[$id] ?? 0) + ($recentB[$id] ?? 0);
            $previous = ($previousV[$id] ?? 0) + ($previousB[$id] ?? 0);
            $delta = $recent - $previous;
            $out[$id] = [
                'window_hours' => $hours,
                'recent_violations' => $recentV[$id] ?? 0,
                'recent_breaches' => $recentB[$id] ?? 0,
                'recent_events' => $recent,
                'previous_events' => $previous,
                'delta' => $delta,
                'direction' => $delta > 0 ? 'rising' : ($delta < 0 ? 'falling' : 'steady'),
            ];
        }
        return $out;
    }

    /** The index and its largest component, e.g. "Governance Risk Index 47 (high): 11 open violations". */
    private static function griReason(array $gri): array
    {
        $top = $gri['components'];
        usort($top, fn($a, $b) => [$b['value'], $a['key']] <=> [$a['value'], $b['key']]);
        return ['code' => 'PRIORITY_GRI', 'params' => ['gri' => $gri['gri'], 'band' => $gri['band'],
            'component' => $top[0]['key'], 'count' => $top[0]['count'], 'multiplier' => $gri['multiplier']]];
    }

    /** @return list<array{code: string, params: array}> */
    private static function reasons(ComplianceResult $score, array $trend): array
    {
        $hours = $trend['window_hours'];
        $out = [['code' => 'PRIORITY_RISK_BAND', 'params' => ['risk_level' => $score->riskLevel, 'score' => $score->score]]];
        if ($trend['direction'] === 'rising') {
            $out[] = ['code' => 'PRIORITY_TREND_RISING', 'params' => ['recent' => $trend['recent_events'], 'previous' => $trend['previous_events'], 'hours' => $hours]];
        } elseif ($trend['direction'] === 'falling') {
            $out[] = ['code' => 'PRIORITY_TREND_FALLING', 'params' => ['recent' => $trend['recent_events'], 'previous' => $trend['previous_events'], 'hours' => $hours]];
        }
        if ($trend['recent_violations']) {
            $out[] = ['code' => 'PRIORITY_RECENT_VIOLATIONS', 'params' => ['count' => $trend['recent_violations'], 'hours' => $hours]];
        }
        if ($trend['recent_breaches']) {
            $out[] = ['code' => 'PRIORITY_RECENT_BREACHES', 'params' => ['count' => $trend['recent_breaches'], 'hours' => $hours]];
        }
        if (!$trend['recent_events']) {
            $out[] = ['code' => 'PRIORITY_NO_RECENT_EVENTS', 'params' => ['hours' => $hours]];
        }
        return $out;
    }
}
