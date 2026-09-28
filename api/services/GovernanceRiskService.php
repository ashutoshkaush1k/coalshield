<?php

declare(strict_types=1);

namespace app\services;

use app\components\AccessRule;
use app\components\Format;
use app\components\Rules;
use app\models\Grievance;
use app\models\User;
use Yii;
use yii\db\Query;

/**
 * Governance Risk Index (Phase 7): the brief's "extended score", kept SEPARATE from the compliance
 * score - which, and whose demo values (100/80/70/60/45, 83.2), do not change.
 *
 *   GRI = min(100, sum over components of min(cap, points x count)) x repeat multiplier
 *
 * 0-100, higher = more risk. Components (rules.yaml product.governance_risk_index, product settings):
 *   open_violations            unresolved violations
 *   sensor_breaches            breaches in the compliance score's window
 *   overdue_obligations        statutory tasks overdue or escalated
 *   overdue_contractor_docs    monthly contractor documents past their due day
 *   grievances_past_sla        open grievances past their response time
 *   ageing_corrective_actions  open corrective actions past their due time
 * Repeat multiplier: 1 + step per violation category with min_repeats or more in the last
 * window_days, at most `max` - the brief's repeat_violation_multiplier.
 *
 * A mine head's index leaves out the sensitive grievances it may not see (AccessRule), so its
 * number can be lower than the regulator's for the same mine.
 */
final class GovernanceRiskService
{
    public const COMPONENTS = ['open_violations', 'sensor_breaches', 'overdue_obligations', 'overdue_contractor_docs',
        'grievances_past_sla', 'ageing_corrective_actions'];

    public static function settings(): array
    {
        return Rules::value('product', 'governance_risk_index');
    }

    /**
     * @param int[] $mineIds
     * @return array<int, array{gri: int, band: string, raw: float, multiplier: float, repeat_categories: list<string>,
     *                           components: list<array{key: string, count: int, points: int, cap: int, value: float}>}>
     */
    public static function forMines(array $mineIds, ?User $viewer = null, ?\DateTimeImmutable $now = null): array
    {
        if ($mineIds === []) {
            return [];
        }
        $now ??= Format::now();
        $cfg = self::settings();
        $counts = array_fill_keys(self::COMPONENTS, []);
        $nowSql = Format::sql($now);

        $counts['open_violations'] = self::grouped((new Query())->from('{{%violation}}')->where(['mine_id' => $mineIds, 'resolved' => false]));
        foreach (ComplianceScoreService::scoreMines($mineIds) as $id => $r) {
            $counts['sensor_breaches'][$id] = $r->breachCount;
        }
        $counts['overdue_obligations'] = self::grouped((new Query())->from('{{%obligation_task}}')
            ->where(['mine_id' => $mineIds, 'status' => ['overdue', 'escalated']]));
        $grievances = (new Query())->from('{{%grievance}}')->where(['mine_id' => $mineIds, 'status' => Grievance::OPEN])
            ->andWhere(['<', 'sla_due_at', $nowSql]);
        if ($viewer !== null && !AccessRule::seesSensitiveGrievances($viewer)) {
            $grievances->andWhere(['not in', 'id', AccessRule::sensitiveGrievanceIds()]);
        }
        $counts['grievances_past_sla'] = self::grouped($grievances);
        $ageing = $now->modify('-' . (int) $cfg['ageing_days'] . ' days');
        $counts['ageing_corrective_actions'] = self::grouped((new Query())->from('{{%corrective_action}}')
            ->where(['mine_id' => $mineIds, 'status' => 'open'])->andWhere(['<', 'due_at', Format::sql($ageing)]));
        $contracts = (new Query())->select(['id', 'mine_id', 'start_date', 'end_date'])->from('{{%contract}}')->where(['mine_id' => $mineIds])->all();
        $mineOf = array_column($contracts, 'mine_id', 'id');
        foreach (ContractorService::missingDocuments($contracts, $now->format('Y-m-d')) as $m) {
            $id = (int) $mineOf[$m['contract_id']];
            $counts['overdue_contractor_docs'][$id] = ($counts['overdue_contractor_docs'][$id] ?? 0) + 1;
        }
        $rep = $cfg['repeat_multiplier'];
        $repeats = [];
        foreach ((new Query())->select(['mine_id', 'category'])->from('{{%violation}}')->where(['mine_id' => $mineIds])
            ->andWhere(['>', 'detected_at', Format::sql($now->modify('-' . (int) $rep['window_days'] . ' days'))])
            ->andWhere(['<=', 'detected_at', $nowSql])
            ->groupBy(['mine_id', 'category'])->having(['>=', 'count(*)', (int) $rep['min_repeats']])->orderBy(['mine_id' => SORT_ASC, 'category' => SORT_ASC])->all() as $row) {
            $repeats[(int) $row['mine_id']][] = $row['category'];
        }

        $out = [];
        foreach ($mineIds as $id) {
            $components = [];
            $raw = 0.0;
            foreach (self::COMPONENTS as $key) {
                $c = $cfg['components'][$key];
                $count = (int) ($counts[$key][$id] ?? 0);
                $value = (float) min((int) $c['cap'], (int) $c['points'] * $count);
                $raw += $value;
                $components[] = ['key' => $key, 'count' => $count, 'points' => (int) $c['points'], 'cap' => (int) $c['cap'], 'value' => $value];
            }
            $categories = $repeats[$id] ?? [];
            $multiplier = min((float) $rep['max'], 1 + (float) $rep['step'] * count($categories));
            $gri = (int) min(100, round($raw * $multiplier));
            $out[(int) $id] = ['gri' => $gri, 'band' => self::band($gri), 'raw' => $raw, 'multiplier' => round($multiplier, 2),
                'repeat_categories' => $categories, 'components' => $components];
        }
        return $out;
    }

    public static function band(int $gri): string
    {
        $bands = self::settings()['bands'];
        return $gri >= (int) $bands['high'] ? 'high' : ($gri >= (int) $bands['medium'] ? 'medium' : 'low');
    }

    /** @return array<int, int> count per mine */
    private static function grouped(Query $query): array
    {
        return array_map('intval', $query->select(['n' => 'count(*)', 'mine_id'])->groupBy('mine_id')->indexBy('mine_id')->column());
    }
}
