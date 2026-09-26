<?php

declare(strict_types=1);

namespace app\commands;

use app\components\AuditChain;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/** yii audit/verify - recompute the audit hash chain; exit code 1 if any row was altered. */
class AuditController extends Controller
{
    public function actionVerify(): int
    {
        $total = (int) Yii::$app->db->createCommand('SELECT count(*) FROM audit_log')->queryScalar();
        $broken = AuditChain::verify();
        if ($broken === []) {
            $this->stdout("Audit chain intact: $total entries verified.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $this->stderr("Audit chain BROKEN ($total entries). First problems:\n", Console::FG_RED);
        foreach ($broken as $row) {
            $this->stderr("  id {$row['id']}: {$row['reason']}\n");
        }
        return ExitCode::UNSPECIFIED_ERROR;
    }
}
