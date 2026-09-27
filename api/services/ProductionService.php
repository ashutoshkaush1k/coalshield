<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\components\Format;
use app\components\Rules;
use app\components\StatusTransition;
use app\models\DailyProduction;
use app\models\Mine;
use app\models\ProductionDetailRequest;
use app\models\ProductionEditLog;
use app\models\User;
use Yii;
use yii\db\Query;

/**
 * Production reporting (brief Phase 4): the mine head's daily entries (draft, submit, edit with a
 * reason once submitted), the numbers-only summary for multi-mine roles, chart series, and the
 * PHP anomaly detector (the ai-service takes over in Phase 7).
 *
 * Anomaly (rules.yaml product.production_anomaly; product settings, not law), per mine and day on
 * the day's total over its shifts:
 *   OVER_TARGET        coal output above the day's target by more than over_target_pct
 *   ROLLING_MEAN_SPIKE the output is at least `ratio` x the mean of the mine's producing days in
 *   ROLLING_MEAN_DROP  the previous `rolling_days` days (or at most 1/ratio x), `z` standard
 *                      deviations or more away - and so is the output-to-target ratio, so a rise
 *                      the target explains (a revised target, a new month's plan) is not flagged.
 *                      Needs `min_baseline_days` producing days in the window.
 * Scored against data/out/demo/scenario_expectations.json (S2 flagged; N1, N3 not) in
 * tests/unit/ProductionAnomalyTest.php.
 */
final class ProductionService
{
    /** A mine head's new draft for its own mine. */
    public static function create(User $user, array $data): DailyProduction
    {
        $entry = new DailyProduction(['mine_id' => $user->mine_id, 'status' => DailyProduction::STATUS_DRAFT]);
        $entry->setAttributes(self::only($data, ['date', 'shift', ...DailyProduction::EDITABLE]), false);
        if (($entry->date ?? '') > self::today()) {
            throw ApiException::fields(['date' => ['IN_FUTURE']]);
        }
        if (!$entry->save()) {
            throw ApiException::validation($entry);
        }
        return $entry;
    }

    /** Change a draft freely. A submitted or locked entry needs editWithReason(). */
    public static function updateDraft(DailyProduction $entry, array $data): DailyProduction
    {
        if (!$entry->isDraft()) {
            throw new ApiException(422, 'ENTRY_LOCKED', ['status' => $entry->status]);
        }
        $entry->setAttributes(self::only($data, DailyProduction::EDITABLE), false);
        if (!$entry->save()) {
            throw ApiException::validation($entry);
        }
        return $entry;
    }

    public static function submit(DailyProduction $entry, User $user): DailyProduction
    {
        if (!$entry->isDraft()) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $entry->status, 'to' => DailyProduction::STATUS_SUBMITTED]);
        }
        $entry->submitted_by = $user->id;
        $entry->submitted_at = Format::sql(Format::now());
        StatusTransition::apply($entry, DailyProduction::STATUS_SUBMITTED);
        return $entry;
    }

    /**
     * Correct a submitted or locked entry: every changed field gets a production_edit_log row with
     * the reason, in the same transaction as the change (and the audit chain entry).
     */
    public static function editWithReason(DailyProduction $entry, array $data, string $reason, User $user): DailyProduction
    {
        if ($entry->isDraft()) {
            return self::updateDraft($entry, $data);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ApiException::fields(['reason' => [$reason === '' ? 'REASON_REQUIRED' : 'TOO_SHORT']]);
        }
        if (mb_strlen($reason) > 500) {
            throw ApiException::fields(['reason' => ['TOO_LONG']]);
        }
        $before = $entry->getAttributes(DailyProduction::EDITABLE);
        $entry->setAttributes(self::only($data, DailyProduction::EDITABLE), false);
        if (!$entry->validate()) {
            throw ApiException::validation($entry);
        }
        $changed = [];
        foreach (DailyProduction::EDITABLE as $field) {
            if (array_key_exists($field, $data) && self::text($before[$field]) !== self::text($entry->$field)) {
                $changed[$field] = [self::text($before[$field]), self::text($entry->$field)];
            }
        }
        if ($changed === []) {
            throw new ApiException(422, 'NOTHING_CHANGED');
        }
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $entry->save(false);
            foreach ($changed as $field => [$old, $new]) {
                $log = new ProductionEditLog([
                    'production_id' => $entry->id, 'field' => $field, 'old_value' => $old, 'new_value' => $new,
                    'reason' => $reason, 'edited_by' => $user->id, 'edited_at' => Format::sql(Format::now()),
                ]);
                $log->save(false);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $entry;
    }

    public static function deleteDraft(DailyProduction $entry): void
    {
        if (!$entry->isDraft()) {
            throw new ApiException(422, 'ENTRY_LOCKED', ['status' => $entry->status]);
        }
        $entry->delete();
    }

    /** Close the reporting period: submitted entries older than the lock window become locked. */
    public static function lockPastPeriods(?string $today = null): int
    {
        $days = (int) Rules::value('product', 'production_lock_after_days');
        $cutoff = (new \DateTimeImmutable($today ?? self::today()))->modify("-{$days} days")->format('Y-m-d');
        $count = 0;
        foreach (DailyProduction::find()->where(['status' => DailyProduction::STATUS_SUBMITTED])->andWhere(['<', 'date', $cutoff])->each() as $entry) {
            StatusTransition::apply($entry, DailyProduction::STATUS_LOCKED, ['code' => 'PERIOD_CLOSED', 'after_days' => $days]);
            $count++;
        }
        return $count;
    }

    /**
     * Numbers only (brief Phase 4): per mine, the day's and the month-to-date target, actual and
     * achievement, the anomaly flag for the month so far, and the latest detail request.
     * @param Mine[] $mines
     */
    public static function summary(array $mines, ?string $date = null): array
    {
        $date ??= self::today();
        $monthStart = substr($date, 0, 8) . '01';
        $ids = array_map(fn(Mine $m) => (int) $m->id, $mines);
        $totals = self::dailyTotals($ids, $monthStart, $date);
        $anomalies = self::anomalies($ids, $monthStart, $date);
        $requests = [];
        if ($ids !== []) {
            foreach (ProductionDetailRequest::find()->where(['mine_id' => $ids])->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->all() as $r) {
                $requests[(int) $r->mine_id] ??= $r;
            }
        }
        $rows = [];
        $fleet = ['today' => [0.0, 0.0], 'mtd' => [0.0, 0.0]];
        foreach ($mines as $mine) {
            $days = $totals[(int) $mine->id] ?? [];
            $today = $days[$date] ?? null;
            $mtdTarget = array_sum(array_column($days, 'target'));
            $mtdActual = array_sum(array_column($days, 'actual'));
            $flags = $anomalies[(int) $mine->id] ?? [];
            $request = $requests[(int) $mine->id] ?? null;
            $rows[] = [
                'mine_id' => (int) $mine->id, 'code' => $mine->code, 'name' => $mine->name, 'state' => $mine->state,
                'today' => [
                    'target_t' => $today ? round($today['target'], 1) : null,
                    'actual_t' => $today ? round($today['actual'], 1) : null,
                    'achievement_pct' => $today ? self::pct($today['actual'], $today['target']) : null,
                    'shifts_reported' => $today ? $today['shifts'] : 0,
                ],
                'mtd' => [
                    'target_t' => round($mtdTarget, 1), 'actual_t' => round($mtdActual, 1),
                    'achievement_pct' => self::pct($mtdActual, $mtdTarget),
                    'days_reported' => count($days),
                ],
                'last_reported' => $days === [] ? null : max(array_keys($days)),
                'anomaly' => [
                    'flagged' => $flags !== [],
                    'days' => array_map(fn($d, $f) => ['date' => $d] + $f, array_keys($flags), array_values($flags)),
                ],
                'request' => $request === null ? null : [
                    'id' => (int) $request->id, 'status' => $request->status, 'date_from' => $request->date_from,
                    'date_to' => $request->date_to, 'due_at' => Format::utc($request->due_at),
                ],
            ];
            $fleet['today'][0] += $today['target'] ?? 0;
            $fleet['today'][1] += $today['actual'] ?? 0;
            $fleet['mtd'][0] += $mtdTarget;
            $fleet['mtd'][1] += $mtdActual;
        }
        usort($rows, fn($a, $b) => [$b['anomaly']['flagged'], $a['mtd']['achievement_pct'] ?? 999, $a['mine_id']]
            <=> [$a['anomaly']['flagged'], $b['mtd']['achievement_pct'] ?? 999, $b['mine_id']]);
        return [
            'date' => $date,
            'month_start' => $monthStart,
            'totals' => [
                'today' => ['target_t' => round($fleet['today'][0], 1), 'actual_t' => round($fleet['today'][1], 1), 'achievement_pct' => self::pct($fleet['today'][1], $fleet['today'][0])],
                'mtd' => ['target_t' => round($fleet['mtd'][0], 1), 'actual_t' => round($fleet['mtd'][1], 1), 'achievement_pct' => self::pct($fleet['mtd'][1], $fleet['mtd'][0])],
            ],
            'flagged' => count(array_filter($rows, fn($r) => $r['anomaly']['flagged'])),
            'mines' => $rows,
            'is_demo_value' => true,
        ];
    }

    /**
     * Chart series for one mine and date range: target vs actual per day, the cumulative totals,
     * the split by shift, and the anomaly days.
     */
    public static function charts(int $mineId, string $from, string $to): array
    {
        $days = self::dailyTotals([$mineId], $from, $to)[$mineId] ?? [];
        ksort($days);
        $daily = [];
        $cumTarget = $cumActual = 0.0;
        foreach ($days as $date => $d) {
            $cumTarget += $d['target'];
            $cumActual += $d['actual'];
            $daily[] = ['date' => $date, 'target_t' => round($d['target'], 1), 'actual_t' => round($d['actual'], 1),
                'cumulative_target_t' => round($cumTarget, 1), 'cumulative_actual_t' => round($cumActual, 1)];
        }
        $shifts = [];
        foreach ((new Query())->select(['shift', 'target' => 'sum(coal_target_t)', 'actual' => 'sum(coal_actual_t)',
            'breakdown' => 'sum(breakdown_hours)', 'manpower' => 'avg(manpower_present)'])
            ->from('{{%daily_production}}')->where(['mine_id' => $mineId])->andWhere(['between', 'date', $from, $to])
            ->groupBy('shift')->orderBy('shift')->all() as $s) {
            $shifts[] = ['shift' => $s['shift'], 'target_t' => round((float) $s['target'], 1), 'actual_t' => round((float) $s['actual'], 1),
                'breakdown_hours' => round((float) $s['breakdown'], 1), 'average_manpower' => (int) round((float) $s['manpower'])];
        }
        $flags = self::anomalies([$mineId], $from, $to)[$mineId] ?? [];
        return [
            'mine_id' => $mineId, 'from' => $from, 'to' => $to,
            'daily' => $daily,
            'shifts' => $shifts,
            'anomalies' => array_map(fn($d, $f) => ['date' => $d] + $f, array_keys($flags), array_values($flags)),
            'is_demo_value' => true,
        ];
    }

    /**
     * Flagged days per mine within [$from, $to].
     * @param int[] $mineIds
     * @return array<int, array<string, array{direction: string, reasons: list<array{code: string, params: array}>}>>
     */
    public static function anomalies(array $mineIds, string $from, string $to): array
    {
        if ($mineIds === []) {
            return [];
        }
        $cfg = Rules::value('product', 'production_anomaly');
        $overTarget = (float) $cfg['over_target_pct'] / 100;
        $window = (int) $cfg['rolling_days'];
        $minBase = (int) $cfg['min_baseline_days'];
        $ratio = (float) $cfg['ratio'];
        $z = (float) $cfg['z'];
        $start = (new \DateTimeImmutable($from))->modify("-{$window} days")->format('Y-m-d');

        $out = [];
        foreach (self::dailyTotals($mineIds, $start, $to) as $mineId => $days) {
            ksort($days);
            $dates = array_keys($days);
            foreach ($dates as $i => $date) {
                if ($date < $from || $days[$date]['target'] <= 0) {
                    continue;
                }
                $actual = $days[$date]['actual'];
                $target = $days[$date]['target'];
                $reasons = [];
                $direction = null;
                if ($actual > $target * (1 + $overTarget)) {
                    $direction = 'spike';
                    $reasons[] = ['code' => 'OVER_TARGET', 'params' => ['actual_t' => round($actual, 1), 'target_t' => round($target, 1),
                        'achievement_pct' => self::pct($actual, $target), 'threshold_pct' => (int) $cfg['over_target_pct']]];
                }
                // The window: producing days (output and target above zero) in the previous $window days.
                $windowStart = (new \DateTimeImmutable($date))->modify("-{$window} days")->format('Y-m-d');
                $base = [];
                for ($j = $i - 1; $j >= 0 && $dates[$j] >= $windowStart; $j--) {
                    $d = $days[$dates[$j]];
                    if ($d['actual'] > 0 && $d['target'] > 0) {
                        $base[] = $d;
                    }
                }
                if (count($base) >= $minBase) {
                    [$r1, $z1, $mean] = self::deviation($actual, array_column($base, 'actual'));
                    [$r2, $z2] = self::deviation($actual / $target, array_map(fn($d) => $d['actual'] / $d['target'], $base));
                    $params = ['actual_t' => round($actual, 1), 'rolling_mean_t' => round($mean, 1), 'ratio' => round($r1, 2),
                        'z' => round($z1, 1), 'days' => count($base)];
                    if ($r1 >= $ratio && $z1 >= $z && $r2 >= $ratio && $z2 >= $z) {
                        $direction = 'spike';
                        $reasons[] = ['code' => 'ROLLING_MEAN_SPIKE', 'params' => $params];
                    } elseif ($r1 <= 1 / $ratio && $z1 <= -$z && $r2 <= 1 / $ratio && $z2 <= -$z) {
                        $direction ??= 'drop';
                        $reasons[] = ['code' => 'ROLLING_MEAN_DROP', 'params' => $params];
                    }
                }
                if ($reasons !== []) {
                    $out[$mineId][$date] = ['direction' => $direction, 'reasons' => $reasons];
                }
            }
        }
        return $out;
    }

    /**
     * Day totals over the shifts reported: [mine id][date] => target, actual, shifts.
     * @param int[] $mineIds
     */
    public static function dailyTotals(array $mineIds, string $from, string $to): array
    {
        if ($mineIds === []) {
            return [];
        }
        $out = [];
        foreach ((new Query())->select(['mine_id', 'date', 'target' => 'sum(coal_target_t)', 'actual' => 'sum(coal_actual_t)', 'shifts' => 'count(*)'])
            ->from('{{%daily_production}}')->where(['mine_id' => $mineIds])->andWhere(['between', 'date', $from, $to])
            ->groupBy(['mine_id', 'date'])->all() as $row) {
            $out[(int) $row['mine_id']][$row['date']] = ['target' => (float) $row['target'], 'actual' => (float) $row['actual'], 'shifts' => (int) $row['shifts']];
        }
        return $out;
    }

    public static function today(): string
    {
        return Format::now()->format('Y-m-d');
    }

    /** @return array{0: float, 1: float, 2: float} ratio to the mean, z-score, mean */
    private static function deviation(float $value, array $base): array
    {
        $mean = array_sum($base) / count($base);
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $base)) / count($base);
        $sd = sqrt($variance);
        return [$mean > 0 ? $value / $mean : INF, $sd > 0 ? ($value - $mean) / $sd : ($value == $mean ? 0.0 : INF * ($value <=> $mean)), $mean];
    }

    private static function pct(float $actual, float $target): ?float
    {
        return $target > 0 ? round(100 * $actual / $target, 1) : null;
    }

    private static function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') : (string) $value;
    }
}
