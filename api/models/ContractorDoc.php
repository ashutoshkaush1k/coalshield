<?php

declare(strict_types=1);

namespace app\models;

use app\components\ScopedActiveRecord;
use Yii;

/**
 * A monthly contractor compliance document; data/schema/contractor_compliance_doc.yaml.
 * Scoped through the contract's mine. The file itself lives in FileStorage.
 * epf_challan is kept as a document type; the status of the EPF Act, 1952 is TODO-VERIFY
 * (data/reference/legal_instruments.csv), so no legal claim is attached to it.
 *
 * @property int $id
 * @property int $contract_id
 * @property string $doc_type
 * @property string $period
 * @property int $file_id
 * @property bool $verified
 * @property int|null $verified_by
 */
class ContractorDoc extends ScopedActiveRecord
{
    public const TYPES = ['wage_register', 'epf_challan', 'esi_challan', 'insurance', 'other'];
    /** Documents due every month for every active contract (product setting, brief Phase 3). */
    public const MONTHLY = ['wage_register', 'epf_challan', 'esi_challan'];

    public static function tableName(): string
    {
        return '{{%contractor_compliance_doc}}';
    }

    public static function scopePath(): string
    {
        return 'contract.mine_id';
    }

    public function rules(): array
    {
        return [
            [['contract_id', 'doc_type', 'period', 'file_id'], 'required', 'message' => 'REQUIRED'],
            [['doc_type'], 'in', 'range' => self::TYPES, 'message' => 'INVALID_VALUE'],
            [['period'], 'match', 'pattern' => '/^\d{4}-(0[1-9]|1[0-2])$/', 'message' => 'INVALID_PERIOD'],
            [['period'], 'unique', 'targetAttribute' => ['contract_id', 'doc_type', 'period'], 'message' => 'ALREADY_UPLOADED'],
        ];
    }

    public function fields(): array
    {
        return [
            'id', 'contract_id', 'doc_type', 'period', 'file_id',
            'verified' => fn() => (bool) $this->verified,
            'verified_by',
            'mime' => fn() => $this->file?->mime,
            'size' => fn() => $this->file ? (int) $this->file->size : null,
            'uploaded_at' => fn() => \app\components\Format::utc($this->file?->created_at),
            // Seeded documents have metadata only (no bytes); a link is offered when the file exists.
            'url' => fn() => $this->file && Yii::$app->fileStorage->verify($this->file) ? Yii::$app->fileStorage->signedUrl($this->file) : null,
        ];
    }

    public function getFile()
    {
        return $this->hasOne(File::class, ['id' => 'file_id']);
    }

    public function getContract()
    {
        return $this->hasOne(Contract::class, ['id' => 'contract_id']);
    }
}
