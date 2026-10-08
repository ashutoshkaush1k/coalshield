<?php

declare(strict_types=1);

namespace app\commands;

use app\components\AuditChain;
use app\components\DemoAccount;
use app\models\Mine;
use app\services\ComplianceScoreService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * The demo board's baseline (Phase 8), checked by scripts\demo_reset.bat and scripts\predemo_check.bat.
 *
 *   yii demo/check           the five named mines' scores and bands, the fleet's average and band
 *                            split, no breach in the window, and the audit chain; exit 0 when all hold
 *   yii demo/check --json    the same as one JSON object (for the scripts)
 *   yii demo/account         create the "Continue as admin (demo)" account if it is missing (the
 *                            seed creates it; this is for a database seeded before it existed)
 *
 * The numbers are those of the demo script (docs/demo-script.md) and DemoScoreCest, after
 * `yii seed demo` (and `yii jobs/all`, which changes none of them). A live demo moves them -
 * breaches from the simulator, findings from the field app - which is why this runs after a reset.
 */
class DemoController extends Controller
{
    public const MINES = [
        'JH-DHN-01' => [100.0, 'low'],    // Moonidih, BCCL
        'MP-SGR-02' => [80.0, 'low'],     // Jayant, NCL
        'CG-KRB-03' => [70.0, 'medium'],  // Gevra, SECL
        'WB-RNG-04' => [60.0, 'medium'],  // Sonepur Bazari, ECL
        'OD-TLC-05' => [45.0, 'high'],    // Bhubaneswari, MCL
    ];
    public const FLEET = ['mine_count' => 74, 'average_score' => 83.2, 'high_risk_count' => 6, 'medium_risk_count' => 21,
        'low_risk_count' => 47, 'total_breaches' => 0];

    public bool $json = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['json']);
    }

    public function actionCheck(): int
    {
        $result = self::check();
        if ($this->json) {
            $this->stdout(json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
            return $result['ok'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }
        foreach ($result['mines'] as $code => $m) {
            $this->stdout(sprintf("%s  %-10s %5.1f %-6s (expected %5.1f %s)\n", $m['ok'] ? 'ok  ' : 'DIFF', $code, $m['score'], $m['band'],
                $m['expected_score'], $m['expected_band']), $m['ok'] ? Console::FG_GREEN : Console::FG_RED);
        }
        foreach ($result['fleet'] as $key => $f) {
            $this->stdout(sprintf("%s  %-18s %s (expected %s)\n", $f['ok'] ? 'ok  ' : 'DIFF', $key, $f['value'], $f['expected']),
                $f['ok'] ? Console::FG_GREEN : Console::FG_RED);
        }
        $this->stdout(sprintf("%s  audit chain        %d entries%s\n", $result['audit']['ok'] ? 'ok  ' : 'DIFF', $result['audit']['entries'],
            $result['audit']['ok'] ? ' intact' : ' BROKEN'), $result['audit']['ok'] ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout($result['ok'] ? "Demo baseline: OK\n" : "Demo baseline: DIFFERENT - reset with scripts\\demo_reset.bat\n",
            $result['ok'] ? Console::FG_GREEN : Console::FG_RED);
        return $result['ok'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** @return array{ok: bool, mines: array, fleet: array, audit: array} */
    public static function check(): array
    {
        $ids = array_map('intval', Mine::find()->select('id')->column());
        $results = ComplianceScoreService::scoreMines($ids);
        $codes = Mine::find()->select('id')->indexBy('code')->column();
        $ok = true;
        $mines = [];
        foreach (self::MINES as $code => [$score, $band]) {
            $r = isset($codes[$code]) ? ($results[(int) $codes[$code]] ?? null) : null;
            $row = ['score' => $r?->score ?? -1.0, 'band' => $r?->riskLevel ?? 'missing', 'expected_score' => $score, 'expected_band' => $band];
            $row['ok'] = $r !== null && abs($r->score - $score) < 0.05 && $r->riskLevel === $band && $r->breachCount === 0;
            $ok = $ok && $row['ok'];
            $mines[$code] = $row;
        }
        $stats = ComplianceScoreService::fleetStats($results);
        $stats['total_breaches'] = array_sum(array_map(fn($r) => $r->breachCount, $results));
        $fleet = [];
        foreach (self::FLEET as $key => $expected) {
            $value = $stats[$key] ?? null;
            $fleet[$key] = ['value' => $value, 'expected' => $expected, 'ok' => $value !== null && abs((float) $value - (float) $expected) < 0.05];
            $ok = $ok && $fleet[$key]['ok'];
        }
        $broken = AuditChain::verify();
        $audit = ['ok' => $broken === [], 'entries' => (int) Yii::$app->db->createCommand('SELECT count(*) FROM audit_log')->queryScalar()];
        return ['ok' => $ok && $audit['ok'], 'mines' => $mines, 'fleet' => $fleet, 'audit' => $audit];
    }

    /** yii demo/account - the "Continue as admin (demo)" account, if missing; audited when created. */
    public function actionAccount(): int
    {
        $db = Yii::$app->db;
        // The account and its audit entry together, or neither.
        $transaction = $db->beginTransaction();
        $existed = $db->createCommand('SELECT 1 FROM {{%user}} WHERE lower(email) = :e', [':e' => DemoAccount::EMAIL])->queryScalar() !== false;
        $id = DemoAccount::insertIfMissing($db);
        if (!$existed) {
            AuditChain::append('user', $id, 'create', null, ['email' => DemoAccount::EMAIL, 'full_name' => DemoAccount::NAME, 'role' => DemoAccount::ROLE], $db);
        }
        $transaction->commit();
        DemoAccount::assignRole($id);
        $this->stdout(sprintf("%s: %s (user %d, role %s). The demo login itself is on only with DEMO_LOGIN_ENABLED=true.\n",
            DemoAccount::NAME, $existed ? 'already there' : 'created', $id, DemoAccount::ROLE), Console::FG_GREEN);
        return ExitCode::OK;
    }
}
