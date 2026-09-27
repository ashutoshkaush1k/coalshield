<?php

declare(strict_types=1);

namespace app\services;

use app\components\Rules;
use app\models\Alert;
use Yii;
use yii\db\Query;

/**
 * Contractor alerts generated from the data (brief Phase 3), one per contract - the mine that holds
 * the contract is the one that must act, and that is how the historical alerts are keyed too:
 *
 *   CONTRACTOR_LICENCE_EXPIRING   licence expired or expiring within 30 days      LAB-02
 *   WORKER_VT_EXPIRED             active workers with an expired VT certificate    SAF-04
 *   WORKER_MEDICAL_EXPIRED        active workers past the 12-month examination     HLT-01
 *   CONTRACTOR_DOC_MISSING        monthly documents not uploaded by the due day    (product setting)
 *   CONTRACT_WORKER_CAP_EXCEEDED  more active workers than the contract allows     (contract term)
 *
 * Idempotent: an alert is not raised again while one for the same code and contract (and, for
 * documents, the same period; for the licence, the same expiry date) is still unresolved - whether
 * it was raised here or loaded with the seeded history. Run by `yii contractor/check`
 * (run_all.bat, and from Phase 7 the daily job).
 */
final class ContractorAlertService
{
    /** @return array<string, int> alerts raised per code */
    public static function run(?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $alertDays = (int) Rules::value('product', 'contractor_licence_expiring_alert_days');
        $months = (int) Rules::value('legal', 'medical_exam_interval_months');
        $medicalCutoff = (new \DateTimeImmutable($today))->modify("-{$months} months")->format('Y-m-d');
        $contracts = (new Query())->select(['c.*', 'k.licence_valid_to', 'k.status AS contractor_status'])
            ->from(['c' => '{{%contract}}'])->innerJoin(['k' => '{{%contractor}}'], 'k.id = c.contractor_id')
            ->where(['<=', 'c.start_date', $today])->andWhere(['>=', 'c.end_date', $today])->orderBy('c.id')->all();
        if ($contracts === []) {
            return [];
        }

        $open = [];   // unresolved alerts: code|contract|discriminator
        foreach ((new Query())->select(['code', 'entity_id', 'params'])->from('{{%alert}}')
            ->where(['entity_type' => 'contract', 'code' => ['CONTRACTOR_LICENCE_EXPIRING', 'WORKER_VT_EXPIRED', 'WORKER_MEDICAL_EXPIRED', 'CONTRACTOR_DOC_MISSING', 'CONTRACT_WORKER_CAP_EXCEEDED']])
            ->andWhere(['<>', 'status', Alert::STATUS_RESOLVED])->all() as $a) {
            $p = json_decode($a['params'], true) ?: [];
            $open[$a['code'] . '|' . $a['entity_id'] . '|' . ($p['period'] ?? $p['licence_valid_to'] ?? '')] = true;
        }
        $workers = [];
        foreach ((new Query())->from('{{%contract_worker}}')->where(['contract_id' => array_column($contracts, 'id'), 'active' => true])->all() as $w) {
            $workers[$w['contract_id']][] = $w;
        }
        $missing = [];
        foreach (ContractorService::missingDocuments($contracts, $today) as $m) {
            $missing[$m['contract_id']][$m['period']][] = $m['doc_type'];
        }

        $raised = [];
        $raise = function (array $c, string $code, string $severity, array $params, string $discriminator = '') use (&$open, &$raised) {
            $key = $code . '|' . $c['id'] . '|' . $discriminator;
            if (isset($open[$key])) {
                return;
            }
            AlertService::create((int) $c['mine_id'], $code, $severity, 'contract', (int) $c['id'], $params);
            $open[$key] = true;
            $raised[$code] = ($raised[$code] ?? 0) + 1;
        };

        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($contracts as $c) {
                $daysLeft = (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($c['licence_valid_to']))->format('%r%a');
                if ($daysLeft <= $alertDays) {
                    $raise($c, 'CONTRACTOR_LICENCE_EXPIRING', $daysLeft < 0 ? 'high' : 'medium', [
                        'contractor_id' => (int) $c['contractor_id'], 'contract_id' => (int) $c['id'], 'days_left' => $daysLeft,
                        'licence_valid_to' => $c['licence_valid_to'], 'obligation' => 'LAB-02',
                    ], $c['licence_valid_to']);
                }
                $active = $workers[$c['id']] ?? [];
                $vt = count(array_filter($active, fn($w) => $w['vt_cert_valid_to'] < $today));
                if ($vt) {
                    $raise($c, 'WORKER_VT_EXPIRED', 'medium', ['contract_id' => (int) $c['id'], 'workers' => $vt, 'obligation' => 'SAF-04']);
                }
                $medical = count(array_filter($active, fn($w) => $w['medical_exam_date'] < $medicalCutoff));
                if ($medical) {
                    $raise($c, 'WORKER_MEDICAL_EXPIRED', 'medium', ['contract_id' => (int) $c['id'], 'workers' => $medical, 'obligation' => 'HLT-01']);
                }
                foreach ($missing[$c['id']] ?? [] as $period => $types) {
                    $raise($c, 'CONTRACTOR_DOC_MISSING', count($types) > 1 ? 'high' : 'medium',
                        ['contract_id' => (int) $c['id'], 'doc_types' => $types, 'period' => $period], $period);
                }
                if (count($active) > (int) $c['max_workers']) {
                    $raise($c, 'CONTRACT_WORKER_CAP_EXCEEDED', 'high', [
                        'contract_id' => (int) $c['id'], 'active_workers' => count($active), 'max_workers' => (int) $c['max_workers'],
                    ]);
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        ksort($raised);
        return $raised;
    }
}
