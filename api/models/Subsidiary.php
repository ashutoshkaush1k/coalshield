<?php

declare(strict_types=1);

namespace app\models;

use app\components\ActiveRecord;

/**
 * An operating company (CIL as holding, its subsidiaries, SCCL, NLC, ...). Reference data, visible
 * to every role. Columns: data/schema/subsidiary.yaml.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property int|null $parent_id
 */
class Subsidiary extends ActiveRecord
{
    public const TYPES = ['holding', 'subsidiary', 'psu', 'state_jv', 'private'];

    public static function tableName(): string
    {
        return '{{%subsidiary}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name', 'type'], 'required', 'message' => 'REQUIRED'],
            [['code'], 'string', 'max' => 32, 'tooLong' => 'TOO_LONG'],
            [['name'], 'string', 'max' => 160, 'tooLong' => 'TOO_LONG'],
            [['type'], 'in', 'range' => self::TYPES, 'message' => 'INVALID_VALUE'],
            [['parent_id'], 'integer', 'message' => 'INVALID_VALUE'],
            [['code'], 'unique', 'message' => 'NOT_UNIQUE'],
        ];
    }

    public function getParent()
    {
        return $this->hasOne(self::class, ['id' => 'parent_id']);
    }
}
