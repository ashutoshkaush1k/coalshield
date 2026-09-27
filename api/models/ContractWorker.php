<?php

declare(strict_types=1);

namespace app\models;

use app\components\Rules;
use app\components\ScopedActiveRecord;

/**
 * A contract worker; data/schema/contract_worker.yaml. Scoped through the contract's mine.
 * Vocational-training refresher: OSH (Central) Rules 2026 r.159, every 4 years (SAF-04).
 * Periodical medical examination: r.109(1), annually (HLT-01). Both periods come from rules.yaml.
 *
 * @property int $id
 * @property int $contract_id
 * @property string $name
 * @property string $worker_code
 * @property string $vt_cert_valid_to
 * @property string $medical_exam_date
 * @property bool $active
 */
class ContractWorker extends ScopedActiveRecord
{
    public const EDITABLE = ['name', 'worker_code', 'vt_cert_valid_to', 'medical_exam_date', 'active'];

    public static function tableName(): string
    {
        return '{{%contract_worker}}';
    }

    public static function scopePath(): string
    {
        return 'contract.mine_id';
    }

    public function rules(): array
    {
        return [
            [['contract_id', 'name', 'worker_code', 'vt_cert_valid_to', 'medical_exam_date'], 'required', 'message' => 'REQUIRED'],
            [['contract_id'], 'integer', 'message' => 'INVALID_VALUE'],
            [['name'], 'string', 'min' => 2, 'max' => 160, 'tooShort' => 'TOO_SHORT', 'tooLong' => 'TOO_LONG'],
            [['worker_code'], 'match', 'pattern' => '/^[A-Za-z0-9_-]{3,32}$/', 'message' => 'INVALID_VALUE'],
            [['worker_code'], 'unique', 'message' => 'NOT_UNIQUE'],
            [['vt_cert_valid_to', 'medical_exam_date'], 'date', 'format' => 'php:Y-m-d', 'message' => 'INVALID_DATE'],
            [['active'], 'boolean', 'message' => 'INVALID_VALUE'],
        ];
    }

    /** VT certificate past its validity (SAF-04). */
    public function vtExpired(string $today): bool
    {
        return $this->vt_cert_valid_to < $today;
    }

    /** Periodical medical examination overdue (HLT-01: medical_exam_interval_months, 12). */
    public function medicalOverdue(string $today): bool
    {
        $months = (int) Rules::value('legal', 'medical_exam_interval_months');
        return (new \DateTimeImmutable($this->medical_exam_date))->modify("+{$months} months")->format('Y-m-d') < $today;
    }

    public function fields(): array
    {
        $today = gmdate('Y-m-d');
        return [
            'id', 'contract_id', 'name', 'worker_code', 'vt_cert_valid_to', 'medical_exam_date',
            'active' => fn() => (bool) $this->active,
            'vt_expired' => fn() => $this->vtExpired($today),
            'medical_overdue' => fn() => $this->medicalOverdue($today),
        ];
    }

    public function getContract()
    {
        return $this->hasOne(Contract::class, ['id' => 'contract_id']);
    }
}
