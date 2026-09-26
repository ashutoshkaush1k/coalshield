<?php

declare(strict_types=1);

namespace app\models;

use app\components\ActiveRecord;

/**
 * Metadata of a stored file (bytes live under FileStorage::$dir, outside the web root).
 * Columns: data/schema/file.yaml.
 *
 * @property int $id
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property int $uploaded_by
 * @property string $entity
 * @property int $entity_id
 * @property string $created_at
 */
class File extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%file}}';
    }

    public function rules(): array
    {
        return [
            [['path', 'mime', 'size', 'sha256', 'uploaded_by', 'entity', 'entity_id'], 'required', 'message' => 'REQUIRED'],
            [['size', 'uploaded_by', 'entity_id'], 'integer', 'min' => 0, 'message' => 'INVALID_VALUE'],
            [['sha256'], 'match', 'pattern' => '/^[0-9a-f]{64}$/', 'message' => 'INVALID_VALUE'],
            [['path'], 'string', 'max' => 512, 'tooLong' => 'TOO_LONG'],
            [['mime', 'entity'], 'string', 'max' => 64, 'tooLong' => 'TOO_LONG'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if ($insert && !$this->created_at) {
            $this->created_at = gmdate('Y-m-d\TH:i:s\Z');
        }
        return parent::beforeSave($insert);
    }

    public function fields(): array
    {
        return [
            'id', 'mime', 'size', 'sha256', 'uploaded_by', 'entity', 'entity_id',
            'created_at' => fn() => User::isoUtc($this->created_at),
        ];
    }
}
