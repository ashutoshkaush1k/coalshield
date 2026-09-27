<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Contractor;
use app\services\ContractorAlertService;
use app\services\ContractorService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * yii contractor/check        raise the contractor alerts due today (idempotent)
 * yii contractor/report [n]   the n worst contractors fleet-wide, with their score and reasons
 */
class ContractorController extends Controller
{
    public function actionCheck(): int
    {
        $raised = ContractorAlertService::run();
        if ($raised === []) {
            $this->stdout("No new contractor alerts (everything due is already alerted).\n");
            return ExitCode::OK;
        }
        foreach ($raised as $code => $n) {
            $this->stdout(sprintf("  %-30s %4d\n", $code, $n));
        }
        $this->stdout(array_sum($raised) . " contractor alert(s) raised.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    public function actionReport(int $limit = 10): int
    {
        $contractors = Contractor::find()->all();
        $evals = ContractorService::worstFirst(ContractorService::evaluate($contractors, null));
        $names = array_column(array_map(fn($c) => ['id' => $c->id, 'name' => $c->name], $contractors), 'name', 'id');
        foreach (array_slice($evals, 0, $limit) as $i => $e) {
            $this->stdout(sprintf("%2d. #%-3d %-32s score %3d %-9s vpw %-6s missing docs %2d  %s\n", $i + 1, $e['contractor_id'],
                $names[$e['contractor_id']], $e['score'], $e['band'], $e['violations_per_worker'] ?? '-',
                count($e['missing_documents']), implode(', ', array_column($e['reasons'], 'code'))));
        }
        return ExitCode::OK;
    }
}
