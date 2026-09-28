<?php

declare(strict_types=1);

namespace app\services;

use app\components\Format;
use app\components\Rules;
use app\components\StatusTransition;
use app\models\Alert;
use app\models\StatusHistory;
use Yii;
use yii\db\Query;

/**
 * The work of the Phase 7 jobs that no other service already does:
 *
 *   escalateAlerts()          an open (unacknowledged) alert moves up a level with age -
 *                             product.alert_escalation.after_hours [24, 72]: level 1 (area /
 *                             corporate) after 24 h, level 2 (government) after 72 h. Never lowers a
 *                             level another rule set (obligations, grievances).
 *   productionReminders()     yesterday's shifts (IST) not submitted at a mine that reports
 *                             (entries in the last 7 days): PRODUCTION_ENTRY_PENDING, one per mine
 *                             and day; resolved once the day is complete.
 *   snapshot()                once a day per mine: the compliance score and the Governance Risk
 *                             Index with its components (mine_risk_snapshot) - their history.
 * All idempotent and recorded as system actions (no user in the history or the audit trail).
 */
final class AutomationService
{
    public static function escalateAlerts(?\DateTimeImmutable $now = null): array
    {
        $now ??= Format::now();
        $hours = Rules::value('product', 'alert_escalation')['after_hours'];
        $done = [1 => 0, 2 => 0];
        self::asSystem(function () use ($now, $hours, &$done) {
            foreach ([2, 1] as $level) {
                $cutoff = Format::sql($now->modify('-' . (int) $hours[$level - 1] . ' hours'));
                foreach (Alert::find()->where(['status' => Alert::STATUS_OPEN])->andWhere(['<', 'escalation_level', $level])
                    ->andWhere(['<=', 'created_at', $cutoff])->orderBy('id')->each(200) as $alert) {
                    $from = (int) $alert->escalation_level;
                    $alert->escalation_level = $level;
                    $alert->save(false, ['escalation_level']);
                    StatusHistory::record($alert, $alert->status, $alert->status, null,
                        ['code' => 'ALERT_ESCALATED', 'from_level' => $from, 'level' => $level, 'after_hours' => (int) $hours[$level - 1]]);
                    $done[$level]++;
                }
            }
        });
        return ['to_level_1' => $done[1], 'to_level_2' => $done[2]];
    }

    public static function productionReminders(?\DateTimeImmutable $now = null): array
    {
        $now ??= Format::now();
        $shifts = Rules::value('product', 'production_entry_reminder')['shifts'];
        $day = $now->setTimezone(new \DateTimeZone('Asia/Kolkata'))->modify('-1 day')->format('Y-m-d');
        $active = array_map('intval', (new Query())->select('mine_id')->distinct()->from('{{%daily_production}}')
            ->where(['>=', 'date', (new \DateTimeImmutable($day))->modify('-7 days')->format('Y-m-d')])->column());
        $entries = [];
        foreach ((new Query())->select(['mine_id', 'shift', 'status'])->from('{{%daily_production}}')->where(['date' => $day, 'mine_id' => $active])->all() as $e) {
            $entries[(int) $e['mine_id']][$e['shift']] = $e['status'];
        }
        $raised = $resolved = 0;
        self::asSystem(function () use ($active, $entries, $shifts, $day, &$raised, &$resolved) {
            $open = [];
            foreach (Alert::find()->where(['code' => 'PRODUCTION_ENTRY_PENDING'])->andWhere(['<>', 'status', Alert::STATUS_RESOLVED])->all() as $a) {
                $open[$a->mine_id . '|' . ($a->params['date'] ?? '')] = $a;
            }
            foreach ($active as $mineId) {
                $missing = array_values(array_filter($shifts, fn($s) => !isset($entries[$mineId][$s])));
                $drafts = array_values(array_filter($shifts, fn($s) => ($entries[$mineId][$s] ?? null) === 'draft'));
                $key = "$mineId|$day";
                if (($missing !== [] || $drafts !== []) && !isset($open[$key])) {
                    AlertService::create($mineId, 'PRODUCTION_ENTRY_PENDING', 'low', 'mine', $mineId,
                        ['date' => $day, 'missing_shifts' => $missing, 'draft_shifts' => $drafts]);
                    $raised++;
                }
            }
            foreach ($open as $key => $alert) {
                [$mineId, $date] = explode('|', $key, 2);
                $e = (new Query())->select(['shift', 'status'])->from('{{%daily_production}}')->where(['mine_id' => (int) $mineId, 'date' => $date])->all();
                $complete = count(array_filter($e, fn($r) => $r['status'] !== 'draft')) >= count($shifts);
                if ($complete) {
                    StatusTransition::apply($alert, Alert::STATUS_RESOLVED, ['code' => 'ENTRIES_SUBMITTED']);
                    $resolved++;
                }
            }
        });
        return ['date' => $day, 'raised' => $raised, 'resolved' => $resolved];
    }

    public static function snapshot(?\DateTimeImmutable $now = null): array
    {
        $now ??= Format::now();
        $ids = array_map('intval', (new Query())->select('id')->from('{{%mine}}')->orderBy('id')->column());
        $scores = ComplianceScoreService::scoreMines($ids);
        $gri = GovernanceRiskService::forMines($ids, null, $now);
        $day = $now->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
        $created = Format::sql(Format::now());
        $db = Yii::$app->db;
        foreach ($ids as $id) {
            $db->createCommand(
                'INSERT INTO {{%mine_risk_snapshot}} (mine_id, day, score, risk_level, gri, gri_band, components, created_at)
                 VALUES (:m, :d, :s, :r, :g, :b, :c, :t)
                 ON CONFLICT (mine_id, day) DO UPDATE SET score = EXCLUDED.score, risk_level = EXCLUDED.risk_level, gri = EXCLUDED.gri,
                   gri_band = EXCLUDED.gri_band, components = EXCLUDED.components, created_at = EXCLUDED.created_at',
                [':m' => $id, ':d' => $day, ':s' => $scores[$id]->score, ':r' => $scores[$id]->riskLevel, ':g' => $gri[$id]['gri'],
                    ':b' => $gri[$id]['band'], ':c' => json_encode($gri[$id]), ':t' => $created])->execute();
        }
        return ['day' => $day, 'mines' => count($ids)];
    }

    /** Run as the system: no web user in the history, the audit trail or the alerts. */
    private static function asSystem(callable $work): void
    {
        $webUser = Yii::$app->has('user', true) ? Yii::$app->user : null;
        $identity = $webUser?->getIdentity(false);
        $webUser?->setIdentity(null);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $work();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        } finally {
            $webUser?->setIdentity($identity);
        }
    }
}
