<?php

declare(strict_types=1);

namespace app\services;

use app\components\Format;
use app\components\Rules;
use app\models\Contractor;
use app\models\ContractorDoc;
use Yii;
use yii\db\Query;

/**
 * Contractor compliance (brief Phase 3): ContractorService::score() from violations per worker,
 * overdue documents, the labour licence and workers over a contract's cap - plus training and
 * medical status. Computed from the data every time, never stored.
 *
 * Legal bases, all from data/schema/rules.yaml (never the repealed Contract Labour Act 1970 or
 * Mines Rules 1955):
 *   licence validity        LAB-02  OSH Code 2020 s.48(3)
 *   vocational refresher    SAF-04  OSH (Central) Rules 2026 r.159 (vt_cert_valid_to in the data)
 *   medical examination     HLT-01  r.109(1), every 12 months
 * Product settings (config, not law): expiring-licence warning 30 days, monthly-document due day
 * 10, the score weights below.
 *
 * Score = 100 - penalties, clamped 0..100:
 *   violations per active worker x 40 (max 40)   all violations linked to the contractor / its
 *                                                 active workers (the data generator's definition)
 *   2 per missing monthly document (max 30)      wage register, EPF challan, ESI challan per active
 *                                                 contract per month, once past the due day
 *   30 if the licence has expired, 10 if it expires within 30 days
 *   share of active workers with expired VT or overdue medical x 15 (max 15)
 *   5 per contract over its worker cap (max 15)
 * Band: compliant >= 80, watch >= 50, else flagged; a suspended or blacklisted contractor is
 * always flagged.
 */
final class ContractorService
{
    public const DOC_LOOKBACK_MONTHS = 4;

    /**
     * @param Contractor[] $contractors
     * @param int[]|null $mineIds only contracts at these mines count (null = all mines)
     * @return array<int, array> evaluation per contractor id
     */
    public static function evaluate(array $contractors, ?array $mineIds, ?string $today = null): array
    {
        if ($contractors === []) {
            return [];
        }
        $today ??= gmdate('Y-m-d');
        $stats = self::collect(array_map(fn(Contractor $c) => (int) $c->id, $contractors), $mineIds, $today, false);
        $out = [];
        foreach ($contractors as $contractor) {
            $out[(int) $contractor->id] = self::score($contractor, $stats[(int) $contractor->id], $today);
        }
        return $out;
    }

    /**
     * Each contractor scored separately at each mine where it holds a contract, counting only its
     * contracts and violations there - the same result as evaluate($contractors, [$mineId]) per
     * mine, from one set of queries.
     * @param array<int, Contractor> $contractors indexed by id
     * @return array<int, array<int, array>> [mine id][contractor id] => evaluation
     */
    public static function evaluatePerMine(array $contractors, array $mineIds, ?string $today = null): array
    {
        if ($contractors === []) {
            return [];
        }
        $today ??= gmdate('Y-m-d');
        $out = [];
        foreach (self::collect(array_keys($contractors), $mineIds, $today, true) as $key => $s) {
            [$contractorId, $mineId] = array_map('intval', explode('|', (string) $key));
            $out[$mineId][$contractorId] = self::score($contractors[$contractorId], $s, $today);
        }
        return $out;
    }

    /**
     * The figures behind a score, per contractor id - or, with $perMine, per "contractor|mine".
     * @param int[] $ids
     */
    private static function collect(array $ids, ?array $mineIds, string $today, bool $perMine): array
    {
        $contracts = (new Query())->from('{{%contract}}')->where(['contractor_id' => $ids])
            ->andFilterWhere(['mine_id' => $mineIds])->orderBy('id')->all();
        $contractIds = array_column($contracts, 'id');
        $activeContracts = array_filter($contracts, fn($c) => $c['start_date'] <= $today && $c['end_date'] >= $today);

        $workers = $contractIds === [] ? [] : (new Query())->from('{{%contract_worker}}')->where(['contract_id' => $contractIds])->all();
        $keyOf = [];   // contract id => stats key
        foreach ($contracts as $c) {
            $keyOf[$c['id']] = $perMine ? $c['contractor_id'] . '|' . $c['mine_id'] : (int) $c['contractor_id'];
        }
        $medicalMonths = (int) Rules::value('legal', 'medical_exam_interval_months');
        $medicalCutoff = (new \DateTimeImmutable($today))->modify("-{$medicalMonths} months")->format('Y-m-d');

        $empty = ['active_workers' => 0, 'vt_expired' => 0, 'medical_overdue' => 0, 'violations' => 0,
            'missing_docs' => [], 'unverified_docs' => 0, 'over_cap' => [], 'contracts' => 0, 'active_contracts' => 0];
        $stats = [];
        if (!$perMine) {
            foreach ($ids as $id) {
                $stats[$id] = $empty;
            }
        }
        foreach ($contracts as $c) {
            $stats[$keyOf[$c['id']]] ??= $empty;
            $stats[$keyOf[$c['id']]]['contracts']++;
        }
        $activeByContract = [];
        foreach ($workers as $w) {
            if (!$w['active']) {
                continue;
            }
            $key = $keyOf[$w['contract_id']];
            $stats[$key]['active_workers']++;
            $activeByContract[$w['contract_id']] = ($activeByContract[$w['contract_id']] ?? 0) + 1;
            if ($w['vt_cert_valid_to'] < $today) {
                $stats[$key]['vt_expired']++;
            }
            if ($w['medical_exam_date'] < $medicalCutoff) {
                $stats[$key]['medical_overdue']++;
            }
        }
        foreach ($activeContracts as $c) {
            $key = $keyOf[$c['id']];
            $stats[$key]['active_contracts']++;
            $active = $activeByContract[$c['id']] ?? 0;
            if ($active > (int) $c['max_workers']) {
                $stats[$key]['over_cap'][] = ['contract_id' => (int) $c['id'], 'active_workers' => $active, 'max_workers' => (int) $c['max_workers']];
            }
        }
        foreach ((new Query())->select(['contractor_id', 'mine_id', 'n' => 'count(*)'])->from('{{%violation}}')
            ->where(['contractor_id' => $ids])->andFilterWhere(['mine_id' => $mineIds])->groupBy(['contractor_id', 'mine_id'])->all() as $row) {
            $key = $perMine ? $row['contractor_id'] . '|' . $row['mine_id'] : (int) $row['contractor_id'];
            if (isset($stats[$key])) {   // per mine: a violation where the contractor holds no contract is not scored there
                $stats[$key]['violations'] += (int) $row['n'];
            }
        }
        foreach (self::missingDocuments($contracts, $today) as $missing) {
            $stats[$keyOf[$missing['contract_id']]]['missing_docs'][] = $missing;
        }
        if ($contractIds !== []) {
            foreach ((new Query())->select(['contract_id', 'n' => 'count(*)'])->from('{{%contractor_compliance_doc}}')
                ->where(['contract_id' => $contractIds, 'verified' => false])->groupBy('contract_id')->all() as $row) {
                $stats[$keyOf[$row['contract_id']]]['unverified_docs'] += (int) $row['n'];
            }
        }
        return $stats;
    }

    /** The score, band and reasons ({code, params}) for one contractor's figures. */
    public static function score(Contractor $contractor, array $s, string $today): array
    {
        $alertDays = (int) Rules::value('product', 'contractor_licence_expiring_alert_days');
        $daysLeft = (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($contractor->licence_valid_to))->format('%r%a');
        $licence = $daysLeft < 0 ? 'expired' : ($daysLeft <= $alertDays ? 'expiring' : 'valid');
        $vpw = $s['active_workers'] > 0 ? round($s['violations'] / $s['active_workers'], 4) : null;
        $nonCompliantWorkers = $s['active_workers'] > 0 ? min(1.0, ($s['vt_expired'] + $s['medical_overdue']) / $s['active_workers']) : 0.0;
        $missing = count($s['missing_docs']);

        $penalties = [
            'violations' => $vpw === null ? 0 : min(40, (int) round(40 * $vpw)),
            'documents' => min(30, 2 * $missing),
            'licence' => ['expired' => 30, 'expiring' => 10, 'valid' => 0][$licence],
            'workers' => min(15, (int) round(15 * $nonCompliantWorkers)),
            'cap' => min(15, 5 * count($s['over_cap'])),
        ];
        $score = max(0, 100 - array_sum($penalties));
        $band = $contractor->status !== 'active' ? 'flagged' : ($score >= 80 ? 'compliant' : ($score >= 50 ? 'watch' : 'flagged'));

        $reasons = [];
        if ($contractor->status !== 'active') {
            $reasons[] = ['code' => 'CONTRACTOR_STATUS', 'params' => ['status' => $contractor->status]];
        }
        if ($missing) {
            $types = array_values(array_unique(array_column($s['missing_docs'], 'doc_type')));
            $reasons[] = ['code' => 'DOCS_MISSING', 'params' => ['count' => $missing, 'doc_types' => $types]];
        }
        if ($vpw !== null && $vpw > 0) {
            $reasons[] = ['code' => 'VIOLATIONS_PER_WORKER', 'params' => ['violations' => $s['violations'], 'workers' => $s['active_workers'], 'ratio' => $vpw]];
        }
        if ($licence !== 'valid') {
            $reasons[] = ['code' => $licence === 'expired' ? 'LICENCE_EXPIRED' : 'LICENCE_EXPIRING', 'params' => ['days_left' => $daysLeft, 'valid_to' => $contractor->licence_valid_to, 'obligation' => 'LAB-02']];
        }
        if ($s['vt_expired']) {
            $reasons[] = ['code' => 'WORKERS_VT_EXPIRED', 'params' => ['workers' => $s['vt_expired'], 'obligation' => 'SAF-04']];
        }
        if ($s['medical_overdue']) {
            $reasons[] = ['code' => 'WORKERS_MEDICAL_OVERDUE', 'params' => ['workers' => $s['medical_overdue'], 'obligation' => 'HLT-01']];
        }
        if ($s['over_cap']) {
            $reasons[] = ['code' => 'OVER_WORKER_CAP', 'params' => ['contracts' => count($s['over_cap'])]];
        }

        return [
            'contractor_id' => (int) $contractor->id,
            'score' => $score,
            'band' => $band,
            'penalties' => $penalties,
            'reasons' => $reasons,
            'licence' => ['state' => $licence, 'valid_to' => $contractor->licence_valid_to, 'days_left' => $daysLeft, 'obligation' => 'LAB-02'],
            'contracts' => $s['contracts'],
            'active_contracts' => $s['active_contracts'],
            'active_workers' => $s['active_workers'],
            'violations' => $s['violations'],
            'violations_per_worker' => $vpw,
            'missing_documents' => $s['missing_docs'],
            'unverified_documents' => $s['unverified_docs'],
            'workers_vt_expired' => $s['vt_expired'],
            'workers_medical_overdue' => $s['medical_overdue'],
            'contracts_over_cap' => $s['over_cap'],
            'is_demo_value' => true,
        ];
    }

    /** Monthly periods whose documents are due by $today (due day of the following month). */
    public static function duePeriods(string $today): array
    {
        $dueDay = (int) Rules::value('product', 'contractor_doc_due_day');
        $periods = [];
        $month = new \DateTimeImmutable(substr($today, 0, 7) . '-01');
        for ($i = 1; $i <= self::DOC_LOOKBACK_MONTHS + 1 && count($periods) < self::DOC_LOOKBACK_MONTHS; $i++) {
            $period = $month->modify("-{$i} months");
            $due = $period->modify('+1 month')->format('Y-m-') . sprintf('%02d', $dueDay);
            if ($due < $today) {
                $periods[] = $period->format('Y-m');
            }
        }
        return array_reverse($periods);
    }

    /**
     * Monthly documents not uploaded for contracts active in that month.
     * @return list<array{contract_id: int, period: string, doc_type: string}>
     */
    public static function missingDocuments(array $contracts, string $today): array
    {
        if ($contracts === []) {
            return [];
        }
        $periods = self::duePeriods($today);
        $have = [];
        foreach ((new Query())->select(['contract_id', 'doc_type', 'period'])->from('{{%contractor_compliance_doc}}')
            ->where(['contract_id' => array_column($contracts, 'id'), 'period' => $periods])->all() as $d) {
            $have[$d['contract_id'] . '|' . $d['doc_type'] . '|' . $d['period']] = true;
        }
        $missing = [];
        foreach ($contracts as $c) {
            foreach ($periods as $period) {
                $start = $period . '-01';
                $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
                if ($c['start_date'] > $end || $c['end_date'] < $start) {
                    continue;
                }
                foreach (ContractorDoc::MONTHLY as $type) {
                    if (!isset($have[$c['id'] . '|' . $type . '|' . $period])) {
                        $missing[] = ['contract_id' => (int) $c['id'], 'period' => $period, 'doc_type' => $type];
                    }
                }
            }
        }
        return $missing;
    }

    /**
     * Per-mine summary for multi-mine roles (brief: count, compliance %, flagged / blacklisted per
     * mine). compliance_pct = share of the mine's contractors (with a contract there) in band
     * "compliant"; each contractor is scored on its contracts at that mine.
     * @param int[] $mineIds
     */
    public static function summary(array $mineIds, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $pairs = (new Query())->select(['mine_id', 'contractor_id'])->distinct()->from('{{%contract}}')
            ->where(['mine_id' => $mineIds])->all();
        $byMine = [];
        foreach ($pairs as $p) {
            $byMine[(int) $p['mine_id']][] = (int) $p['contractor_id'];
        }
        $mines = (new Query())->select(['id', 'code', 'name', 'state'])->from('{{%mine}}')->where(['id' => array_keys($byMine)])->indexBy('id')->all();
        $contractors = Contractor::find()->where(['id' => array_unique(array_column($pairs, 'contractor_id'))])->indexBy('id')->all();

        $perMine = self::evaluatePerMine($contractors, array_keys($byMine), $today);
        $rows = [];
        foreach ($byMine as $mineId => $ids) {
            $evals = $perMine[$mineId];
            $bands = array_count_values(array_column($evals, 'band'));
            $rows[] = [
                'mine_id' => $mineId, 'code' => $mines[$mineId]['code'], 'name' => $mines[$mineId]['name'], 'state' => $mines[$mineId]['state'],
                'contractors' => count($ids),
                'compliance_pct' => round(100 * ($bands['compliant'] ?? 0) / count($ids), 1),
                'flagged' => $bands['flagged'] ?? 0,
                'blacklisted' => count(array_filter($ids, fn($id) => $contractors[$id]->status === 'blacklisted')),
                'average_score' => round(array_sum(array_column($evals, 'score')) / count($evals), 1),
            ];
        }
        usort($rows, fn($a, $b) => [$b['flagged'], $a['compliance_pct'], $a['mine_id']] <=> [$a['flagged'], $b['compliance_pct'], $b['mine_id']]);

        // Flagged contractors, each scored once on all its contracts in scope (not per mine), worst
        // first - so a contractor in trouble across several mines leads the list.
        $minesOf = [];
        foreach ($pairs as $p) {
            $minesOf[(int) $p['contractor_id']][] = (int) $p['mine_id'];
        }
        $flaggedContractors = [];
        foreach (self::worstFirst(self::evaluate(array_values($contractors), $mineIds, $today)) as $e) {
            if ($e['band'] !== 'flagged') {
                continue;
            }
            $flaggedContractors[] = [
                'contractor_id' => $e['contractor_id'], 'name' => $contractors[$e['contractor_id']]->name,
                'status' => $contractors[$e['contractor_id']]->status,
                'mines' => array_map(fn($id) => ['mine_id' => $id, 'code' => $mines[$id]['code'], 'name' => $mines[$id]['name']],
                    array_values(array_unique($minesOf[$e['contractor_id']]))),
                'score' => $e['score'], 'violations_per_worker' => $e['violations_per_worker'],
                'missing_documents' => count($e['missing_documents']), 'reasons' => $e['reasons'],
            ];
        }
        return [
            'mine_count' => count($rows),
            'contractor_count' => count($contractors),
            'flagged' => count($flaggedContractors),
            'blacklisted' => count(array_filter($contractors, fn($c) => $c->status === 'blacklisted')),
            'mines' => $rows,
            'flagged_contractors' => $flaggedContractors,
        ];
    }

    /** Sort evaluations worst first: score, then violations per worker. */
    public static function worstFirst(array $evaluations): array
    {
        usort($evaluations, fn($a, $b) => [$a['score'], -($a['violations_per_worker'] ?? 0), $a['contractor_id']]
            <=> [$b['score'], -($b['violations_per_worker'] ?? 0), $b['contractor_id']]);
        return $evaluations;
    }

    public static function today(): string
    {
        return Format::now()->format('Y-m-d');
    }
}
