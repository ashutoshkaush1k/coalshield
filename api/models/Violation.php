<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\Rules;
use app\components\ScopedActiveRecord;
use app\services\FieldCapture;

/**
 * A violation from PPE vision, an inspection or a grievance; data/schema/violation.yaml.
 * Unresolved violations cost the mine weight_ppe points each. Resolved ones stay on record.
 *
 * @property int $id
 * @property int $mine_id
 * @property string $violation_type
 * @property string $category
 * @property string|null $confidence
 * @property string $source
 * @property string|null $frame_ref
 * @property int|null $inspection_id
 * @property int|null $observation_id
 * @property int|null $contractor_id
 * @property string $detected_at
 * @property bool $resolved
 * @property string|null $resolved_at
 */
class Violation extends ScopedActiveRecord
{
    public const SOURCES = ['vision', 'inspection', 'grievance'];

    public static function tableName(): string
    {
        return '{{%violation}}';
    }

    public function rules(): array
    {
        return [
            [['mine_id', 'violation_type', 'category', 'source', 'detected_at'], 'required', 'message' => 'REQUIRED'],
            [['mine_id', 'inspection_id', 'observation_id', 'contractor_id'], 'integer', 'message' => 'INVALID_VALUE'],
            [['violation_type'], 'match', 'pattern' => '/^[a-z0-9_]{2,64}$/', 'message' => 'INVALID_VALUE'],
            [['category'], 'in', 'range' => fn() => Rules::categories(), 'message' => 'INVALID_VALUE'],
            [['source'], 'in', 'range' => self::SOURCES, 'message' => 'INVALID_VALUE'],
            [['confidence'], 'number', 'min' => 0, 'max' => 1, 'message' => 'INVALID_VALUE'],
            [['resolved'], 'boolean'],
            [['frame_ref'], 'string', 'max' => 255, 'tooLong' => 'TOO_LONG'],
        ];
    }

    public function fields(): array
    {
        return [
            'id', 'mine_id', 'violation_type', 'category',
            'confidence' => fn() => Format::float($this->confidence),
            'source', 'frame_ref', 'inspection_id', 'observation_id', 'contractor_id',
            'detected_at' => fn() => Format::utc($this->detected_at),
            'resolved' => fn() => (bool) $this->resolved,
            'resolved_at' => fn() => Format::utc($this->resolved_at),
            // Phase 7B: made from a field-app capture - device time, location, flags, photos (else null)
            'field' => fn() => $this->source === 'inspection' ? FieldCapture::forObservation($this->observation_id === null ? null : (int) $this->observation_id) : null,
        ];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }

    public function getCorrectiveActions()
    {
        return $this->hasMany(CorrectiveAction::class, ['violation_id' => 'id'])->orderBy(['id' => SORT_DESC]);
    }
}
