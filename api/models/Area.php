<?php

declare(strict_types=1);

namespace app\models;

use app\components\ActiveRecord;

/**
 * An operating area of a company, as the company publishes it. Reference data.
 * Columns: data/schema/area.yaml.
 *
 * @property int $id
 * @property int $subsidiary_id
 * @property string $code
 * @property string $name
 * @property string|null $district
 * @property string|null $state
 */
class Area extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%area}}';
    }

    public function rules(): array
    {
        return [
            [['subsidiary_id', 'code', 'name'], 'required', 'message' => 'REQUIRED'],
            [['subsidiary_id'], 'integer', 'message' => 'INVALID_VALUE'],
            [['code'], 'string', 'max' => 64, 'tooLong' => 'TOO_LONG'],
            [['name', 'district', 'state'], 'string', 'max' => 160, 'tooLong' => 'TOO_LONG'],
            [['code'], 'unique', 'message' => 'NOT_UNIQUE'],
        ];
    }

    public function getSubsidiary()
    {
        return $this->hasOne(Subsidiary::class, ['id' => 'subsidiary_id']);
    }
}
