<?php

declare(strict_types=1);

namespace app\services;

use app\components\AccessRule;
use app\components\ApiException;
use app\components\Format;
use app\components\Rules;
use app\components\StatusTransition;
use app\models\Alert;
use app\models\Grievance;
use app\models\GrievanceAction;
use app\models\Mine;
use app\models\Observation;
use app\models\User;
use Yii;
use yii\db\Expression;
use yii\db\Query;
use yii\web\UploadedFile;

/**
 * Grievance handling (brief Phase 5).
 *
 * SLA per category: rules.yaml product.grievance_sla_hours (product settings, not law). A grievance
 * still open at its sla_due_at is an SLA breach: escalation level 1, a grievance_action "escalate"
 * and GRIEVANCE_SLA_BREACHED; still open product.grievance_escalate_again_after_sla SLA periods
 * later, level 2. A reopened grievance gets a new SLA period. A breach cluster is a mine with at
 * least product.grievance_breach_cluster.min_breaches grievances raised within window_days that all
 * breached their SLA.
 *
 * Routing: a sensitive grievance (harassment, or against the mine head) is assigned to the
 * government; any other to the mine's head. A safety grievance that is not sensitive creates an
 * observation (status open) for the inspection flow; a sensitive one does not, because an
 * observation is visible to the mine head - the government handles it directly.
 */
final class GrievanceService
{
    /** Initial severity by category, until staff know more (product setting). */
    public const SEVERITY_FOR = [
        'safety' => 'high', 'harassment' => 'high', 'wages' => 'medium', 'working_conditions' => 'medium',
        'environment' => 'medium', 'land_compensation' => 'low', 'other' => 'low',
    ];

    /** Tracking codes: 8 characters with no look-alikes (no 0/O, 1/I/L) - as in the demo data. */
    public const TRACKING_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** A new tracking code from the CSPRNG; shown to the complainant once, stored only as an HMAC. */
    public static function newTrackingCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::TRACKING_ALPHABET[random_int(0, strlen(self::TRACKING_ALPHABET) - 1)];
        }
        return $code;
    }

    /** HMAC-SHA256 of a tracking code, keyed from the application secret (never stored in clear). */
    public static function trackingHash(string $code): string
    {
        $secret = (string) env('JWT_SECRET', '');
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET is not set (api/.env)');
        }
        $normalised = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        return hash_hmac('sha256', $normalised, hash_hmac('sha256', 'grievance-tracking-code', $secret));
    }

    public static function slaHours(string $category): int
    {
        return (int) (Rules::value('product', 'grievance_sla_hours')[$category] ?? 168);
    }

    /**
     * A grievance from the public form. The honeypot must be empty; the caller has been
     * rate-limited already.
     */
    /** @return array{0: Grievance, 1: string} the grievance and its tracking code (shown once) */
    public static function submitPublic(array $data, ?UploadedFile $file): array
    {
        if (trim((string) ($data['website'] ?? '')) !== '') {
            throw new ApiException(400, 'SUBMISSION_REJECTED');   // honeypot: a field people never see
        }
        $anonymous = filter_var($data['is_anonymous'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ($data['submitter_type'] ?? '') === 'anonymous';
        $category = (string) ($data['category'] ?? '');
        $errors = [];
        $mine = null;
        if (!isset($data['mine_id']) || !ctype_digit((string) $data['mine_id'])
            || ($mine = Mine::find()->where(['mine.id' => (int) $data['mine_id']])->one()) === null) {
            $errors['mine_id'] = ['REQUIRED'];
        }
        $grievance = new Grievance([
            'mine_id' => $mine?->id,
            'submitter_type' => $anonymous ? 'anonymous' : (string) ($data['submitter_type'] ?? ''),
            'is_anonymous' => $anonymous,
            'name' => $anonymous ? null : (self::clean($data['name'] ?? null)),
            'contact' => $anonymous ? null : (self::clean($data['contact'] ?? null)),
            'category' => $category,
            'severity' => self::SEVERITY_FOR[$category] ?? 'medium',
            'language' => (string) ($data['language'] ?? 'en'),
            'description' => trim((string) ($data['description'] ?? '')),
            'against_mine_head' => filter_var($data['against_mine_head'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'status' => 'received',
            'escalation_level' => 0,
        ]);
        if ($grievance->submitter_type === 'anonymous' && !$anonymous) {
            $grievance->is_anonymous = true;
        }
        $grievance->validate();
        foreach ($grievance->getErrors() as $field => $codes) {
            $errors[$field] = $codes;
        }
        unset($errors['mine_id']) ;
        if ($mine === null) {
            $errors['mine_id'] = ['REQUIRED'];
        }
        $point = self::point($data, $errors);
        $safetyCategory = null;
        if ($category === 'safety') {
            $safetyCategory = (string) ($data['safety_category'] ?? '');
            if (!in_array($safetyCategory, Rules::categories(), true)) {
                $errors['safety_category'] = ['REQUIRED'];
            }
        }
        if ($file !== null) {
            $params = Yii::$app->params;
            if ($file->size > $params['grievance.maxFileBytes']) {
                $errors['file'] = ['FILE_TOO_LARGE'];
            } elseif (!in_array((new \finfo(FILEINFO_MIME_TYPE))->file($file->tempName) ?: '', $params['grievance.fileMimeTypes'], true)) {
                $errors['file'] = ['FILE_TYPE_NOT_ALLOWED'];
            }
        }
        if ($errors !== []) {
            throw ApiException::fields($errors);
        }

        $now = Format::now();
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $grievance->ticket_no = (string) Yii::$app->db->createCommand('SELECT next_grievance_ticket(:y)', [':y' => (int) $now->format('Y')])->queryScalar();
            $trackingCode = self::newTrackingCode();
            $grievance->tracking_code_hash = self::trackingHash($trackingCode);
            $grievance->created_at = Format::sql($now);
            $grievance->sla_due_at = Format::sql($now->modify('+' . self::slaHours($category) . ' hours'));
            $grievance->assigned_to = self::routeTo($grievance);
            if ($point !== null) {
                $grievance->location = new Expression('ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)', [':lon' => $point[0], ':lat' => $point[1]]);
            }
            $grievance->save(false);
            if ($file !== null) {
                $stored = Yii::$app->fileStorage->storeUpload($file, 'grievance', (int) $grievance->id, null);
                $grievance->file_id = $stored->id;
                $grievance->save(false, ['file_id']);
            }
            GrievanceAction::record($grievance, 'submit', null, 'received', null, null);
            if ($category === 'safety' && !$grievance->isSensitive()) {
                $observation = new Observation([
                    'mine_id' => $grievance->mine_id, 'grievance_id' => $grievance->id, 'category' => $safetyCategory,
                    'severity' => 'high', 'status' => 'open', 'observed_at' => Format::sql($now),
                ]);
                if (!$observation->save()) {
                    throw ApiException::validation($observation);
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return [$grievance, $trackingCode];
    }

    /** What the public may see about a ticket: status and when things happened - no people, no text. */
    public static function track(string $ticket, string $code): array
    {
        $ticket = strtoupper(trim($ticket));
        $grievance = preg_match('/^GRV-\d{4}-\d{6}$/', $ticket) ? Grievance::find()->where(['ticket_no' => $ticket])->with('actions')->one() : null;
        // The code is checked even when the ticket does not exist (against a dummy), in constant
        // time: an unknown ticket and a wrong code take the same path and give the same 404.
        $stored = $grievance?->tracking_code_hash ?? str_repeat('0', 64);
        $matches = hash_equals($stored, self::trackingHash($code));
        if ($grievance === null || $grievance->tracking_code_hash === null || !$matches) {
            throw ApiException::notFound();
        }
        return [
            'ticket_no' => $grievance->ticket_no,
            'category' => $grievance->category,
            'status' => $grievance->status,
            'created_at' => Format::utc($grievance->created_at),
            'sla_due_at' => Format::utc($grievance->sla_due_at),
            'is_overdue' => $grievance->isOpen() && strtotime((string) $grievance->sla_due_at) < time(),
            'resolution_note' => in_array($grievance->status, Grievance::DONE, true) ? $grievance->resolution_note : null,
            'timeline' => array_values(array_map(fn(GrievanceAction $a) => $a->publicFields(),
                array_filter($grievance->actions, fn(GrievanceAction $a) => $a->action !== 'assign'))),
        ];
    }

    /** A status change by staff; resolving needs a note, reopening starts a new SLA period. */
    public static function transition(Grievance $grievance, string $to, ?string $note): Grievance
    {
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 2000) {
            throw ApiException::fields(['note' => ['TOO_LONG']]);
        }
        if (!in_array($to, array_keys(Grievance::ACTION_FOR), true)) {
            throw ApiException::fields(['to' => ['INVALID_VALUE']]);
        }
        if ($to === 'resolved') {
            if ($note === null || mb_strlen($note) < 5) {
                throw ApiException::fields(['note' => [$note ? 'TOO_SHORT' : 'REQUIRED']]);
            }
            $grievance->resolution_note = $note;
        }
        if ($to === 'reopened') {
            $grievance->sla_due_at = Format::sql(Format::now()->modify('+' . self::slaHours($grievance->category) . ' hours'));
            $grievance->escalation_level = 0;
        }
        StatusTransition::apply($grievance, $to, array_filter(['note' => $note ?: null]));
        return $grievance;
    }

    /** Hand a grievance to someone who may see it (AccessRule decides who that is). */
    public static function assign(Grievance $grievance, int $userId, User $by): Grievance
    {
        $assignee = in_array($userId, array_column(self::assignees($grievance), 'id'), true) ? User::findOne($userId) : null;
        if ($assignee === null) {
            throw ApiException::fields(['user_id' => ['INVALID_VALUE']]);
        }
        $grievance->assigned_to = $assignee->id;
        $grievance->save(false, ['assigned_to']);
        GrievanceAction::record($grievance, 'assign', $grievance->status, $grievance->status, $assignee->full_name, (int) $by->id);
        return $grievance;
    }

    /** @return list<array{id: int, name: string, role: string}> users who may handle this grievance */
    public static function assignees(Grievance $grievance): array
    {
        $mine = Mine::find()->where(['mine.id' => $grievance->mine_id])->one();
        $query = User::find()->where(['status' => 'active'])->andWhere(['or',
            ['role' => [User::ROLE_GOVERNMENT, User::ROLE_INSPECTOR]],
            $grievance->isSensitive() ? '0=1' : ['and', ['role' => User::ROLE_CORPORATE], ['subsidiary_id' => $mine?->subsidiary_id]],
            $grievance->isSensitive() ? '0=1' : ['and', ['role' => User::ROLE_MINE_HEAD], ['mine_id' => $grievance->mine_id]],
        ])->orderBy(['role' => SORT_ASC, 'id' => SORT_ASC]);
        return array_map(fn(User $u) => ['id' => (int) $u->id, 'name' => $u->full_name, 'role' => $u->role], $query->all());
    }

    /**
     * SLA breaches and second escalations, as a system action (no user in the timeline or the
     * audit chain). Idempotent. @return array{breached: int, escalated: int}
     */
    public static function escalateDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= Format::now();
        $again = (float) Rules::value('product', 'grievance_escalate_again_after_sla');
        $done = ['breached' => 0, 'escalated' => 0];
        $open = Grievance::find()->where(['status' => Grievance::OPEN])->andWhere(['<', 'sla_due_at', Format::sql($now)])
            ->andWhere(['<', 'escalation_level', 2])->orderBy('id')->all();
        // A reopened grievance runs on a new SLA period from its reopening (transition() sets that
        // for reopenings in the app; for the seeded history it is read from the timeline).
        $reopened = $open === [] ? [] : (new Query())->select(['at' => 'max(created_at)', 'grievance_id'])->from('{{%grievance_action}}')
            ->where(['action' => 'reopen', 'grievance_id' => array_map(fn($g) => (int) $g->id, $open)])
            ->groupBy('grievance_id')->indexBy('grievance_id')->column();
        $due = [];
        foreach ($open as $g) {
            $sla = self::slaHours($g->category);
            $dueAt = new \DateTimeImmutable($g->sla_due_at);
            if (isset($reopened[$g->id])) {
                $dueAt = max($dueAt, (new \DateTimeImmutable($reopened[$g->id]))->modify("+{$sla} hours"));
            }
            $secondAt = $dueAt->modify('+' . (int) round($again * $sla) . ' hours');
            $target = $secondAt < $now ? 2 : ($dueAt < $now ? 1 : 0);
            if ($target > (int) $g->escalation_level) {
                $due[] = [$g, $target];   // a grievance past both thresholds reaches level 2 in one pass
            }
        }
        if ($due === []) {
            return $done;
        }
        $webUser = Yii::$app->has('user', true) ? Yii::$app->user : null;
        $identity = $webUser?->getIdentity(false);
        $webUser?->setIdentity(null);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($due as [$g, $target]) {
                for ($level = (int) $g->escalation_level + 1; $level <= $target; $level++) {
                    $g->escalation_level = $level;
                    $g->save(false, ['escalation_level']);
                    GrievanceAction::record($g, 'escalate', $g->status, $g->status, 'LEVEL_' . $level, null);
                    $alert = Alert::find()->where(['code' => 'GRIEVANCE_SLA_BREACHED', 'entity_type' => 'grievance', 'entity_id' => $g->id])
                        ->andWhere(['<>', 'status', Alert::STATUS_RESOLVED])->one();
                    if ($alert === null) {
                        $alert = AlertService::create((int) $g->mine_id, 'GRIEVANCE_SLA_BREACHED', 'high', 'grievance', (int) $g->id,
                            ['grievance_id' => (int) $g->id, 'category' => $g->category, 'sla_due_at' => Format::utc($g->sla_due_at)]);
                    }
                    $alert->escalation_level = $level;
                    $alert->save(false, ['escalation_level']);
                    $done[$level === 1 ? 'breached' : 'escalated']++;
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        } finally {
            $webUser?->setIdentity($identity);
        }
        return $done;
    }

    /**
     * Analytics over the grievances a user may see at the given mines: totals, by category, by mine,
     * average resolution time (submission to first resolution), SLA breaches and breach clusters.
     * @param int[] $mineIds
     */
    public static function stats(User $user, array $mineIds): array
    {
        $base = fn() => Grievance::find()->forUser($user)->andWhere(['{{%grievance}}.mine_id' => $mineIds]);
        $firstResolve = (new Query())->select(['grievance_id', 'at' => 'min(created_at)'])->from('{{%grievance_action}}')
            ->where(['action' => 'resolve'])->groupBy('grievance_id');
        $agg = fn($query) => $query->select([
            'total' => 'count(*)',
            'open' => "count(*) FILTER (WHERE {{%grievance}}.status IN ('received', 'acknowledged', 'under_investigation', 'reopened'))",
            'breaches' => 'count(*) FILTER (WHERE {{%grievance}}.escalation_level >= 1)',
            'escalated_open' => "count(*) FILTER (WHERE {{%grievance}}.escalation_level >= 1 AND {{%grievance}}.status IN ('received', 'acknowledged', 'under_investigation', 'reopened'))",
            'avg_hours' => 'avg(extract(epoch FROM (r.at - {{%grievance}}.created_at)) / 3600)',
            'sensitive' => 'count(*) FILTER (WHERE ' . AccessRule::sensitiveGrievanceSql('{{%grievance}}') . ')',
        ])->leftJoin(['r' => $firstResolve], 'r.grievance_id = {{%grievance}}.id');
        $row = fn(array $r) => [
            'total' => (int) $r['total'], 'open' => (int) $r['open'], 'sla_breaches' => (int) $r['breaches'],
            'escalated_open' => (int) $r['escalated_open'],
            'breach_rate_pct' => (int) $r['total'] ? round(100 * $r['breaches'] / $r['total'], 1) : null,
            'avg_resolution_hours' => $r['avg_hours'] === null ? null : round((float) $r['avg_hours'], 1),
            'sensitive' => (int) $r['sensitive'],
        ];

        $totals = $row($agg($base())->createCommand()->queryOne());
        $byCategory = [];
        foreach ($agg($base())->addSelect(['category' => '{{%grievance}}.category'])->groupBy('{{%grievance}}.category')->createCommand()->queryAll() as $r) {
            $byCategory[] = ['category' => $r['category']] + $row($r);
        }
        usort($byCategory, fn($a, $b) => $b['total'] <=> $a['total']);
        $byLanguage = array_map('intval', $base()->select(['n' => 'count(*)', 'language' => '{{%grievance}}.language'])
            ->groupBy('{{%grievance}}.language')->indexBy('language')->column());

        $clusters = self::clusters($user, $mineIds);
        $mines = Mine::find()->where(['mine.id' => $mineIds])->indexBy('id')->all();
        $byMine = [];
        foreach ($agg($base())->addSelect(['mine_id' => '{{%grievance}}.mine_id'])->groupBy('{{%grievance}}.mine_id')->createCommand()->queryAll() as $r) {
            $mineId = (int) $r['mine_id'];
            $byMine[] = ['mine_id' => $mineId, 'code' => $mines[$mineId]->code ?? null, 'name' => $mines[$mineId]->name ?? null,
                'state' => $mines[$mineId]->state ?? null, 'cluster' => $clusters[$mineId] ?? null] + $row($r);
        }
        usort($byMine, fn($a, $b) => [$b['cluster'] !== null, $b['escalated_open'], $b['sla_breaches'], $a['mine_id']]
            <=> [$a['cluster'] !== null, $a['escalated_open'], $a['sla_breaches'], $b['mine_id']]);
        return [
            'totals' => $totals,
            'by_category' => $byCategory,
            'by_language' => $byLanguage,
            'by_mine' => $byMine,
            'clusters' => array_values(array_map(fn($c, $m) => ['mine_id' => $m, 'code' => $mines[$m]->code ?? null, 'name' => $mines[$m]->name ?? null] + $c,
                $clusters, array_keys($clusters))),
            'sla_hours' => Rules::value('product', 'grievance_sla_hours'),
            'cluster_rule' => Rules::value('product', 'grievance_breach_cluster'),
            'is_demo_value' => true,
        ];
    }

    /**
     * Breach clusters: per mine, the densest window (by submission time) of grievances that
     * breached their SLA; flagged when it holds at least min_breaches. @return array<int, array> by mine id
     */
    public static function clusters(User $user, array $mineIds): array
    {
        $rule = Rules::value('product', 'grievance_breach_cluster');
        $window = (int) $rule['window_days'] * 86400;
        $min = (int) $rule['min_breaches'];
        $byMine = [];
        foreach (Grievance::find()->forUser($user)->andWhere(['{{%grievance}}.mine_id' => $mineIds])
            ->andWhere(['>=', '{{%grievance}}.escalation_level', 1])->select(['{{%grievance}}.id', '{{%grievance}}.mine_id', '{{%grievance}}.created_at'])
            ->orderBy(['{{%grievance}}.created_at' => SORT_ASC])->asArray()->all() as $g) {
            $byMine[(int) $g['mine_id']][] = ['id' => (int) $g['id'], 't' => strtotime($g['created_at'])];
        }
        $out = [];
        foreach ($byMine as $mineId => $breaches) {
            $best = [];
            foreach ($breaches as $i => $start) {
                $in = array_values(array_filter($breaches, fn($b) => $b['t'] >= $start['t'] && $b['t'] < $start['t'] + $window));
                if (count($in) > count($best)) {
                    $best = $in;
                }
            }
            if (count($best) >= $min) {
                $out[$mineId] = [
                    'breaches' => count($best),
                    'from' => gmdate('Y-m-d', $best[0]['t']),
                    'to' => gmdate('Y-m-d', end($best)['t']),
                    'grievance_ids' => array_column($best, 'id'),
                    'window_days' => (int) $rule['window_days'],
                ];
            }
        }
        return $out;
    }

    /** Who a new grievance goes to: the government for a sensitive one, the mine's head otherwise. */
    private static function routeTo(Grievance $grievance): ?int
    {
        $role = $grievance->isSensitive() ? User::ROLE_GOVERNMENT : User::ROLE_MINE_HEAD;
        $query = User::find()->where(['role' => $role, 'status' => 'active'])->orderBy('id');
        if ($role === User::ROLE_MINE_HEAD) {
            $query->andWhere(['mine_id' => $grievance->mine_id]);
        }
        $user = $query->one();
        return $user === null ? null : (int) $user->id;
    }

    /** @return array{0: float, 1: float}|null lon, lat */
    private static function point(array $data, array &$errors): ?array
    {
        $lat = $data['latitude'] ?? null;
        $lon = $data['longitude'] ?? null;
        if (($lat === null || $lat === '') && ($lon === null || $lon === '')) {
            return null;
        }
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            $errors['location'] = ['INVALID_VALUE'];
            return null;
        }
        return [(float) $lon, (float) $lat];
    }

    private static function clean(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
