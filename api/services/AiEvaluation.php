<?php

declare(strict_types=1);

namespace app\services;

/**
 * Scores the detectors against the data track's labelled scenarios
 * (data/out/<preset>/scenario_expectations.json): positives S1-S7 must be flagged, decoys N1-N3
 * must not. docs/AI_EVALUATION.md holds the table this produces (yii ai/evaluate --write), and
 * tests/unit/AiEvaluationTest.php recomputes it on the seeded demo and compares.
 *
 * A flag matches a scenario when it is at the scenario's mine and its window overlaps the
 * scenario's dates (a day either side); the contractor detector must also name the scenario's
 * contractor, the flatline detector its sensor.
 *   TP  a positive scenario matched by at least one flag of its detector
 *   FN  a positive scenario not matched
 *   FP  a flag of the detector that matches no positive scenario (not necessarily wrong: the
 *       synthetic fleet has unlabelled patterns too - but unrequested)
 *   decoys ignored   a negative scenario with no matching flag
 */
final class AiEvaluation
{
    /** Scenario code prefix -> the detector that should (or, for a decoy, should not) flag it. */
    public const DETECTOR_OF = [
        'S1' => 'repeat_violations', 'S2' => 'production_anomaly', 'S3' => 'sensor_flatline', 'S4' => 'contractor_outlier',
        'S5' => 'night_shift', 'S6' => 'grievance_cluster', 'S7' => 'late_actions',
        'N1' => 'production_anomaly', 'N2' => 'grievance_cluster', 'N3' => 'night_shift',
    ];

    public static function scenarios(string $preset = 'demo'): array
    {
        $dir = (string) \Yii::$app->params['dataOutDir'];
        if (!preg_match('~^([a-zA-Z]:)?[/\\\\]~', $dir)) {
            $dir = \Yii::getAlias('@app') . '/' . $dir;
        }
        return json_decode((string) file_get_contents("$dir/$preset/scenario_expectations.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The data's own "now": the end of the generated window (_manifest.json). */
    public static function asOf(string $preset = 'demo'): \DateTimeImmutable
    {
        $dir = (string) \Yii::$app->params['dataOutDir'];
        if (!preg_match('~^([a-zA-Z]:)?[/\\\\]~', $dir)) {
            $dir = \Yii::getAlias('@app') . '/' . $dir;
        }
        $manifest = json_decode((string) file_get_contents("$dir/$preset/_manifest.json"), true, 512, JSON_THROW_ON_ERROR);
        return new \DateTimeImmutable($manifest['window'][1] . 'T23:59:59Z');
    }

    public static function matches(array $flag, array $scenario): bool
    {
        if ((int) $flag['mine_id'] !== (int) $scenario['mine_id']) {
            return false;
        }
        $signal = $scenario['signal'] ?? [];
        if ($flag['detector'] === 'contractor_outlier') {
            return (int) ($flag['entities']['contractor_id'] ?? 0) === (int) ($signal['contractor_id'] ?? -1);
        }
        if ($flag['detector'] === 'sensor_flatline' && isset($signal['sensor_type']) && ($flag['entities']['sensor_type'] ?? null) !== $signal['sensor_type']) {
            return false;
        }
        if (($flag['from'] ?? '') === '' || ($flag['to'] ?? '') === '') {
            return true;
        }
        $from = strtotime($scenario['date_from'] . 'T00:00:00Z') - 86400;
        $to = strtotime($scenario['date_to'] . 'T23:59:59Z') + 86400;
        return strtotime($flag['from']) <= $to && strtotime($flag['to']) >= $from;
    }

    /**
     * @param array<string, list<array>> $flags per detector
     * @param array<string, string> $engines per detector
     * @return array{detectors: list<array>, scenarios: list<array>}
     */
    public static function score(array $flags, array $engines, array $scenarios): array
    {
        $byDetector = [];
        foreach ($scenarios as $s) {
            $code = substr($s['scenario_code'], 0, 2);
            $detector = self::DETECTOR_OF[$code] ?? null;
            if ($detector !== null) {
                $byDetector[$detector][] = $s + ['short' => $code];
            }
        }
        $rows = $perScenario = [];
        foreach (AnomalyService::PHP as $detector => $_) {
            $list = $flags[$detector] ?? [];
            $pos = array_values(array_filter($byDetector[$detector] ?? [], fn($s) => $s['polarity'] === 'positive'));
            $neg = array_values(array_filter($byDetector[$detector] ?? [], fn($s) => $s['polarity'] === 'negative'));
            $tp = $fn = 0;
            $matched = [];
            foreach ($pos as $s) {
                $hits = array_keys(array_filter($list, fn($f) => self::matches($f, $s)));
                $matched = array_merge($matched, $hits);
                $hits === [] ? $fn++ : $tp++;
                $perScenario[] = ['scenario' => $s['scenario_code'], 'detector' => $detector, 'expected' => 'flag',
                    'result' => $hits === [] ? 'missed' : 'flagged', 'ok' => $hits !== []];
            }
            $fpFlags = array_values(array_diff_key($list, array_flip($matched)));
            $ignored = 0;
            foreach ($neg as $s) {
                $hit = array_filter($list, fn($f) => self::matches($f, $s)) !== [];
                $ignored += $hit ? 0 : 1;
                $perScenario[] = ['scenario' => $s['scenario_code'], 'detector' => $detector, 'expected' => 'no flag',
                    'result' => $hit ? 'flagged' : 'ignored', 'ok' => !$hit];
            }
            $fpMines = array_values(array_unique(array_map(fn($f) => (int) $f['mine_id'], $fpFlags)));
            sort($fpMines);
            $rows[] = ['detector' => $detector, 'engine' => $engines[$detector] ?? 'php',
                'positives' => implode(', ', array_map(fn($s) => $s['short'], $pos)) ?: '-',
                'decoys' => implode(', ', array_map(fn($s) => $s['short'], $neg)) ?: '-',
                'tp' => $tp, 'fn' => $fn, 'fp_flags' => count($fpFlags), 'fp_mines' => count($fpMines), 'fp_mine_ids' => $fpMines,
                'decoys_ignored' => $ignored, 'decoys_total' => count($neg), 'flags' => count($list)];
        }
        usort($perScenario, fn($a, $b) => strcmp($a['scenario'], $b['scenario']));
        return ['detectors' => $rows, 'scenarios' => $perScenario];
    }

    /** The Markdown table written to docs/AI_EVALUATION.md (and compared by the test). */
    public static function markdown(array $result): string
    {
        $out = "| Detector | Scenarios | Flags | True positives | Missed | False positives (flags / mines) | Decoys correctly ignored |\n";
        $out .= "|---|---|---|---|---|---|---|\n";
        foreach ($result['detectors'] as $r) {
            $out .= sprintf("| %s | %s%s | %d | %d / %d | %d | %d / %d | %s |\n", $r['detector'], $r['positives'],
                $r['decoys'] !== '-' ? " (decoy {$r['decoys']})" : '', $r['flags'], $r['tp'], $r['tp'] + $r['fn'], $r['fn'],
                $r['fp_flags'], $r['fp_mines'], $r['decoys_total'] ? "{$r['decoys_ignored']} / {$r['decoys_total']}" : '-');
        }
        $tp = array_sum(array_column($result['detectors'], 'tp'));
        $all = $tp + array_sum(array_column($result['detectors'], 'fn'));
        $ign = array_sum(array_column($result['detectors'], 'decoys_ignored'));
        $dec = array_sum(array_column($result['detectors'], 'decoys_total'));
        $fp = array_sum(array_column($result['detectors'], 'fp_flags'));
        $out .= sprintf("| **All** | | %d | **%d / %d** | %d | %d | **%d / %d** |\n",
            array_sum(array_column($result['detectors'], 'flags')), $tp, $all, $all - $tp, $fp, $ign, $dec);
        return $out;
    }
}
