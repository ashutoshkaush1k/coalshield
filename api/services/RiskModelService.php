<?php

declare(strict_types=1);

namespace app\services;

use app\components\AiClient;
use app\components\AiUnavailableException;
use app\components\Format;
use Yii;
use yii\db\Query;

/**
 * The predictive model (Phase 7): gradient-boosted trees trained on real US mine-years (MSHA,
 * ai-service/risk/train.py) and applied to our mines. It predicts the probability of at least one
 * high-incidence next year (lost-time and fatal accidents at 3 or more per 100 workers), with the
 * features that raise it most.
 *
 * Features (ai-service/risk/features.py, the same here): the mine's last 90 days, annualised -
 * workers (average daily manpower present, from production entries), underground or not,
 * violations per 100 workers overall and by category, accidents (fatal, serious, minor) and
 * lost-time accidents (fatal, serious) per 100 workers. The model travels as JSON trees
 * (ai-service/risk/model.json); predict() asks the ai-service and, when it is down, evaluates the
 * same trees here - same arithmetic, same result (tests/unit/RiskModelParityTest.php).
 *
 * Transfer (ai-service/risk/model.py transfer(), the same here): violation rates depend on how
 * hard inspectors look (US ~32 per 100 workers a year, our records ~6), so each mine's violation
 * rates are replaced by the US value at the percentile the mine holds in our fleet (a mine with
 * none stays at none); accident rates pass as they are. Trained on US regulator data and
 * transferred: a ranking signal, not a calibrated probability for Indian mines
 * (docs/AI_EVALUATION.md, and said on every screen that shows it).
 */
final class RiskModelService
{
    public const CATEGORY_FEATURE = ['roof_strata' => 'roof_strata_per_100', 'ventilation_gas' => 'ventilation_gas_per_100',
        'electrical' => 'electrical_per_100', 'machinery' => 'machinery_per_100', 'transport_haulage' => 'transport_haulage_per_100',
        'fire' => 'fire_per_100', 'ppe' => 'ppe_per_100'];
    public const WINDOW_DAYS = 90;

    private static ?array $model = null;

    public static function model(): array
    {
        if (self::$model === null) {
            $path = (string) (Yii::$app->params['ai.riskModelPath'] ?? '../ai-service/risk/model.json');
            if (!preg_match('~^([a-zA-Z]:)?[/\\\\]~', $path)) {
                $path = Yii::getAlias('@app') . '/' . $path;
            }
            if (!is_file($path)) {
                throw new \RuntimeException("risk model missing: $path (run ai-service/risk/train.py)");
            }
            self::$model = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$model;
    }

    /** Each mine's features as of $asOf. @return array<int, array<string, float>> */
    public static function features(\DateTimeImmutable $asOf): array
    {
        $from = $asOf->modify('-' . self::WINDOW_DAYS . ' days');
        $scale = 365 / self::WINDOW_DAYS;
        $fromSql = Format::sql($from);
        $toSql = Format::sql($asOf);
        $db = Yii::$app->db;
        $mines = (new Query())->select(['id', 'type'])->from('{{%mine}}')->orderBy('id')->all();
        $workers = $db->createCommand(
            'SELECT mine_id, avg(total) FROM (SELECT mine_id, date, sum(manpower_present) AS total FROM {{%daily_production}}
             WHERE date > :from AND date <= :to GROUP BY 1, 2) d GROUP BY 1',
            [':from' => $from->format('Y-m-d'), ':to' => $asOf->format('Y-m-d')])->queryAll(\PDO::FETCH_KEY_PAIR);
        $violations = [];
        foreach ($db->createCommand('SELECT mine_id, category, count(*) FROM {{%violation}} WHERE detected_at > :from AND detected_at <= :to GROUP BY 1, 2',
            [':from' => $fromSql, ':to' => $toSql])->queryAll() as $r) {
            $violations[(int) $r['mine_id']][$r['category']] = (int) $r['count'];
        }
        $incidents = [];
        foreach ($db->createCommand("SELECT mine_id, count(*) FILTER (WHERE severity IN ('fatal', 'serious', 'minor')) AS accidents,
                count(*) FILTER (WHERE severity IN ('fatal', 'serious')) AS lost FROM {{%incident}} WHERE occurred_at > :from AND occurred_at <= :to GROUP BY 1",
            [':from' => $fromSql, ':to' => $toSql])->queryAll() as $r) {
            $incidents[(int) $r['mine_id']] = [(int) $r['accidents'], (int) $r['lost']];
        }
        $out = [];
        foreach ($mines as $m) {
            $id = (int) $m['id'];
            $w = max((float) ($workers[$id] ?? 0), 1.0);
            $per100 = fn(float $n) => 100.0 * $n * $scale / $w;
            [$acc, $lost] = $incidents[$id] ?? [0, 0];
            $f = [
                'log_workers' => log1p($w),
                'underground' => in_array($m['type'], ['underground', 'mixed'], true) ? 1.0 : 0.0,
                'violations_per_100' => $per100((float) array_sum($violations[$id] ?? [])),
            ];
            foreach (self::CATEGORY_FEATURE as $category => $feature) {
                $f[$feature] = $per100((float) ($violations[$id][$category] ?? 0));
            }
            $f['accidents_per_100'] = $per100((float) $acc);
            $f['lost_time_per_100'] = $per100((float) $lost);
            $f['had_lost_time'] = $lost > 0 ? 1.0 : 0.0;
            $out[$id] = $f;
        }
        return $out;
    }

    /**
     * Predictions for every mine: the ai-service, or these trees when it is down.
     * @return array{model_version: string, engine: string, predictions: list<array>}
     */
    public static function predict(array $features, ?string $engine = null): array
    {
        $engine ??= (string) (Yii::$app->params['ai.engine'] ?? 'auto');
        $mines = array_map(fn($id, $f) => ['mine_id' => $id, 'features' => $f], array_keys($features), $features);
        if ($engine !== 'php') {
            try {
                return AiClient::postJson('risk/predict', ['mines' => $mines]);
            } catch (AiUnavailableException $e) {
                if ($engine === 'ai-service') {
                    throw $e;
                }
            }
        }
        $doc = self::model();
        $preds = [];
        foreach (self::transfer($doc, $mines) as $t) {
            $p = self::probability($doc, $t['x']);
            $preds[] = ['mine_id' => $t['mine_id'], 'probability' => round($p, 4), 'band' => self::band($doc, $p),
                'factors' => self::explain($doc, $t)];
        }
        return ['model_version' => $doc['version'], 'engine' => 'php', 'predictions' => $preds];
    }

    /** Predict and store (mine_risk_prediction); the job `yii jobs/score` calls this. @return int mines */
    public static function refresh(?\DateTimeImmutable $asOf = null, ?string $engine = null): array
    {
        $asOf ??= Format::now();
        $features = self::features($asOf);
        $result = self::predict($features, $engine);
        $now = Format::sql(Format::now());
        $rows = [];
        foreach ($result['predictions'] as $p) {
            $rows[] = [(int) $p['mine_id'], $now, (float) $p['probability'], $p['band'], $p['factors'],
                $features[(int) $p['mine_id']], $result['model_version'], $result['engine'] ?? 'ai-service'];
        }
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()->delete('{{%mine_risk_prediction}}')->execute();
            $db->createCommand()->batchInsert('{{%mine_risk_prediction}}',
                ['mine_id', 'predicted_at', 'probability', 'band', 'factors', 'features', 'model_version', 'engine'], $rows)->execute();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return ['mines' => count($rows), 'engine' => $result['engine'] ?? 'ai-service', 'model_version' => $result['model_version']];
    }

    public static function card(): array
    {
        $doc = self::model();
        return ['version' => $doc['version'], 'bands' => $doc['bands']] + $doc['card'];
    }

    public static function rawScore(array $doc, array $x): float
    {
        $values = array_map(fn($f) => (float) $x[$f], $doc['features']);
        $total = (float) $doc['init'];
        foreach ($doc['trees'] as $tree) {
            $node = 0;
            while ($tree['l'][$node] !== -1) {
                $node = $values[$tree['f'][$node]] <= $tree['t'][$node] ? $tree['l'][$node] : $tree['r'][$node];
            }
            $total += $doc['learning_rate'] * $tree['v'][$node];
        }
        return $total;
    }

    public static function probability(array $doc, array $x): float
    {
        $z = $doc['platt']['a'] * self::rawScore($doc, $x) + $doc['platt']['b'];
        return 1.0 / (1.0 + exp(-$z));
    }

    /** Mid-rank percentile of $x among $values, 0..1. */
    public static function percentile(array $values, float $x): float
    {
        $below = count(array_filter($values, fn($v) => $v < $x));
        $equal = count(array_filter($values, fn($v) => $v == $x));
        return ($below + 0.5 * $equal) / count($values);
    }

    /** Linear interpolation in the 101 stored quantiles. */
    public static function quantile(array $qs, float $q): float
    {
        $pos = min(max($q, 0.0), 1.0) * (count($qs) - 1);
        $i = (int) floor($pos);
        if ($i >= count($qs) - 1) {
            return (float) end($qs);
        }
        return $qs[$i] + ($qs[$i + 1] - $qs[$i]) * ($pos - $i);
    }

    /** Each mine's model features and its violation-rate percentiles in our fleet. */
    public static function transfer(array $doc, array $mines): array
    {
        $fleet = [];
        foreach ($doc['quantiles'] as $f => $_) {
            $fleet[$f] = array_map(fn($m) => (float) $m['features'][$f], $mines);
        }
        $out = [];
        foreach ($mines as $m) {
            $x = array_map('floatval', $m['features']);
            $pct = [];
            foreach ($doc['quantiles'] as $f => $qs) {
                $pct[$f] = self::percentile($fleet[$f], $x[$f]);
                $x[$f] = $x[$f] == 0.0 ? 0.0 : self::quantile($qs, $pct[$f]);
            }
            $out[] = ['mine_id' => (int) $m['mine_id'], 'raw' => $m['features'], 'x' => $x, 'pct' => $pct];
        }
        return $out;
    }

    public static function band(array $doc, float $p): string
    {
        return $p >= $doc['bands']['high'] ? 'high' : ($p >= $doc['bands']['medium'] ? 'medium' : 'low');
    }

    /** The features that raise the probability most: each set to its typical value in turn. */
    public static function explain(array $doc, array $t, int $top = 3): array
    {
        $x = $t['x'];
        $p = self::probability($doc, $x);
        $out = [];
        foreach ($doc['features'] as $f) {
            $ref = $x;
            $ref[$f] = $doc['reference'][$f];
            $delta = $p - self::probability($doc, $ref);
            if ($delta > 0.005) {
                $pct = $t['pct'][$f] ?? null;
                $out[] = ['feature' => $f, 'value' => round((float) $t['raw'][$f], 3),
                    'fleet_percentile' => $pct === null ? null : (int) round(100 * $pct),
                    'typical' => $pct !== null ? null : round((float) $doc['reference'][$f], 3),
                    'points' => round(100 * $delta, 1)];
            }
        }
        usort($out, fn($a, $b) => [-$a['points'], $a['feature']] <=> [-$b['points'], $b['feature']]);
        return array_slice($out, 0, $top);
    }
}
