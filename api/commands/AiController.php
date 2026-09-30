<?php

declare(strict_types=1);

namespace app\commands;

use app\services\AiEvaluation;
use app\services\AnomalyService;
use app\services\RiskModelService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * The detectors and their evaluation (Phase 7).
 *
 *   yii ai/detect [--engine=auto|php|ai-service] [--as-of=ISO]   run every detector, print the flags (stores nothing)
 *   yii ai/evaluate [--engine=php] [--write] [--preset=demo]     score them against data/out/<preset>/scenario_expectations.json
 *                                                                at the data's own "now"; --write updates docs/AI_EVALUATION.md
 *   yii ai/fixtures                                              write each detector's demo payload to tests/_data/detectors/
 *                                                                (the ai-service and PHP parity tests read them)
 * Storing flags and raising alerts is the job of `yii jobs/anomaly`.
 */
class AiController extends Controller
{
    public ?string $engine = null;
    public ?string $asOf = null;
    public bool $write = false;
    /** ai/evaluate: whose scenario_expectations.json to score against (online: the online database's preset). */
    public string $preset = 'demo';

    public const DOC = '/../docs/AI_EVALUATION.md';
    public const START = '<!-- detector-evaluation:start -->';
    public const END = '<!-- detector-evaluation:end -->';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['engine', 'asOf', 'write', 'preset']);
    }

    public function actionDetect(): int
    {
        $asOf = $this->asOf ? new \DateTimeImmutable($this->asOf) : new \DateTimeImmutable('now');
        $result = AnomalyService::detect($asOf, $this->engine);
        foreach ($result['flags'] as $name => $flags) {
            $this->stdout(sprintf("%-20s %-10s %3d flag(s)\n", $name, $result['engines'][$name], count($flags)));
            foreach ($flags as $f) {
                $this->stdout(sprintf("    mine %-3d %-32s %s  %s\n", $f['mine_id'], mb_substr($f['subject'], 0, 32), $f['reasons'][0]['code'],
                    json_encode($f['reasons'][0]['params'])));
            }
        }
        return ExitCode::OK;
    }

    public function actionEvaluate(): int
    {
        $result = AiEvaluation::score(...array_values(self::evaluation($this->engine ?? 'php', $this->preset)));
        $table = AiEvaluation::markdown($result);
        $this->stdout($table);
        foreach ($result['scenarios'] as $s) {
            $this->stdout(sprintf("  %-40s %-20s expected %-8s -> %s\n", $s['scenario'], $s['detector'], $s['expected'], $s['result']));
        }
        if ($this->write) {
            $path = \Yii::getAlias('@app') . self::DOC;
            $doc = (string) file_get_contents($path);
            $start = strpos($doc, self::START);
            $end = strpos($doc, self::END);
            if ($start === false || $end === false) {
                $this->stderr("markers not found in docs/AI_EVALUATION.md\n");
                return ExitCode::DATAERR;
            }
            $doc = substr($doc, 0, $start + strlen(self::START)) . "\n" . $table . substr($doc, $end);
            file_put_contents($path, $doc);
            $this->stdout("docs/AI_EVALUATION.md updated\n");
        }
        return ExitCode::OK;
    }

    /** @return array{flags: array, engines: array, scenarios: array} at the demo data's own "now" */
    public static function evaluation(string $engine = 'php', string $preset = 'demo'): array
    {
        $detected = AnomalyService::detect(AiEvaluation::asOf($preset), $engine);
        return ['flags' => $detected['flags'], 'engines' => $detected['engines'], 'scenarios' => AiEvaluation::scenarios($preset)];
    }

    public function actionFixtures(): int
    {
        $dir = \Yii::getAlias('@app') . '/tests/_data/detectors';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        foreach (AnomalyService::payloads(AiEvaluation::asOf()) as $name => $payload) {
            file_put_contents("$dir/$name.input.json", json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            $this->stdout(sprintf("%-20s %8d bytes\n", $name, filesize("$dir/$name.input.json")));
        }
        // The predictive model's input for the fleet (its features as of the same moment).
        $mines = [];
        foreach (RiskModelService::features(AiEvaluation::asOf()) as $id => $f) {
            $mines[] = ['mine_id' => $id, 'features' => $f];
        }
        file_put_contents("$dir/risk_predict.input.json", json_encode(['mines' => $mines], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
        $this->stdout(sprintf("%-20s %8d bytes\n", 'risk_predict', filesize("$dir/risk_predict.input.json")));
        $this->stdout("Then run ai-service/tests/make_expected.py to write the *.expected.json files.\n");
        return ExitCode::OK;
    }
}
