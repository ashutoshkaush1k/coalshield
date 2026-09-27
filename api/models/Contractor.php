<?php

declare(strict_types=1);

namespace app\models;

use app\components\HasStatusTransitions;
use app\components\ScopedActiveRecord;

/**
 * A contractor company; data/schema/contractor.yaml. Global, not per mine: an account sees a
 * contractor when one of its contracts is at a mine in scope ("via:contract.contractor_id").
 * Labour licence validity is governed by the OSH Code 2020 s.48(3) (obligation LAB-02, 5 years).
 *
 * @property int $id
 * @property string $name
 * @property string $registration_no
 * @property string $labour_licence_no
 * @property string $licence_valid_to
 * @property string $epf_code
 * @property string $esi_code
 * @property string $contact
 * @property string $status
 */
class Contractor extends ScopedActiveRecord implements HasStatusTransitions
{
    public const STATUSES = ['active', 'suspended', 'blacklisted'];
    public const EDITABLE = ['name', 'registration_no', 'labour_licence_no', 'licence_valid_to', 'epf_code', 'esi_code', 'contact'];

    public static function tableName(): string
    {
        return '{{%contractor}}';
    }

    public static function scopePath(): string
    {
        return 'via:contract.contractor_id';
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return [
            'active' => ['suspended', 'blacklisted'],
            'suspended' => ['active', 'blacklisted'],
            'blacklisted' => ['active'],
        ];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        StatusHistory::record($this, $from, $to, $userId, $context);
    }

    public function rules(): array
    {
        return [
            [['name', 'registration_no', 'labour_licence_no', 'licence_valid_to', 'epf_code', 'esi_code', 'contact'], 'required', 'message' => 'REQUIRED'],
            [['name'], 'string', 'min' => 2, 'max' => 160, 'tooShort' => 'TOO_SHORT', 'tooLong' => 'TOO_LONG'],
            [['registration_no', 'labour_licence_no', 'epf_code', 'esi_code', 'contact'], 'string', 'max' => 64, 'tooLong' => 'TOO_LONG'],
            [['licence_valid_to'], 'date', 'format' => 'php:Y-m-d', 'message' => 'INVALID_DATE'],
            [['registration_no'], 'unique', 'message' => 'NOT_UNIQUE'],
            [['status'], 'in', 'range' => self::STATUSES, 'message' => 'INVALID_VALUE'],
        ];
    }

    public function fields(): array
    {
        return ['id', 'name', 'registration_no', 'labour_licence_no', 'licence_valid_to', 'epf_code', 'esi_code', 'contact', 'status'];
    }

    public function getContracts()
    {
        return $this->hasMany(Contract::class, ['contractor_id' => 'id']);
    }
}
