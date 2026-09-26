<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\ScopedActiveRecord;

/**
 * An accident or dangerous occurrence; data/schema/incident.yaml (HANDOFF C23).
 * Incidents do not enter the demo compliance score.
 *
 * The obligation follows the severity, as in the data: fatal RPT-03 (r.7(1)), serious and minor
 * injuries RPT-04 (r.7(2)), dangerous occurrence RPT-05 (r.7(3)) - data/reference/obligations.csv.
 * The reporting check compares reported_at - occurred_at with 48 hours, the rule the data's
 * reported_within_48h column encodes.
 *
 * @property int $id
 * @property int $mine_id
 * @property string $occurred_at
 * @property string $reported_at
 * @property string $type
 * @property string $severity
 * @property int $persons_affected
 * @property string $description_code
 * @property int|null $related_violation_id
 * @property bool $reported_within_48h
 * @property string $obligation_code
 */
class Incident extends ScopedActiveRecord
{
    public const TYPES = ['ground_movement', 'transportation_winding', 'transportation_other', 'machinery_other',
        'explosives', 'electricity', 'gas_dust_fire', 'fall_other_than_ground', 'other_causes'];
    public const SEVERITIES = ['fatal', 'serious', 'minor', 'dangerous_occurrence'];
    public const OBLIGATION_FOR_SEVERITY = [
        'fatal' => 'RPT-03', 'serious' => 'RPT-04', 'minor' => 'RPT-04', 'dangerous_occurrence' => 'RPT-05',
    ];
    public const REPORTING_LIMIT_HOURS = 48;

    public static function tableName(): string
    {
        return '{{%incident}}';
    }

    public function rules(): array
    {
        return [
            [['mine_id', 'occurred_at', 'reported_at', 'type', 'severity', 'description_code'], 'required', 'message' => 'REQUIRED'],
            [['mine_id', 'persons_affected', 'related_violation_id'], 'integer', 'min' => 0, 'message' => 'INVALID_VALUE'],
            [['type'], 'in', 'range' => self::TYPES, 'message' => 'INVALID_VALUE'],
            [['severity'], 'in', 'range' => self::SEVERITIES, 'message' => 'INVALID_VALUE'],
            [['description_code'], 'match', 'pattern' => '/^[A-Z0-9_]{2,64}$/', 'message' => 'INVALID_VALUE'],
            [['occurred_at', 'reported_at'], 'datetime', 'format' => 'php:Y-m-d\TH:i:s\Z', 'message' => 'INVALID_DATETIME', 'when' => fn() => $this->isNewRecord],
            [['reported_at'], function () {
                if (!$this->hasErrors() && strtotime($this->reported_at) < strtotime($this->occurred_at)) {
                    $this->addError('reported_at', 'BEFORE_OCCURRED_AT');
                }
            }, 'when' => fn() => $this->isNewRecord],
            [['related_violation_id'], function () {
                $violation = Violation::findOne($this->related_violation_id);
                if ($violation === null || (int) $violation->mine_id !== (int) $this->mine_id) {
                    $this->addError('related_violation_id', 'NOT_IN_SAME_MINE');
                }
            }, 'skipOnEmpty' => true],
        ];
    }

    public function beforeValidate(): bool
    {
        if ($this->severity && isset(self::OBLIGATION_FOR_SEVERITY[$this->severity])) {
            $this->obligation_code = self::OBLIGATION_FOR_SEVERITY[$this->severity];
        }
        if ($this->severity === 'dangerous_occurrence') {
            $this->persons_affected = 0;
        }
        if ($this->occurred_at && $this->reported_at) {
            $this->reported_within_48h = $this->reportingHours() <= self::REPORTING_LIMIT_HOURS;
        }
        return parent::beforeValidate();
    }

    public function reportingHours(): float
    {
        return round((strtotime($this->reported_at) - strtotime($this->occurred_at)) / 3600, 1);
    }

    /** {code, params} for the 48-hour reporting check. */
    public function reportingCheck(): array
    {
        $hours = $this->reportingHours();
        return [
            'code' => $hours <= self::REPORTING_LIMIT_HOURS ? 'REPORTED_WITHIN_48H' : 'REPORTED_AFTER_48H',
            'params' => [
                'hours' => $hours,
                'limit_hours' => self::REPORTING_LIMIT_HOURS,
                'obligation_code' => $this->obligation_code,
            ],
        ];
    }

    public function fields(): array
    {
        return [
            'id', 'mine_id',
            'mine_name' => fn() => $this->mine?->name,
            'occurred_at' => fn() => Format::utc($this->occurred_at),
            'reported_at' => fn() => Format::utc($this->reported_at),
            'type', 'severity',
            'persons_affected' => fn() => (int) $this->persons_affected,
            'description_code', 'related_violation_id',
            'reported_within_48h' => fn() => (bool) $this->reported_within_48h,
            'obligation_code',
            'reporting_check' => fn() => $this->reportingCheck(),
        ];
    }

    public function extraFields(): array
    {
        return ['relatedViolation'];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }

    public function getRelatedViolation()
    {
        return $this->hasOne(Violation::class, ['id' => 'related_violation_id']);
    }
}
