<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\ScopedActiveRecord;

/**
 * A contractor's work at one mine; data/schema/contract.yaml. Scoped by its mine_id.
 *
 * @property int $id
 * @property int $contractor_id
 * @property int $mine_id
 * @property string $work_type
 * @property string $work_order_no
 * @property string $value
 * @property string $start_date
 * @property string $end_date
 * @property int $max_workers
 */
class Contract extends ScopedActiveRecord
{
    public const WORK_TYPES = ['ob_removal', 'transport', 'loading', 'civil', 'security', 'other'];
    public const EDITABLE = ['work_type', 'work_order_no', 'value', 'start_date', 'end_date', 'max_workers'];

    public static function tableName(): string
    {
        return '{{%contract}}';
    }

    public function rules(): array
    {
        return [
            [['contractor_id', 'mine_id', 'work_type', 'work_order_no', 'value', 'start_date', 'end_date', 'max_workers'], 'required', 'message' => 'REQUIRED'],
            [['contractor_id', 'mine_id'], 'integer', 'message' => 'INVALID_VALUE'],
            [['max_workers'], 'integer', 'min' => 1, 'max' => 5000, 'message' => 'INVALID_VALUE', 'tooSmall' => 'INVALID_VALUE', 'tooBig' => 'INVALID_VALUE'],
            [['value'], 'number', 'min' => 0, 'message' => 'INVALID_VALUE', 'tooSmall' => 'INVALID_VALUE'],
            [['work_type'], 'in', 'range' => self::WORK_TYPES, 'message' => 'INVALID_VALUE'],
            [['work_order_no'], 'string', 'max' => 64, 'tooLong' => 'TOO_LONG'],
            [['work_order_no'], 'unique', 'message' => 'NOT_UNIQUE'],
            [['start_date', 'end_date'], 'date', 'format' => 'php:Y-m-d', 'message' => 'INVALID_DATE'],
            [['end_date'], function () {
                if (!$this->hasErrors() && $this->end_date < $this->start_date) {
                    $this->addError('end_date', 'BEFORE_START_DATE');
                }
            }],
        ];
    }

    public function isActiveOn(string $date): bool
    {
        return $this->start_date <= $date && $this->end_date >= $date;
    }

    public function fields(): array
    {
        return [
            'id', 'contractor_id', 'mine_id',
            'mine_name' => fn() => $this->mine?->name,
            'work_type', 'work_order_no',
            'value' => fn() => Format::float($this->value),
            'start_date', 'end_date',
            'max_workers' => fn() => (int) $this->max_workers,
            'is_active' => fn() => $this->isActiveOn(gmdate('Y-m-d')),
        ];
    }

    public function getContractor()
    {
        return $this->hasOne(Contractor::class, ['id' => 'contractor_id']);
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }

    public function getWorkers()
    {
        return $this->hasMany(ContractWorker::class, ['contract_id' => 'id']);
    }

    public function getDocuments()
    {
        return $this->hasMany(ContractorDoc::class, ['contract_id' => 'id']);
    }
}
