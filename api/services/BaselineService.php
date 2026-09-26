<?php

declare(strict_types=1);

namespace app\services;

use app\models\Mine;
use yii\db\Query;

/**
 * How far live scores have drifted from the seeded baseline (PLAN Q14): the simulator's pre-flight.
 * The baseline is the per-mine open-violation count `yii seed` stored in its audit entry.
 * Extra open violations persist until resolved; breaches inside the window age out on their own.
 */
final class BaselineService
{
    public static function check(): array
    {
        $entry = (new Query())->select('new_values')->from('{{%audit_log}}')
            ->where(['entity' => 'seed', 'action' => 'seed'])->orderBy(['id' => SORT_DESC])->limit(1)->scalar();
        $seed = $entry ? json_decode($entry, true) : null;
        $baseline = $seed['baseline_open_violations'] ?? null;

        $mines = Mine::find()->orderBy(['mine.id' => SORT_ASC])->all();
        $current = ComplianceScoreService::scoreMines(array_map(fn(Mine $m) => (int) $m->id, $mines));
        $rows = [];
        foreach ($mines as $mine) {
            $expectedViolations = (int) ($baseline[(string) $mine->id] ?? 0);
            $expected = ComplianceScoreService::compute($expectedViolations, 0);
            $actual = $current[$mine->id];
            $rows[] = [
                'mine_id' => (int) $mine->id, 'code' => $mine->code,
                'expected_score' => $expected->score, 'actual_score' => $actual->score,
                'expected_risk' => $expected->riskLevel, 'actual_risk' => $actual->riskLevel,
                'extra_violations' => $actual->violationCount - $expectedViolations,
                'window_breaches' => $actual->breachCount,
                'is_clean' => $actual->score === $expected->score,
            ];
        }
        return [
            'preset' => $seed['preset'] ?? null,
            'has_baseline' => $baseline !== null,
            'is_clean' => $baseline !== null && !array_filter($rows, fn($r) => !$r['is_clean']),
            'breach_window_hours' => ComplianceScoreService::windowHours(),
            'mines' => $rows,
        ];
    }
}
