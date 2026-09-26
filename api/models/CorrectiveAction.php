<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\HasStatusTransitions;
use app\components\ScopedActiveRecord;

/**
 * Corrective action for a violation; data/schema/corrective_action.yaml.
 * open -> resolved (resolving closes its violation). Overdue = open past due_at.
 *
 * @property int $id
 * @property int $mine_id
 * @property int $violation_id
 * @property int|null $alert_id
 * @property int|null $contractor_id
 * @property string $description
 * @property string $status
 * @property string $due_at
 * @property int $created_by
 * @property string|null $proof_image_path
 * @property string $created_at
 * @property string|null $resolved_at
 */
class CorrectiveAction extends ScopedActiveRecord implements HasStatusTransitions
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    public static function tableName(): string
    {
        return '{{%corrective_action}}';
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return [self::STATUS_OPEN => [self::STATUS_RESOLVED], self::STATUS_RESOLVED => []];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        StatusHistory::record($this, $from, $to, $userId, $context);
    }

    public function rules(): array
    {
        return [
            [['mine_id', 'violation_id', 'description', 'due_at', 'created_by'], 'required', 'message' => 'REQUIRED'],
            [['mine_id', 'violation_id', 'alert_id', 'contractor_id', 'created_by'], 'integer', 'message' => 'INVALID_VALUE'],
            [['description'], 'string', 'min' => 3, 'max' => 2000, 'tooShort' => 'TOO_SHORT', 'tooLong' => 'TOO_LONG'],
            [['due_at'], 'datetime', 'format' => 'php:Y-m-d\TH:i:s\Z', 'message' => 'INVALID_DATETIME', 'when' => fn() => $this->isNewRecord],
            [['status'], 'in', 'range' => [self::STATUS_OPEN, self::STATUS_RESOLVED], 'message' => 'INVALID_VALUE'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if ($insert && !$this->created_at) {
            $this->created_at = Format::sql(Format::now());
        }
        return parent::beforeSave($insert);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OPEN && strtotime($this->due_at) < time();
    }

    public function fields(): array
    {
        return [
            'id', 'mine_id', 'violation_id', 'alert_id', 'contractor_id', 'description', 'status',
            'due_at' => fn() => Format::utc($this->due_at),
            'is_overdue' => fn() => $this->isOverdue(),
            'created_by', 'proof_image_path',
            'created_at' => fn() => Format::utc($this->created_at),
            'resolved_at' => fn() => Format::utc($this->resolved_at),
        ];
    }

    public function extraFields(): array
    {
        return ['violation', 'history' => fn() => StatusHistory::forEntity($this)];
    }

    public function getViolation()
    {
        return $this->hasOne(Violation::class, ['id' => 'violation_id']);
    }
}
