<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use app\services\GrievanceService;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * yii grievance/check      SLA breaches and second escalations (GRIEVANCE_SLA_BREACHED); idempotent.
 *                          Run by run_all.bat (the Phase 7 jobs later); reading grievances runs it too.
 * yii grievance/clusters   the SLA-breach clusters across all mines
 */
class GrievanceController extends Controller
{
    public function actionCheck(): int
    {
        $done = GrievanceService::escalateDue();
        $this->stdout(sprintf("Grievance SLA: %d breached (level 1), %d escalated to level 2.\n", $done['breached'], $done['escalated']));
        return ExitCode::OK;
    }

    public function actionClusters(): int
    {
        $government = User::find()->where(['role' => User::ROLE_GOVERNMENT])->orderBy('id')->one();
        $mines = (new Query())->select(['id', 'code', 'name'])->from('{{%mine}}')->indexBy('id')->all();
        $clusters = GrievanceService::clusters($government, array_map('intval', array_keys($mines)));
        foreach ($clusters as $mineId => $c) {
            $this->stdout(sprintf("  %-10s %-28s %d breaches, raised %s to %s (grievances %s)\n", $mines[$mineId]['code'],
                mb_substr($mines[$mineId]['name'], 0, 28), $c['breaches'], $c['from'], $c['to'], implode(', ', $c['grievance_ids'])));
        }
        $this->stdout(count($clusters) . " mine(s) flagged.\n");
        return ExitCode::OK;
    }
}
