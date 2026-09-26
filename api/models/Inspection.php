<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\HasStatusTransitions;
use app\components\ScopedActiveRecord;

/**
 * An inspection visit (PLAN Q11); data/schema/inspection.yaml.
 * scheduled -> visited -> closed. Closing locks the record (brief rule 9): later edits need a
 * reason and go to record_edit_log.
 *
 * @property int $id
 * @property int $mine_id
 * @property int $inspector_id
 * @property string $inspection_type
 * @property string $status
 * @property string $scheduled_for
 * @property string|null $visited_at
 * @property string|null $closed_at
 * @property int $findings_count
 * @property bool $is_locked
 */
class Inspection extends ScopedActiveRecord implements HasStatusTransitions
{
    public const TYPES = ['regular', 'spot', 'complaint'];
    /** Fields that may be edited through PATCH (with a reason once locked). */
    public const EDITABLE = ['inspection_type', 'scheduled_for'];

    public static function tableName(): string
    {
        return '{{%inspection}}';
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return ['scheduled' => ['visited'], 'visited' => ['closed'], 'closed' => []];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        StatusHistory::record($this, $from, $to, $userId, $context);
    }

    public function beforeSave($insert): bool
    {
        $now = Format::sql(Format::now());
        if ($this->isAttributeChanged('status')) {
            if ($this->status === 'visited' && !$this->visited_at) {
                $this->visited_at = $now;
            }
            if ($this->status === 'closed') {
                $this->closed_at ??= $now;
                $this->is_locked = true;
            }
        }
        return parent::beforeSave($insert);
    }

    public function rules(): array
    {
        return [
            [['mine_id', 'inspector_id', 'inspection_type', 'scheduled_for'], 'required', 'message' => 'REQUIRED'],
            [['mine_id', 'inspector_id', 'findings_count'], 'integer', 'message' => 'INVALID_VALUE'],
            [['inspection_type'], 'in', 'range' => self::TYPES, 'message' => 'INVALID_VALUE'],
            [['scheduled_for'], 'date', 'format' => 'php:Y-m-d', 'message' => 'INVALID_DATE'],
        ];
    }

    public function fields(): array
    {
        return [
            'id', 'mine_id',
            'mine_name' => fn() => $this->mine?->name,
            'inspector_id',
            'inspector_name' => fn() => $this->inspector?->full_name,
            'inspection_type', 'status', 'scheduled_for',
            'visited_at' => fn() => Format::utc($this->visited_at),
            'closed_at' => fn() => Format::utc($this->closed_at),
            'findings_count' => fn() => (int) $this->findings_count,
            'is_locked' => fn() => (bool) $this->is_locked,
        ];
    }

    public function extraFields(): array
    {
        return [
            'observations',
            'history' => fn() => StatusHistory::forEntity($this),
            'edits' => fn() => RecordEditLog::forEntity($this),
        ];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }

    public function getInspector()
    {
        return $this->hasOne(User::class, ['id' => 'inspector_id']);
    }

    public function getObservations()
    {
        return $this->hasMany(Observation::class, ['inspection_id' => 'id'])->orderBy(['observed_at' => SORT_ASC, 'id' => SORT_ASC]);
    }
}
