<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\components\Format;
use app\components\Rules;
use app\components\StatusTransition;
use app\models\Alert;
use app\models\Incident;
use app\models\Obligation;
use app\models\ObligationSubmission;
use app\models\ObligationTask;
use app\models\StatusHistory;
use app\models\User;
use Yii;
use yii\db\Query;
use yii\web\UploadedFile;

/**
 * The statutory obligation register (Phase 5B).
 *
 * History comes from the data track (data/generators/gen_obligations.py). This service only
 * creates the tasks of **new** periods, on the same schedule (periods() mirrors the generator's;
 * ObligationScheduleTest proves they agree), and runs the register:
 *
 *   submit    the mine head uploads evidence (file + note)          -> submitted
 *   review    government or inspector accepts, or rejects with a reason
 *   check     reminders (OBLIGATION_DUE_SOON, one per mine and due time, reminder_days ahead),
 *             overdue (OBLIGATION_OVERDUE, level 1) and escalation (level 2 after
 *             escalate_after_hours) - rules.yaml product.obligation_schedule; idempotent; a system
 *             action; runs on reads of the register and in `yii obligation/check`.
 *
 * Statutory compliance is a separate metric - the compliance score formula is untouched: the share
 * of tasks due in the last `window` days (default 90) whose evidence was submitted by the due time
 * and accepted.
 */
final class ObligationService
{
    public const WINDOW_DAYS = 90;
    private const IST = '+05:30';

    /** The register's data version: any write bumps it, so cached summaries are never stale. */
    public static function version(): int
    {
        return (int) (Yii::$app->cache->get('obligation.version') ?: 0);
    }

    public static function bump(): void
    {
        Yii::$app->cache->set('obligation.version', self::version() + 1);
    }

    public static function settings(): array
    {
        return Rules::value('product', 'obligation_schedule');
    }

    /**
     * Periods of `schedule` that start on or before $last and fall due on or after $first:
     * [label, start, end, dueDay, basis]. Mirrors gen_obligations.periods().
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function periods(string $schedule, string $code, string $first, string $last): array
    {
        $d = fn(string $s) => new \DateTimeImmutable($s);
        $f = fn(\DateTimeImmutable $x) => $x->format('Y-m-d');
        $out = [];
        if ($schedule === 'weekly' || $schedule === 'fortnightly') {
            $firstDay = $d($first);
            $monday = $firstDay->modify('-' . ((int) $firstDay->format('N') - 1) . ' days')->modify('-14 days');
            while ($monday <= $d($last)) {
                $y = (int) $monday->format('o');
                $w = (int) $monday->format('W');
                if ($schedule === 'weekly') {
                    $end = $monday->modify('+6 days');
                    $out[] = [sprintf('%d-W%02d', $y, $w), $f($monday), $f($end), $f($end), 'product'];
                } elseif ($w % 2 === 1) {
                    $weeksInYear = (int) (new \DateTimeImmutable("$y-12-28"))->format('W');
                    $end = $monday->modify($w < $weeksInYear ? '+13 days' : '+6 days');
                    $out[] = [sprintf('%d-F%02d', $y, intdiv($w + 1, 2)), $f($monday), $f($end), $f($end), 'product'];
                }
                $monday = $monday->modify('+7 days');
            }
        } else {
            for ($y = (int) substr($first, 0, 4) - 1; $y <= (int) substr($last, 0, 4) + 1; $y++) {
                if ($schedule === 'monthly') {
                    for ($m = 1; $m <= 12; $m++) {
                        $start = $d(sprintf('%d-%02d-01', $y, $m));
                        $end = $start->modify('last day of this month');
                        $out[] = [sprintf('%d-%02d', $y, $m), $f($start), $f($end), $f($end), 'product'];
                    }
                } elseif ($schedule === 'quarterly') {
                    for ($q = 0; $q < 4; $q++) {
                        $start = $d(sprintf('%d-%02d-01', $y, 3 * $q + 1));
                        $end = $start->modify('+2 months')->modify('last day of this month');
                        $out[] = [sprintf('%d-Q%d', $y, $q + 1), $f($start), $f($end), $f($end), 'product'];
                    }
                } elseif ($schedule === 'half_yearly') {
                    $out[] = ["$y-H1", "$y-01-01", "$y-06-30", "$y-06-30", 'product'];
                    $out[] = ["$y-H2", "$y-07-01", "$y-12-31", "$y-12-31", 'product'];
                } elseif ($code === 'ENV-03') {
                    // "on or before 30 September, for the financial year ending 31 March"
                    $out[] = [sprintf('FY%d-%s', $y, substr((string) ($y + 1), 2)), "$y-04-01", ($y + 1) . '-03-31', ($y + 1) . '-09-30', 'law'];
                } elseif ($code === 'RPT-06') {
                    // "on or before 28/29 February following each calendar year"
                    $out[] = ["$y", "$y-01-01", "$y-12-31", $f($d(($y + 1) . '-03-01')->modify('-1 day')), 'law'];
                } else {
                    $out[] = ["$y", "$y-01-01", "$y-12-31", "$y-12-31", 'product'];
                }
            }
        }
        return array_values(array_filter($out, fn($p) => $p[1] <= $last && $p[3] >= $first));
    }

    /** 23:59:59 IST on a day, as a UTC SQL timestamp. */
    public static function dueAt(string $day): string
    {
        return Format::sql(new \DateTimeImmutable("$day 23:59:59" . self::IST));
    }

    /**
     * Create the tasks of periods that have started by $now and are not there yet, for every
     * applicable (mine, obligation). History is never back-filled further than the lookback.
     */
    public static function generate(\DateTimeImmutable $now, int $lookbackDays = 31): int
    {
        $first = $now->modify("-{$lookbackDays} days")->format('Y-m-d');
        $last = $now->format('Y-m-d');
        $rows = [];
        $pairs = (new Query())->select(['a.mine_id', 'a.obligation_id', 'o.code', 'o.schedule'])
            ->from(['a' => '{{%obligation_applicability}}'])->innerJoin(['o' => '{{%obligation}}'], 'o.id = a.obligation_id')
            ->where(['o.generates_tasks' => true])->all();
        $periodsOf = [];
        foreach ($pairs as $p) {
            $periodsOf[$p['code']] ??= self::periods($p['schedule'], $p['code'], $first, $last);
            foreach ($periodsOf[$p['code']] as [$label, $start, $end, $dueDay, $basis]) {
                $rows[] = [(int) $p['mine_id'], (int) $p['obligation_id'], $label, $start, $end, self::dueAt($dueDay), $basis,
                    Format::sql(max(new \DateTimeImmutable("$start 00:00:00" . self::IST), $now->modify("-{$lookbackDays} days")))];
            }
        }
        if ($rows === []) {
            return 0;
        }
        $created = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $sql = Yii::$app->db->queryBuilder->batchInsert('{{%obligation_task}}',
                ['mine_id', 'obligation_id', 'period', 'period_start', 'period_end', 'due_at', 'due_basis', 'created_at'], $chunk);
            $created += Yii::$app->db->createCommand($sql . ' ON CONFLICT (mine_id, obligation_id, period) DO NOTHING')->execute();
        }
        return $created;
    }

    /** The mine head's evidence: a file and a note. Also late, or again after a rejection. */
    public static function submit(ObligationTask $task, User $by, ?UploadedFile $file, string $note): ObligationSubmission
    {
        if ($file === null) {
            throw ApiException::fields(['file' => ['REQUIRED']]);
        }
        $note = trim($note);
        if (mb_strlen($note) > 1000) {
            throw ApiException::fields(['note' => ['TOO_LONG']]);
        }
        if (!StatusTransition::canTransition($task, 'submitted')) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $task->status, 'to' => 'submitted']);
        }
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $submission = new ObligationSubmission([
                'task_id' => $task->id, 'file_id' => 0, 'note' => $note === '' ? null : $note, 'submitted_by' => $by->id,
                'submitted_at' => Format::sql(Format::now()), 'status' => 'pending',
            ]);
            $stored = Yii::$app->fileStorage->storeUpload($file, 'obligation_submission', 0, (int) $by->id);
            $submission->file_id = $stored->id;
            $submission->save(false);
            $stored->entity_id = $submission->id;
            $stored->save(false);
            StatusTransition::apply($task, 'submitted', ['submission_id' => (int) $submission->id]);
            $transaction->commit();
            self::bump();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $submission;
    }

    /** Government or inspector: accept, or reject with a reason (the mine then submits again). */
    public static function review(ObligationSubmission $submission, User $by, string $decision, string $note): ObligationSubmission
    {
        if (!in_array($decision, ['accept', 'reject'], true)) {
            throw ApiException::fields(['decision' => ['INVALID_VALUE']]);
        }
        $note = trim($note);
        if ($decision === 'reject' && mb_strlen($note) < 5) {
            throw ApiException::fields(['note' => [$note === '' ? 'REASON_REQUIRED' : 'TOO_SHORT']]);
        }
        if (mb_strlen($note) > 1000) {
            throw ApiException::fields(['note' => ['TOO_LONG']]);
        }
        if ($submission->status !== 'pending') {
            throw new ApiException(422, 'ALREADY_REVIEWED', ['status' => $submission->status]);
        }
        $task = $submission->task;
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $submission->status = $decision === 'accept' ? 'accepted' : 'rejected';
            $submission->reviewed_by = $by->id;
            $submission->reviewed_at = Format::sql(Format::now());
            $submission->review_note = $note === '' ? null : $note;
            $submission->save(false);
            if ($decision === 'accept') {
                $task->accepted_at = $submission->reviewed_at;
                StatusTransition::apply($task, 'accepted', ['submission_id' => (int) $submission->id]);
                foreach (self::openAlerts('OBLIGATION_OVERDUE', [(int) $task->id]) as $alert) {
                    StatusTransition::apply($alert, Alert::STATUS_RESOLVED, ['code' => 'EVIDENCE_ACCEPTED', 'task_id' => (int) $task->id]);
                }
            } else {
                StatusTransition::apply($task, 'rejected', ['submission_id' => (int) $submission->id, 'reason' => $note]);
            }
            $transaction->commit();
            self::bump();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $submission;
    }

    /**
     * An incident's reporting task (owner, 2026-09-28): its obligation (incident.obligation_code,
     * RPT-03 / RPT-04 / RPT-05), due incident_notice_hours after it occurred (a product setting), done
     * at reported_at - the incident record is the report, so there is no upload and no review. Called
     * in the incident's transaction; the seeded incidents' tasks come from the data track.
     */
    public static function recordIncident(Incident $incident): ObligationTask
    {
        $obligation = Obligation::findOne(['code' => $incident->obligation_code]);
        if ($obligation === null) {
            throw new \RuntimeException("obligation {$incident->obligation_code} is not in the catalogue");
        }
        $occurred = new \DateTimeImmutable((string) $incident->occurred_at, new \DateTimeZone('UTC'));
        $day = $occurred->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
        $task = new ObligationTask([
            'mine_id' => $incident->mine_id, 'obligation_id' => $obligation->id,
            'period' => sprintf('INC-%06d', $incident->id), 'period_start' => $day, 'period_end' => $day,
            'due_at' => Format::sql($occurred->modify('+' . (int) self::settings()['incident_notice_hours'] . ' hours')),
            'due_basis' => 'product', 'status' => 'accepted', 'escalation_level' => 0,
            'accepted_at' => Format::sql(new \DateTimeImmutable((string) $incident->reported_at, new \DateTimeZone('UTC'))),
            'created_at' => Format::sql(Format::now()), 'incident_id' => $incident->id,
        ]);
        $task->save(false);
        StatusHistory::record($task, 'open', 'accepted', null, ['code' => 'INCIDENT_REPORTED', 'incident_id' => (int) $incident->id]);
        self::bump();
        return $task;
    }

    /**
     * Government only: waive a task that has no accepted evidence (the mine is not bound for this
     * period - e.g. the mine was closed). A reason is required; it is kept in the task's history.
     * Waived tasks leave statutory compliance, and their overdue alert is resolved.
     */
    public static function waive(ObligationTask $task, string $reason): ObligationTask
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ApiException::fields(['reason' => [$reason === '' ? 'REASON_REQUIRED' : 'TOO_SHORT']]);
        }
        if (mb_strlen($reason) > 1000) {
            throw ApiException::fields(['reason' => ['TOO_LONG']]);
        }
        if (!StatusTransition::canTransition($task, 'waived')) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $task->status, 'to' => 'waived']);
        }
        $transaction = Yii::$app->db->beginTransaction();
        try {
            StatusTransition::apply($task, 'waived', ['reason' => $reason]);
            foreach (self::openAlerts('OBLIGATION_OVERDUE', [(int) $task->id]) as $alert) {
                StatusTransition::apply($alert, Alert::STATUS_RESOLVED, ['code' => 'OBLIGATION_WAIVED', 'task_id' => (int) $task->id]);
            }
            $transaction->commit();
            self::bump();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $task;
    }

    /**
     * Reminders, overdue and escalation - a system action (no user in the history or the audit
     * chain); idempotent. @return array{created: int, reminders: int, overdue: int, escalated: int}
     */
    /**
     * check(), at most once per web request: a screen view reads several parts, each of which
     * would run it. The marker lives on the application, which is new for every request.
     */
    public static function checkForRequest(): array
    {
        $app = Yii::$app;
        if (!empty($app->params['obligation.checked'])) {
            return ['created' => 0, 'reminders' => 0, 'overdue' => 0, 'escalated' => 0];
        }
        $app->params['obligation.checked'] = true;
        return self::check();
    }

    public static function check(?\DateTimeImmutable $now = null): array
    {
        $now ??= Format::now();
        $cfg = self::settings();
        $escAfter = (int) $cfg['escalate_after_hours'];
        $done = ['created' => 0, 'reminders' => 0, 'overdue' => 0, 'escalated' => 0];
        $sqlNow = Format::sql($now);
        $escBefore = Format::sql($now->modify("-{$escAfter} hours"));
        $unresolvedOverdue = (new Query())->select('entity_id')->from('{{%alert}}')
            ->where(['code' => 'OBLIGATION_OVERDUE', 'entity_type' => 'obligation_task'])->andWhere(['<>', 'status', Alert::STATUS_RESOLVED]);

        // What needs doing - cheap queries, so a check with nothing to do costs a few milliseconds.
        $late = ObligationTask::find()->where(['or',
            ['and', ['status' => ['open', 'rejected']], ['<', 'due_at', $sqlNow]],                  // becomes overdue
            ['and', ['status' => 'overdue'], ['<', 'due_at', $escBefore]],                           // becomes escalated
            ['and', ['status' => ['overdue', 'escalated']], ['not in', 'id', $unresolvedOverdue]],  // needs its alert
        ])->with('obligation')->orderBy('id')->all();
        $soon = (new Query())->select(['t.mine_id', 't.due_at', 'codes' => "string_agg(o.code, ',' ORDER BY o.code)", 'ids' => "string_agg(t.id::text, ',' ORDER BY t.id)"])
            ->from(['t' => '{{%obligation_task}}'])->innerJoin(['o' => '{{%obligation}}'], 'o.id = t.obligation_id')
            ->where(['t.status' => ['open', 'rejected']])->andWhere(['>=', 't.due_at', $sqlNow])
            ->andWhere(['<', 't.due_at', Format::sql($now->modify('+' . (int) $cfg['reminder_days'] . ' days'))])
            ->andWhere(['not exists', (new Query())->from(['a' => '{{%alert}}'])->where(['a.code' => 'OBLIGATION_DUE_SOON'])
                ->andWhere('a.mine_id = t.mine_id')->andWhere("a.params->>'due_at' = to_char(t.due_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')")])
            ->groupBy(['t.mine_id', 't.due_at'])->all();
        $stale = Alert::find()->where(['code' => 'OBLIGATION_DUE_SOON'])->andWhere(['<>', 'status', Alert::STATUS_RESOLVED])
            ->andWhere(['<', new \yii\db\Expression("params->>'due_at'"), Format::utc($sqlNow)])->all();
        // New periods' tasks: at most once a day (a new period starts on a date).
        $generateKey = 'obligation.generated.' . $now->format('Y-m-d');
        $generate = !Yii::$app->cache->exists($generateKey);
        if ($late === [] && $soon === [] && $stale === [] && !$generate) {
            return $done;
        }

        $webUser = Yii::$app->has('user', true) ? Yii::$app->user : null;
        $identity = $webUser?->getIdentity(false);
        $webUser?->setIdentity(null);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if ($generate) {
                $done['created'] = self::generate($now);
            }
            if ($late !== []) {
                $alerts = [];
                foreach (self::openAlerts('OBLIGATION_OVERDUE', array_map(fn($t) => (int) $t->id, $late)) as $a) {
                    $alerts[(int) $a->entity_id] = $a;
                }
                foreach ($late as $task) {
                    if (in_array($task->status, ['open', 'rejected'], true)) {
                        $task->escalation_level = 1;
                        StatusTransition::apply($task, 'overdue', ['code' => 'DUE_TIME_PASSED']);
                        $done['overdue']++;
                    }
                    if ($task->status === 'overdue' && strtotime((string) $task->due_at) < $now->getTimestamp() - $escAfter * 3600) {
                        $task->escalation_level = 2;
                        StatusTransition::apply($task, 'escalated', ['code' => 'ESCALATED', 'after_hours' => $escAfter]);
                        $done['escalated']++;
                    }
                    $alert = $alerts[(int) $task->id] ?? AlertService::create((int) $task->mine_id, 'OBLIGATION_OVERDUE', 'high', 'obligation_task', (int) $task->id, [
                        'task_id' => (int) $task->id, 'obligation' => $task->obligation->code, 'period' => $task->period,
                        'due_at' => Format::utc($task->due_at), 'instrument' => $task->obligation->instrument, 'clause' => $task->obligation->clause,
                    ]);
                    if ((int) $alert->escalation_level !== (int) $task->escalation_level) {
                        $alert->escalation_level = (int) $task->escalation_level;
                        $alert->save(false);
                    }
                }
            }
            // Reminders: one per mine and due time, for tasks due within reminder_days with nothing submitted.
            foreach ($soon as $r) {
                $codes = explode(',', $r['codes']);
                AlertService::create((int) $r['mine_id'], 'OBLIGATION_DUE_SOON', 'medium', 'mine', (int) $r['mine_id'], [
                    'due_at' => Format::utc($r['due_at']), 'count' => count($codes), 'obligations' => $codes,
                    'task_ids' => array_map('intval', explode(',', $r['ids'])),
                ]);
                $done['reminders']++;
            }
            // A reminder whose due time has passed has done its job (the overdue alert takes over).
            foreach ($stale as $a) {
                StatusTransition::apply($a, Alert::STATUS_RESOLVED, ['code' => 'DUE_TIME_PASSED']);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        } finally {
            $webUser?->setIdentity($identity);
        }
        if ($generate) {
            Yii::$app->cache->set($generateKey, true, 2 * 86400);
        }
        self::bump();
        return $done;
    }

    /**
     * Statutory compliance per mine and per company over the tasks due in the last WINDOW_DAYS,
     * plus the most overdue open items. Separate from the compliance score.
     * @param int[] $mineIds
     */
    public static function summary(array $mineIds, ?\DateTimeImmutable $now = null, int $mostOverdue = 20): array
    {
        if ($now === null) {
            // Cached per mine set and data version; the window moves with the hour.
            sort($mineIds);
            $key = 'obligation.summary.' . self::version() . '.' . gmdate('YmdH') . '.' . $mostOverdue . '.' . md5(implode(',', $mineIds));
            $hit = Yii::$app->cache->get($key);
            if ($hit !== false) {
                return $hit;
            }
            $result = self::summary($mineIds, Format::now(), $mostOverdue);
            Yii::$app->cache->set($key, $result, 3600);
            return $result;
        }
        $from = Format::sql($now->modify('-' . self::WINDOW_DAYS . ' days'));
        $to = Format::sql($now);
        if ($mineIds === []) {
            return ['window_days' => self::WINDOW_DAYS, 'totals' => self::row([]), 'by_mine' => [], 'by_company' => [], 'by_domain' => [], 'most_overdue' => []];
        }
        // One grouped query (mine x domain); the per-mine, per-company, per-domain and fleet figures
        // are sums of its rows.
        // When the duty was done: the first upload, or for an incident's reporting task (no upload -
        // the incident record is the report) the time it was reported, kept as accepted_at.
        $first = (new Query())->select(['task_id', 'at' => 'min(submitted_at)'])->from('{{%obligation_submission}}')->groupBy('task_id');
        $cells = (new Query())->from(['t' => '{{%obligation_task}}'])
            ->innerJoin(['o' => '{{%obligation}}'], 'o.id = t.obligation_id')
            ->leftJoin(['f' => $first], 'f.task_id = t.id')
            ->where(['t.mine_id' => $mineIds])->andWhere(['between', 't.due_at', $from, $to])->andWhere(['<>', 't.status', 'waived'])
            ->select([
                'mine_id' => 't.mine_id', 'domain' => 'o.domain',
                'due' => 'count(*)',
                'on_time' => "count(*) FILTER (WHERE t.status = 'accepted' AND coalesce(f.at, t.accepted_at) <= t.due_at)",
                'late_accepted' => "count(*) FILTER (WHERE t.status = 'accepted' AND coalesce(f.at, t.accepted_at) > t.due_at)",
                'awaiting_review' => "count(*) FILTER (WHERE t.status = 'submitted')",
                'overdue' => "count(*) FILTER (WHERE t.status IN ('overdue', 'rejected'))",
                'escalated' => "count(*) FILTER (WHERE t.status = 'escalated')",
            ])->groupBy(['t.mine_id', 'o.domain'])->all();
        $mines = (new Query())->select(['m.id', 'm.code', 'm.name', 'm.state', 'subsidiary_code' => 's.code', 'subsidiary_name' => 's.name'])
            ->from(['m' => '{{%mine}}'])->leftJoin(['s' => '{{%subsidiary}}'], 's.id = m.subsidiary_id')->where(['m.id' => $mineIds])->indexBy('id')->all();
        $keys = ['due', 'on_time', 'late_accepted', 'awaiting_review', 'overdue', 'escalated'];
        $sum = function (?array &$into, array $r) use ($keys): void {
            foreach ($keys as $k) {
                $into[$k] = ($into[$k] ?? 0) + (int) $r[$k];
            }
        };
        $perMine = $perCompany = $perDomain = [];
        $total = [];
        foreach ($cells as $r) {
            $m = $mines[(int) $r['mine_id']];
            $sum($perMine[(int) $r['mine_id']], $r);
            $sum($perCompany[(string) $m['subsidiary_code']], $r);
            $sum($perDomain[$r['domain']], $r);
            $sum($total, $r);
        }
        $byMine = [];
        foreach ($perMine as $id => $r) {
            $m = $mines[$id];
            $byMine[] = ['mine_id' => $id, 'code' => $m['code'], 'name' => $m['name'], 'state' => $m['state'], 'company' => $m['subsidiary_code']] + self::row($r);
        }
        usort($byMine, fn($a, $b) => [$a['compliance_pct'] ?? 101, $a['mine_id']] <=> [$b['compliance_pct'] ?? 101, $b['mine_id']]);
        $companyName = array_column($mines, 'subsidiary_name', 'subsidiary_code');
        $byCompany = [];
        foreach ($perCompany as $code => $r) {
            $byCompany[] = ['company' => $code, 'name' => $companyName[$code] ?? null] + self::row($r);
        }
        usort($byCompany, fn($a, $b) => [$a['compliance_pct'] ?? 101, $a['company']] <=> [$b['compliance_pct'] ?? 101, $b['company']]);
        $byDomain = [];
        foreach ($perDomain as $domain => $r) {
            $byDomain[] = ['domain' => $domain] + self::row($r);
        }
        usort($byDomain, fn($a, $b) => $a['domain'] <=> $b['domain']);
        $overdue = ObligationTask::find()->where(['mine_id' => $mineIds, 'status' => ['overdue', 'escalated']])
            ->with(['obligation', 'mine', 'latestSubmission.submitter', 'latestSubmission.reviewer', 'latestSubmission.file'])
            ->orderBy(['due_at' => SORT_ASC, 'id' => SORT_ASC])->limit($mostOverdue)->all();
        return [
            'window_days' => self::WINDOW_DAYS,
            'totals' => self::row($total),
            'by_mine' => $byMine,
            'by_company' => $byCompany,
            'by_domain' => $byDomain,
            'most_overdue' => array_map(fn(ObligationTask $t) => $t->toArray(), $overdue),
            'open_overdue' => (int) ObligationTask::find()->where(['mine_id' => $mineIds, 'status' => ['overdue', 'escalated']])->count(),
            'pending_review' => (int) ObligationTask::find()->where(['mine_id' => $mineIds, 'status' => 'submitted'])->count(),
        ];
    }

    private static function row(array $r): array
    {
        $due = (int) ($r['due'] ?? 0);
        return [
            'due' => $due, 'on_time' => (int) ($r['on_time'] ?? 0), 'late_accepted' => (int) ($r['late_accepted'] ?? 0),
            'awaiting_review' => (int) ($r['awaiting_review'] ?? 0), 'overdue' => (int) ($r['overdue'] ?? 0), 'escalated' => (int) ($r['escalated'] ?? 0),
            'compliance_pct' => $due ? round(100 * (int) $r['on_time'] / $due, 1) : null,
        ];
    }

    /** @return Alert[] unresolved alerts of this code for these tasks */
    private static function openAlerts(string $code, array $taskIds): array
    {
        return Alert::find()->where(['code' => $code, 'entity_type' => 'obligation_task', 'entity_id' => $taskIds])
            ->andWhere(['<>', 'status', Alert::STATUS_RESOLVED])->all();
    }
}
