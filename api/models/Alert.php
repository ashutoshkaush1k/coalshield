<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\HasStatusTransitions;
use app\components\ScopedActiveRecord;

/**
 * An alert: {code, params} only, never display text (brief rule 7); data/schema/alert.yaml.
 * INSPECTION_DIRECTIVE alerts are raised by government users against a mine; the mine closes
 * them with proof, and government can reopen them. Transitions go to status_history.
 *
 * @property int $id
 * @property string $code
 * @property array $params
 * @property string $severity
 * @property int $mine_id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $status
 * @property int|null $ack_by
 * @property int $escalation_level
 * @property string $created_at
 */
class Alert extends ScopedActiveRecord implements HasStatusTransitions
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';
    public const SEVERITIES = ['low', 'medium', 'high'];

    public const CODE_DIRECTIVE = 'INSPECTION_DIRECTIVE';
    public const CODE_SENSOR = 'SENSOR_THRESHOLD_BREACHED';
    public const CODE_VIOLATION = 'VIOLATION_RECORDED';
    public const CODE_DANGEROUS_OCCURRENCE = 'DANGEROUS_OCCURRENCE_REPORTED';

    public static function tableName(): string
    {
        return '{{%alert}}';
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return [
            self::STATUS_OPEN => [self::STATUS_ACKNOWLEDGED, self::STATUS_RESOLVED],
            self::STATUS_ACKNOWLEDGED => [self::STATUS_RESOLVED],
            // Reopening is only offered for directives (AlertService::reopen checks it).
            self::STATUS_RESOLVED => [self::STATUS_OPEN],
        ];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        StatusHistory::record($this, $from, $to, $userId, $context);
    }

    public function rules(): array
    {
        return [
            [['code', 'severity', 'mine_id', 'entity_type', 'entity_id'], 'required', 'message' => 'REQUIRED'],
            [['severity'], 'in', 'range' => self::SEVERITIES, 'message' => 'INVALID_VALUE'],
            [['status'], 'in', 'range' => array_keys(self::transitions()), 'message' => 'INVALID_VALUE'],
            [['mine_id', 'entity_id', 'ack_by', 'escalation_level'], 'integer', 'message' => 'INVALID_VALUE'],
            [['code'], 'match', 'pattern' => '/^[A-Z_]+$/', 'message' => 'INVALID_VALUE'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if ($insert) {
            $this->created_at ??= Format::sql(Format::now());
            $this->status ??= self::STATUS_OPEN;
            $this->escalation_level ??= 0;
            if ($this->params === [] || $this->params === null) {
                $this->params = new \ArrayObject();
            }
        }
        return parent::beforeSave($insert);
    }

    public function isDirective(): bool
    {
        return $this->code === self::CODE_DIRECTIVE;
    }

    public function fields(): array
    {
        return [
            'id', 'code',
            'params' => fn() => (object) Format::json($this->params),
            'severity', 'mine_id',
            'mine_code' => fn() => $this->mine?->code,
            'mine_name' => fn() => $this->mine?->name,
            'entity_type', 'entity_id' => fn() => (int) $this->entity_id,
            'status', 'ack_by', 'escalation_level',
            'is_directive' => fn() => $this->isDirective(),
            'created_at' => fn() => Format::utc($this->created_at),
        ];
    }

    public function extraFields(): array
    {
        return ['history' => fn() => StatusHistory::forEntity($this)];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }
}
