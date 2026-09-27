<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\HasStatusTransitions;
use app\components\ScopedActiveRecord;

/**
 * One due occurrence of an obligation for one mine (Phase 5B register).
 *
 *   open / rejected / overdue / escalated -> submitted (evidence uploaded)
 *   submitted -> accepted | rejected (government or inspector review)
 *   open / rejected -> overdue (due time passed; system) -> escalated (system)
 *
 * @property int $id
 * @property int $mine_id
 * @property int $obligation_id
 * @property string $period
 * @property string $period_start
 * @property string $period_end
 * @property string $due_at
 * @property string $due_basis
 * @property string $status
 * @property int $escalation_level
 * @property string|null $accepted_at
 * @property string $created_at
 */
class ObligationTask extends ScopedActiveRecord implements HasStatusTransitions
{
    public const AWAITING_EVIDENCE = ['open', 'rejected', 'overdue', 'escalated'];
    public const LATE = ['overdue', 'escalated'];

    public static function tableName(): string
    {
        return '{{%obligation_task}}';
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return [
            'open' => ['submitted', 'overdue', 'waived'],
            'rejected' => ['submitted', 'overdue', 'waived'],
            'overdue' => ['submitted', 'escalated', 'waived'],
            'escalated' => ['submitted', 'waived'],
            'submitted' => ['accepted', 'rejected'],
        ];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        StatusHistory::record($this, $from, $to, $userId, $context);
    }

    public function fields(): array
    {
        return [
            'id', 'mine_id',
            'mine_name' => fn() => $this->mine?->name,
            'mine_code' => fn() => $this->mine?->code,
            'obligation' => fn() => self::obligationArray($this->obligation),
            'period', 'period_start', 'period_end',
            'due_at' => fn() => Format::utc($this->due_at),
            'due_basis', 'status',
            'escalation_level' => fn() => (int) $this->escalation_level,
            'accepted_at' => fn() => Format::utc($this->accepted_at),
            'is_overdue' => fn() => in_array($this->status, self::LATE, true),
            'latest_submission' => fn() => $this->latestSubmission?->toArray(),
        ];
    }

    /** The obligation as serialised with a task - the same for every task of it, so built once. */
    private static function obligationArray(?Obligation $o): ?array
    {
        static $built = [];
        if ($o === null) {
            return null;
        }
        return $built[$o->id] ??= $o->toArray(['id', 'code', 'domain', 'title', 'evidence_type', 'frequency', 'due_rule', 'citation']);
    }

    public function extraFields(): array
    {
        return [
            'submissions' => fn() => array_map(fn(ObligationSubmission $s) => $s->toArray(), $this->submissions),
            'history' => fn() => StatusHistory::forEntity($this),
        ];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }

    public function getObligation()
    {
        return $this->hasOne(Obligation::class, ['id' => 'obligation_id']);
    }

    public function getSubmissions()
    {
        return $this->hasMany(ObligationSubmission::class, ['task_id' => 'id'])->orderBy(['submitted_at' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getLatestSubmission()
    {
        return $this->hasOne(ObligationSubmission::class, ['task_id' => 'id'])
            ->andWhere(['obligation_submission.id' => (new \yii\db\Query())->select('max(s2.id)')->from(['s2' => '{{%obligation_submission}}'])
                ->where('s2.task_id = obligation_submission.task_id')]);
    }
}
