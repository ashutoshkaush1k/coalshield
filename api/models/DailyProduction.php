<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\HasStatusTransitions;
use app\components\ScopedActiveRecord;

/**
 * One mine x day x shift production entry; data/schema/daily_production.yaml.
 *
 * draft -> submitted (the mine head submits; closed to direct editing from then on) -> locked
 * (the reporting period closed, ProductionService::lockPastPeriods). A submitted or locked entry
 * changes only through ProductionService::editWithReason, which writes production_edit_log.
 *
 * @property int $id
 * @property int $mine_id
 * @property string $date
 * @property string $shift
 * @property string $coal_target_t
 * @property string $coal_actual_t
 * @property string $ob_target_m3
 * @property string $ob_actual_m3
 * @property string $dispatch_t
 * @property string $closing_stock_t
 * @property string $breakdown_hours
 * @property int $manpower_present
 * @property string|null $remarks
 * @property string $status
 * @property int|null $submitted_by
 * @property string|null $submitted_at
 */
class DailyProduction extends ScopedActiveRecord implements HasStatusTransitions
{
    public const SHIFTS = ['A', 'B', 'C'];
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_LOCKED = 'locked';
    /** The figures of an entry: what a mine head enters, and what an edit with reason may change. */
    public const NUMBERS = ['coal_target_t', 'coal_actual_t', 'ob_target_m3', 'ob_actual_m3', 'dispatch_t', 'closing_stock_t', 'breakdown_hours', 'manpower_present'];
    public const EDITABLE = [...self::NUMBERS, 'remarks'];

    public static function tableName(): string
    {
        return '{{%daily_production}}';
    }

    public static function scopePath(): string
    {
        return 'mine_id';
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return [
            self::STATUS_DRAFT => [self::STATUS_SUBMITTED],
            self::STATUS_SUBMITTED => [self::STATUS_LOCKED],
        ];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        StatusHistory::record($this, $from, $to, $userId, $context);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function rules(): array
    {
        return [
            [['mine_id', 'date', 'shift', ...self::NUMBERS], 'required', 'message' => 'REQUIRED'],
            [['date'], 'date', 'format' => 'php:Y-m-d', 'message' => 'INVALID_DATE'],
            [['shift'], 'in', 'range' => self::SHIFTS, 'message' => 'INVALID_VALUE'],
            [['coal_target_t', 'coal_actual_t', 'ob_target_m3', 'ob_actual_m3', 'dispatch_t', 'closing_stock_t'], 'number',
                'min' => 0, 'max' => 9999999999, 'message' => 'INVALID_NUMBER', 'tooSmall' => 'NEGATIVE', 'tooBig' => 'TOO_LARGE'],
            [['breakdown_hours'], 'number', 'min' => 0, 'max' => 8, 'message' => 'INVALID_NUMBER', 'tooSmall' => 'NEGATIVE', 'tooBig' => 'OVER_SHIFT_HOURS'],
            [['manpower_present'], 'integer', 'min' => 0, 'max' => 100000, 'message' => 'INVALID_NUMBER', 'tooSmall' => 'NEGATIVE', 'tooBig' => 'TOO_LARGE'],
            [['remarks'], 'string', 'max' => 1000, 'tooLong' => 'TOO_LONG'],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_LOCKED], 'message' => 'INVALID_VALUE'],
            [['shift'], 'unique', 'targetAttribute' => ['mine_id', 'date', 'shift'], 'message' => 'ALREADY_REPORTED'],
        ];
    }

    public function fields(): array
    {
        $numbers = [];
        foreach (self::NUMBERS as $field) {
            $numbers[$field] = $field === 'manpower_present' ? fn() => (int) $this->manpower_present : fn() => (float) $this->$field;
        }
        return [
            'id', 'mine_id', 'date', 'shift', ...$numbers, 'remarks', 'status', 'submitted_by',
            'submitted_at' => fn() => Format::utc($this->submitted_at),
            'is_locked' => fn() => !$this->isDraft(),
        ];
    }

    public function extraFields(): array
    {
        return ['edits' => fn() => array_map(fn(ProductionEditLog $e) => $e->toArray(), $this->edits)];
    }

    public function getEdits()
    {
        return $this->hasMany(ProductionEditLog::class, ['production_id' => 'id'])->orderBy(['id' => SORT_DESC]);
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }
}
