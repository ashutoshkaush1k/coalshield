<?php

declare(strict_types=1);

namespace app\services;

use app\components\AiClient;
use app\components\AiUnavailableException;
use app\components\Format;
use app\components\Rules;
use app\components\StatusTransition;
use app\models\Alert;
use app\models\AnomalyFlag;
use app\models\Contractor;
use app\services\detectors\ContractorOutlier;
use app\services\detectors\GrievanceCluster;
use app\services\detectors\LateActions;
use app\services\detectors\NightShift;
use app\services\detectors\ProductionAnomaly;
use app\services\detectors\RepeatViolations;
use app\services\detectors\SensorFlatline;
use Yii;
use yii\db\Query;

/**
 * The anomaly detectors (Phase 7). The API builds each detector's payload from the database, asks
 * the ai-service (POST /anomaly/{detector}) and - when it is down, or ai.engine = php - runs the
 * PHP twin of the same algorithm (services/detectors/). run() stores the result: a new finding
 * becomes an active anomaly_flag and an ANOMALY_DETECTED alert, a finding seen again is refreshed,
 * and a finding no longer made is cleared (its alert resolved). Idempotent; a system action.
 *
 * Detectors, their scenarios (data/out/<preset>/scenario_expectations.json) and settings
 * (rules.yaml product.detectors, product.production_anomaly, product.grievance_breach_cluster):
 *   production_anomaly   S2; decoys N1, N3      sensor_flatline      S3
 *   night_shift          S5; decoy N3           repeat_violations    S1
 *   late_actions         S7                     contractor_outlier   S4
 *   grievance_cluster    S6; decoy N2
 */
final class AnomalyService
{
    public const PHP = [
        ProductionAnomaly::NAME => ProductionAnomaly::class,
        SensorFlatline::NAME => SensorFlatline::class,
        NightShift::NAME => NightShift::class,
        RepeatViolations::NAME => RepeatViolations::class,
        LateActions::NAME => LateActions::class,
        ContractorOutlier::NAME => ContractorOutlier::class,
        GrievanceCluster::NAME => GrievanceCluster::class,
    ];
    private const SEVERITY = ['sensor_flatline' => 'high', 'repeat_violations' => 'high'];

    /**
     * Every detector's flags as of $asOf (the last lookback_days).
     * @return array{flags: array<string, list<array>>, engines: array<string, string>}
     */
    public static function detect(\DateTimeImmutable $asOf, ?string $engine = null): array
    {
        $engine ??= (string) (Yii::$app->params['ai.engine'] ?? 'auto');
        $flags = $engines = [];
        $serviceDown = $engine === 'php';
        foreach (self::payloads($asOf) as $name => $payload) {
            if (!$serviceDown) {
                try {
                    $response = AiClient::postJson("anomaly/$name", $payload);
                    $flags[$name] = $response['flags'] ?? [];
                    $engines[$name] = 'ai-service';
                    continue;
                } catch (AiUnavailableException $e) {
                    if ($engine === 'ai-service') {
                        throw $e;
                    }
                    $serviceDown = true;   // do not wait on it again for the other detectors
                }
            }
            $flags[$name] = (self::PHP[$name])::detect($payload);
            $engines[$name] = 'php';
        }
        return ['flags' => $flags, 'engines' => $engines];
    }

    /**
     * Detect and store: new findings -> active flags + ANOMALY_DETECTED alerts; findings seen again
     * refreshed; findings no longer made cleared. @return array summary per detector
     */
    public static function run(?\DateTimeImmutable $asOf = null, ?string $engine = null): array
    {
        $asOf ??= Format::now();
        $result = self::detect($asOf, $engine);
        $now = Format::sql(Format::now());
        $summary = [];
        $webUser = Yii::$app->has('user', true) ? Yii::$app->user : null;
        $identity = $webUser?->getIdentity(false);
        $webUser?->setIdentity(null);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($result['flags'] as $name => $flags) {
                $existing = AnomalyFlag::find()->where(['detector' => $name])->indexBy(fn($f) => $f->mine_id . '|' . $f->subject)->all();
                $seen = [];
                $new = 0;
                foreach ($flags as $f) {
                    $key = (int) $f['mine_id'] . '|' . $f['subject'];
                    $seen[$key] = true;
                    $row = $existing[$key] ?? new AnomalyFlag(['detector' => $name, 'mine_id' => (int) $f['mine_id'], 'subject' => $f['subject'],
                        'first_detected_at' => $now]);
                    $isNew = $row->isNewRecord || $row->status === 'cleared';
                    $row->setAttributes([
                        'window_from' => ($f['from'] ?? '') !== '' ? $f['from'] : null,
                        'window_to' => ($f['to'] ?? '') !== '' ? $f['to'] : null,
                        'score' => (float) $f['score'], 'reasons' => $f['reasons'], 'entities' => (object) ($f['entities'] ?? []),
                        'engine' => $result['engines'][$name], 'status' => 'active', 'last_seen_at' => $now, 'cleared_at' => null,
                    ], false);
                    $row->save(false);
                    if ($isNew) {
                        $new++;
                        $first = $f['reasons'][0];
                        AlertService::create((int) $f['mine_id'], 'ANOMALY_DETECTED', self::SEVERITY[$name] ?? 'medium', 'anomaly_flag', (int) $row->id, [
                            'detector' => $name, 'subject' => $f['subject'], 'reason' => $first['code'], 'reason_params' => $first['params'],
                            'from' => $f['from'] ?? null, 'to' => $f['to'] ?? null,
                        ]);
                    }
                }
                $cleared = 0;
                foreach ($existing as $key => $row) {
                    if ($row->status === 'active' && !isset($seen[$key])) {
                        $row->setAttributes(['status' => 'cleared', 'cleared_at' => $now], false);
                        $row->save(false);
                        $cleared++;
                        foreach (Alert::find()->where(['code' => 'ANOMALY_DETECTED', 'entity_type' => 'anomaly_flag', 'entity_id' => $row->id])
                            ->andWhere(['<>', 'status', Alert::STATUS_RESOLVED])->all() as $alert) {
                            StatusTransition::apply($alert, Alert::STATUS_RESOLVED, ['code' => 'ANOMALY_CLEARED']);
                        }
                    }
                }
                $summary[$name] = ['engine' => $result['engines'][$name], 'flags' => count($flags), 'new' => $new, 'cleared' => $cleared];
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        } finally {
            $webUser?->setIdentity($identity);
        }
        return $summary;
    }

    /** Each detector's payload: the data it needs, nothing more. @return array<string, array> */
    public static function payloads(\DateTimeImmutable $asOf): array
    {
        $cfg = Rules::value('product', 'detectors');
        $lookback = (int) $cfg['lookback_days'];
        $from = $asOf->modify("-{$lookback} days");
        $fromSql = Format::sql($from);
        $toSql = Format::sql($asOf);
        $fromIso = Format::utc($fromSql);
        $toIso = Format::utc($toSql);
        $db = Yii::$app->db;
        $mineIds = array_map('intval', (new Query())->select('id')->from('{{%mine}}')->orderBy('id')->column());

        $prod = Rules::value('product', 'production_anomaly');
        $days = [];
        $start = $from->modify('-' . (int) $prod['rolling_days'] . ' days')->format('Y-m-d');
        foreach (ProductionService::dailyTotals($mineIds, $start, $asOf->format('Y-m-d')) as $mineId => $byDate) {
            foreach ($byDate as $date => $d) {
                $days[] = ['mine_id' => $mineId, 'date' => $date, 'target' => $d['target'], 'actual' => $d['actual']];
            }
        }

        $flat = $cfg['sensor_flatline'];
        // Candidate hours: flat (every reading within the tolerance) AND next to another flat hour at
        // the same value. A run of min_hours (>= 2) consists only of such hours, so the detector's
        // result is unchanged - and a lone hour with one reading (flat by definition) is not sent.
        $hours = $db->createCommand(
            "WITH h AS (
                SELECT mine_id, sensor_type, date_trunc('hour', recorded_at) AS hr, min(value) AS mn, max(value) AS mx, count(*) AS n
                FROM {{%sensor_reading}} WHERE recorded_at > :from AND recorded_at <= :to
                GROUP BY 1, 2, 3 HAVING max(value) - min(value) <= :tol
             ), w AS (
                SELECT h.*, lag(hr) OVER s AS phr, lag(mn) OVER s AS pmn, lag(mx) OVER s AS pmx,
                       lead(hr) OVER s AS nhr, lead(mn) OVER s AS nmn, lead(mx) OVER s AS nmx
                FROM h WINDOW s AS (PARTITION BY mine_id, sensor_type ORDER BY hr)
             )
             SELECT mine_id, sensor_type, extract(epoch FROM hr)::bigint AS hour, mn AS min, mx AS max, n AS count FROM w
             WHERE (phr = hr - interval '1 hour' AND greatest(mx, pmx) - least(mn, pmn) <= :tol)
                OR (nhr = hr + interval '1 hour' AND greatest(mx, nmx) - least(mn, nmn) <= :tol)
             ORDER BY 1, 2, 3",
            [':from' => $fromSql, ':to' => $toSql, ':tol' => (float) $flat['tolerance']])->queryAll();

        $violations = $db->createCommand(
            "SELECT id, mine_id, category, source, extract(epoch FROM detected_at)::bigint AS t,
                    extract(hour FROM detected_at AT TIME ZONE 'Asia/Kolkata')::int AS hour_ist
             FROM {{%violation}} WHERE detected_at > :from AND detected_at <= :to ORDER BY id",
            [':from' => $fromSql, ':to' => $toSql])->queryAll();
        $incidents = $db->createCommand(
            "SELECT id, mine_id, type, extract(epoch FROM occurred_at)::bigint AS t
             FROM {{%incident}} WHERE occurred_at > :from AND occurred_at <= :to ORDER BY id",
            [':from' => $fromSql, ':to' => $toSql])->queryAll();
        $actions = $db->createCommand(
            "SELECT id, mine_id, extract(epoch FROM due_at)::bigint AS due, extract(epoch FROM resolved_at)::bigint AS resolved
             FROM {{%corrective_action}} WHERE resolved_at > :from AND resolved_at <= :to AND due_at IS NOT NULL ORDER BY id",
            [':from' => $fromSql, ':to' => $toSql])->queryAll();

        $contractors = [];
        $all = Contractor::find()->orderBy('id')->all();
        $primary = [];
        foreach ((new Query())->select(['contractor_id', 'mine_id', 'n' => 'count(*)'])->from('{{%contract}}')
            ->groupBy(['contractor_id', 'mine_id'])->orderBy(['contractor_id' => SORT_ASC, 'n' => SORT_DESC, 'mine_id' => SORT_ASC])->all() as $row) {
            $primary[(int) $row['contractor_id']] ??= (int) $row['mine_id'];
        }
        foreach (ContractorService::evaluate($all, null, $asOf->format('Y-m-d')) as $id => $e) {
            $contractors[] = ['contractor_id' => $id, 'mine_id' => $primary[$id] ?? null, 'active_workers' => (int) $e['active_workers'],
                'violations' => (int) $e['violations'], 'missing_docs' => count($e['missing_documents'])];
        }

        $breaches = $db->createCommand(
            "SELECT id, mine_id, extract(epoch FROM created_at)::bigint AS t FROM {{%grievance}}
             WHERE escalation_level >= 1 AND created_at > :from AND created_at <= :to ORDER BY id",
            [':from' => $fromSql, ':to' => $toSql])->queryAll();

        return [
            ProductionAnomaly::NAME => ['settings' => $prod, 'from' => $from->format('Y-m-d'), 'to' => $asOf->format('Y-m-d'), 'days' => $days],
            SensorFlatline::NAME => ['settings' => $flat, 'hours' => array_map(fn($h) => ['mine_id' => (int) $h['mine_id'], 'sensor_type' => $h['sensor_type'],
                'hour' => (int) $h['hour'], 'min' => (float) $h['min'], 'max' => (float) $h['max'], 'count' => (int) $h['count']], $hours)],
            NightShift::NAME => ['settings' => $cfg['night_shift'], 'from' => $fromIso, 'to' => $toIso,
                'violations' => array_values(array_map(fn($v) => ['id' => (int) $v['id'], 'mine_id' => (int) $v['mine_id'], 'hour_ist' => (int) $v['hour_ist']],
                    array_filter($violations, fn($v) => $v['source'] === 'vision')))],
            RepeatViolations::NAME => ['settings' => $cfg['repeat_violations'],
                'violations' => array_map(fn($v) => ['id' => (int) $v['id'], 'mine_id' => (int) $v['mine_id'], 'category' => $v['category'], 't' => (int) $v['t']], $violations),
                'incidents' => array_map(fn($i) => ['id' => (int) $i['id'], 'mine_id' => (int) $i['mine_id'], 'type' => $i['type'], 't' => (int) $i['t']], $incidents)],
            LateActions::NAME => ['settings' => $cfg['late_actions'], 'actions' => array_map(fn($a) => ['id' => (int) $a['id'], 'mine_id' => (int) $a['mine_id'],
                'due' => (int) $a['due'], 'resolved' => (int) $a['resolved']], $actions)],
            ContractorOutlier::NAME => ['settings' => $cfg['contractor_outlier'], 'from' => $fromIso, 'to' => $toIso, 'contractors' => $contractors],
            GrievanceCluster::NAME => ['settings' => Rules::value('product', 'grievance_breach_cluster'),
                'breaches' => array_map(fn($b) => ['id' => (int) $b['id'], 'mine_id' => (int) $b['mine_id'], 't' => (int) $b['t']], $breaches)],
        ];
    }
}
