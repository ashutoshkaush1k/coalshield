<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\HasStatusTransitions;
use app\components\Rules;
use app\components\ScopedActiveRecord;
use app\services\FieldCapture;

/**
 * A candidate finding from an inspection (or, from Phase 5, a safety grievance);
 * data/schema/observation.yaml. open -> promoted (creates a violation) | dismissed.
 *
 * @property int $id
 * @property int|null $inspection_id
 * @property int|null $grievance_id
 * @property int $mine_id
 * @property string $category
 * @property string $severity
 * @property string $status
 * @property int|null $violation_id
 * @property int|null $contractor_id
 * @property string $observed_at
 */
class Observation extends ScopedActiveRecord implements HasStatusTransitions
{
    public const SEVERITIES = ['low', 'medium', 'high'];

    public static function tableName(): string
    {
        return '{{%observation}}';
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return ['open' => ['promoted', 'dismissed'], 'promoted' => [], 'dismissed' => []];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        StatusHistory::record($this, $from, $to, $userId, $context);
    }

    public function rules(): array
    {
        return [
            [['mine_id', 'category', 'severity', 'observed_at'], 'required', 'message' => 'REQUIRED'],
            [['inspection_id', 'grievance_id', 'mine_id', 'violation_id', 'contractor_id'], 'integer', 'message' => 'INVALID_VALUE'],
            [['category'], 'in', 'range' => fn() => Rules::categories(), 'message' => 'INVALID_VALUE'],
            [['severity'], 'in', 'range' => self::SEVERITIES, 'message' => 'INVALID_VALUE'],
        ];
    }

    public function fields(): array
    {
        return [
            'id', 'inspection_id', 'grievance_id', 'mine_id', 'category', 'severity', 'status',
            'violation_id', 'contractor_id',
            'observed_at' => fn() => Format::utc($this->observed_at),
            // Phase 7B: a field-app capture (else null)
            'field' => fn() => FieldCapture::forObservation($this->id === null ? null : (int) $this->id),
        ];
    }

    public function getInspection()
    {
        return $this->hasOne(Inspection::class, ['id' => 'inspection_id']);
    }
}
