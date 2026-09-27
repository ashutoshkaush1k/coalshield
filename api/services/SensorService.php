<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\components\AuditChain;
use app\components\Format;
use app\components\Rules;
use app\models\Alert;
use app\models\Mine;
use DateTimeImmutable;
use Yii;
use yii\db\Query;

/**
 * Sensor readings: ingest, fleet standing, breach buckets and trend series. Ported from
 * backend/app/services/iot/*, with the prototype's 50 / 10 / 45 thresholds replaced by the legal
 * limits in data/schema/rules.yaml (HANDOFF item 6):
 *   ch4 > 1.25 %, ch4_return_air > 0.75 % (SAF-11), wet-bulb temperature > 33.5 degC (HLT-05):
 *   instantaneous; dust > 2.0 mg/m3 as an 8-hour rolling mean (HLT-04);
 *   co (TODO-VERIFY) and humidity have no limit, so `breached` stays null for them.
 */
final class SensorService
{
    public const MAX_BATCH = 2000;

    /** Whether a reading breaches, given the rule; null when the sensor has no legal limit. */
    public static function isBreach(string $type, float $value, ?float $rollingMean = null): ?bool
    {
        $rule = Rules::sensor($type);
        if ($rule === null || $rule['limit'] === null) {
            return null;
        }
        $compared = $rule['compare'] === 'rolling_8h_mean' && $rollingMean !== null ? $rollingMean : $value;
        return $compared > $rule['limit'];
    }

    /** low / medium / high by how far past the limit (same ratios as the prototype). */
    public static function severityForMargin(string $type, float $value): string
    {
        $limit = Rules::sensor($type)['limit'] ?? null;
        if (!$limit) {
            return 'low';
        }
        $ratio = max(0.0, $value - $limit) / $limit;
        return $ratio >= 0.30 ? 'high' : ($ratio >= 0.10 ? 'medium' : 'low');
    }

    /**
     * Store a batch of readings stamped now (or recorded_at when given), raise an alert per breach
     * and report each mine's score before and after. One transaction; one audit entry per batch.
     *
     * @param list<array{mine_id?: int, mine_code?: string, sensor_type: string, value: float|int|string, recorded_at?: string}> $readings
     */
    public static function ingest(array $readings, string $source, ?DateTimeImmutable $now = null): array
    {
        if ($readings === [] || count($readings) > self::MAX_BATCH) {
            throw ApiException::fields(['readings' => [$readings === [] ? 'REQUIRED' : 'TOO_MANY']]);
        }
        $now ??= Format::now();
        $rules = Rules::sensors();
        $mineIdByCode = (new Query())->select(['id', 'code'])->from('{{%mine}}')->indexBy('code')->column();
        $validIds = array_flip(array_map('intval', $mineIdByCode));

        $rows = [];
        $errors = [];
        foreach ($readings as $i => $r) {
            $mineId = isset($r['mine_id']) ? (int) $r['mine_id'] : (int) ($mineIdByCode[$r['mine_code'] ?? ''] ?? 0);
            $type = (string) ($r['sensor_type'] ?? '');
            $value = $r['value'] ?? null;
            if (!isset($validIds[$mineId])) {
                $errors["readings[$i].mine_id"] = ['NOT_FOUND'];
            } elseif (!isset($rules[$type])) {
                $errors["readings[$i].sensor_type"] = ['INVALID_VALUE'];
            } elseif (!is_numeric($value)) {
                $errors["readings[$i].value"] = ['INVALID_VALUE'];
            } else {
                $at = $now;
                if (!empty($r['recorded_at'])) {
                    $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', (string) $r['recorded_at'], new \DateTimeZone('UTC'));
                    if ($at === false) {
                        $errors["readings[$i].recorded_at"] = ['INVALID_DATETIME'];
                        continue;
                    }
                }
                $rows[] = ['mine_id' => $mineId, 'sensor_type' => $type, 'value' => (float) $value, 'recorded_at' => $at];
            }
        }
        if ($errors) {
            throw ApiException::fields($errors);
        }

        $mineIds = array_values(array_unique(array_column($rows, 'mine_id')));
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $before = ComplianceScoreService::scoreMines($mineIds, $now);
            $stored = 0;
            $breaches = [];
            foreach ($rows as $row) {
                $rule = $rules[$row['sensor_type']];
                $mean = null;
                if ($rule['compare'] === 'rolling_8h_mean' && $rule['limit'] !== null) {
                    $window = (new Query())->select(['s' => 'coalesce(sum(value), 0)', 'n' => 'count(*)'])->from('{{%sensor_reading}}')
                        ->where(['mine_id' => $row['mine_id'], 'sensor_type' => $row['sensor_type']])
                        ->andWhere(['>', 'recorded_at', Format::sql($row['recorded_at']->modify('-8 hours'))])
                        ->andWhere(['<=', 'recorded_at', Format::sql($row['recorded_at'])])
                        ->one();
                    $mean = ((float) $window['s'] + $row['value']) / ((int) $window['n'] + 1);
                }
                $breached = self::isBreach($row['sensor_type'], $row['value'], $mean);
                $id = (int) $db->createCommand(
                    'INSERT INTO sensor_reading (mine_id, sensor_type, value, unit, recorded_at, breached, resolved)
                     VALUES (:m, :t, :v, :u, :at, :b, false) RETURNING id',
                    [':m' => $row['mine_id'], ':t' => $row['sensor_type'], ':v' => $row['value'], ':u' => $rule['unit'],
                     ':at' => Format::sql($row['recorded_at']), ':b' => $breached],
                )->queryScalar();
                $stored++;
                if ($breached) {
                    $breaches[] = AlertService::forBreach($id, $row['mine_id'], $row['sensor_type'], $row['value'], $mean);
                }
            }
            $after = ComplianceScoreService::scoreMines($mineIds, $now);
            $mines = [];
            foreach ($mineIds as $mineId) {
                ComplianceScoreService::recordIfMoved($mineId, $after[$mineId], $before[$mineId]);
                $mines[] = [
                    'mine_id' => $mineId,
                    'score_before' => $before[$mineId]->score,
                    'score_after' => $after[$mineId]->score,
                    'risk_before' => $before[$mineId]->riskLevel,
                    'risk_after' => $after[$mineId]->riskLevel,
                    'breaches' => count(array_filter($breaches, fn($b) => $b['mine_id'] === $mineId)),
                ];
            }
            AuditChain::append('sensor_reading', null, 'ingest', null, [
                'source' => $source, 'readings' => $stored, 'breaches' => count($breaches), 'mines' => count($mineIds),
            ]);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return ['stored' => $stored, 'breaches' => $breaches, 'mines' => $mines];
    }

    /** Latest reading per sensor type for each mine, worst mine first (Government risk view). */
    public static function fleetStanding(array $mines): array
    {
        if ($mines === []) {
            return [];
        }
        $ids = array_map(fn(Mine $m) => (int) $m->id, $mines);
        $latest = [];
        foreach (Yii::$app->db->createCommand(
            'SELECT DISTINCT ON (mine_id, sensor_type) mine_id, sensor_type, value, unit, recorded_at, breached
               FROM sensor_reading WHERE mine_id = ANY(:ids)
              ORDER BY mine_id, sensor_type, recorded_at DESC, id DESC',
            [':ids' => '{' . implode(',', $ids) . '}'],
        )->queryAll() as $row) {
            $latest[$row['mine_id']][$row['sensor_type']] = $row;
        }
        $open = [];
        foreach ((new Query())->select(['mine_id', 'sensor_type', 'n' => 'count(*)'])->from('{{%sensor_reading}}')
            ->where(['mine_id' => $ids, 'breached' => true, 'resolved' => false])
            ->groupBy(['mine_id', 'sensor_type'])->all() as $row) {
            $open[$row['mine_id']][$row['sensor_type']] = (int) $row['n'];
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2, 'ok' => 3];
        $out = [];
        foreach ($mines as $mine) {
            $sensors = [];
            foreach (Rules::sensors() as $type => $rule) {
                $reading = $latest[$mine->id][$type] ?? null;
                if ($reading === null && !isset($open[$mine->id][$type])) {
                    continue;   // opencast mines have no gas sensors
                }
                $value = $reading ? (float) $reading['value'] : null;
                $breached = (bool) ($reading['breached'] ?? false);
                $limit = $rule['limit'];
                $margin = $value !== null && $limit !== null ? max(0.0, $value - $limit) : 0.0;
                $severity = $breached ? self::severityForMargin($type, $value) : 'ok';
                $status = match (true) {
                    $value === null => 'NO_READINGS',
                    $limit === null => 'NO_LEGAL_LIMIT',
                    $breached => 'BREACHED',
                    $value >= $limit * 0.9 => 'APPROACHING_LIMIT',
                    default => 'WITHIN_RANGE',
                };
                $sensors[] = [
                    'sensor_type' => $type, 'unit' => $rule['unit'], 'threshold' => $limit,
                    'compare' => $rule['compare'], 'obligation' => $rule['obligation'],
                    'value' => $value, 'recorded_at' => Format::utc($reading['recorded_at'] ?? null),
                    'breached' => $breached, 'margin' => round($margin, 3), 'severity' => $severity,
                    'status_code' => $status, 'open_breaches' => $open[$mine->id][$type] ?? 0,
                ];
            }
            $worst = 'ok';
            foreach ($sensors as $s) {
                if ($order[$s['severity']] < $order[$worst]) {
                    $worst = $s['severity'];
                }
            }
            $out[] = [
                'mine_id' => (int) $mine->id, 'code' => $mine->code, 'name' => $mine->name,
                'district' => $mine->district, 'state' => $mine->state,
                'worst_severity' => $worst,
                'breaching_now' => count(array_filter($sensors, fn($s) => $s['breached'])),
                'total_open_breaches' => array_sum(array_column($sensors, 'open_breaches')),
                'sensors' => $sensors,
            ];
        }
        usort($out, fn($a, $b) => [$order[$a['worst_severity']], -$a['breaching_now'], -$a['total_open_breaches'], $a['mine_id']]
            <=> [$order[$b['worst_severity']], -$b['breaching_now'], -$b['total_open_breaches'], $b['mine_id']]);
        return $out;
    }

    /** Breach counts over time in fixed buckets, split into gas / dust / temperature. */
    public static function breachBuckets(array $mineIds): array
    {
        if ($mineIds === []) {
            return [];
        }
        $size = (int) Yii::$app->params['sensor.breachBucketHours'] * 3600;
        $rows = Yii::$app->db->createCommand(
            'SELECT (floor(extract(epoch FROM recorded_at) / :size) * :size)::bigint AS bucket, sensor_type, count(*) AS n
               FROM sensor_reading
              WHERE breached AND NOT resolved AND mine_id = ANY(:ids)
              GROUP BY 1, 2',
            [':size' => $size, ':ids' => '{' . implode(',', array_map('intval', $mineIds)) . '}'],
        )->queryAll();
        if ($rows === []) {
            return [];
        }
        $starts = array_map(fn($r) => (int) $r['bucket'], $rows);
        $buckets = [];
        for ($t = min($starts); $t <= max($starts); $t += $size) {
            $buckets[$t] = ['gas' => 0, 'dust' => 0, 'temperature' => 0];
        }
        foreach ($rows as $row) {
            $category = Rules::SENSOR_CATEGORY[$row['sensor_type']] ?? null;
            if (isset($buckets[(int) $row['bucket']][$category])) {
                $buckets[(int) $row['bucket']][$category] += (int) $row['n'];
            }
        }
        $out = [];
        foreach ($buckets as $start => $counts) {
            $out[] = ['start_ms' => $start * 1000] + $counts + ['breaches' => array_sum($counts)];
        }
        return $out;
    }

    /** One series per sensor type the mine reports, oldest first, with the limit line. */
    public static function trend(int $mineId, int $points): array
    {
        $sensors = Rules::sensors();
        // One statement for every sensor type (LATERAL, each on the (mine_id, sensor_type,
        // recorded_at) index): planning a query over the monthly partitions costs more than
        // running it, so one plan instead of six (docs/PERFORMANCE.md).
        $types = '{' . implode(',', array_keys($sensors)) . '}';
        $byType = [];
        foreach (Yii::$app->db->createCommand(
            'SELECT r.id, r.sensor_type, r.value, r.unit, r.recorded_at, r.breached
               FROM unnest(CAST(:types AS varchar[])) AS t(type)
               CROSS JOIN LATERAL (
                 SELECT id, sensor_type, value, unit, recorded_at, breached FROM {{%sensor_reading}}
                  WHERE mine_id = :mine AND sensor_type = t.type
                  ORDER BY recorded_at DESC, id DESC LIMIT :points) r',
            [':types' => $types, ':mine' => $mineId, ':points' => $points],
        )->queryAll() as $row) {
            $byType[$row['sensor_type']][] = $row;
        }
        $series = [];
        foreach ($sensors as $type => $rule) {
            $rows = $byType[$type] ?? [];
            if ($rows === []) {
                continue;
            }
            $rows = array_reverse($rows);
            $series[] = [
                'sensor_type' => $type,
                'category' => Rules::SENSOR_CATEGORY[$type],
                'unit' => $rule['unit'],
                'threshold' => $rule['limit'],
                'compare' => $rule['compare'],
                'obligation' => $rule['obligation'],
                'breach_count' => count(array_filter($rows, fn($r) => $r['breached'] === true || $r['breached'] === 't')),
                'points' => array_map(fn($r) => [
                    'id' => (int) $r['id'], 'mine_id' => $mineId, 'sensor_type' => $r['sensor_type'],
                    'value' => (float) $r['value'], 'unit' => $r['unit'],
                    'breached' => $r['breached'] === null ? null : (bool) $r['breached'],
                    'recorded_at' => Format::utc($r['recorded_at']),
                ], $rows),
            ];
        }
        return ['mine_id' => $mineId, 'series' => $series];
    }

    /** Limits and units for the frontend legend. */
    public static function thresholds(): array
    {
        return array_map(fn($r) => ['unit' => $r['unit'], 'limit' => $r['limit'], 'compare' => $r['compare'], 'obligation' => $r['obligation']], Rules::sensors());
    }
}
