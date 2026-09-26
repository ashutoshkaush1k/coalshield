<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;

/**
 * An edit of a locked record (brief rule 9): which field, old and new value, the reason, who.
 * Append-only; audited itself through the base ActiveRecord.
 *
 * @property int $id
 * @property string $entity
 * @property int $entity_id
 * @property int|null $mine_id
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $reason
 * @property int $edited_by
 * @property string $edited_at
 */
class RecordEditLog extends \app\components\ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%record_edit_log}}';
    }

    /** @return self[] */
    public static function forEntity(\app\components\ActiveRecord $model): array
    {
        return self::find()
            ->where(['entity' => trim($model::tableName(), '{}%'), 'entity_id' => $model->getPrimaryKey()])
            ->orderBy(['id' => SORT_DESC])
            ->all();
    }

    public function fields(): array
    {
        return [
            'id', 'field', 'old_value', 'new_value', 'reason', 'edited_by',
            'edited_at' => fn() => Format::utc($this->edited_at),
        ];
    }
}
