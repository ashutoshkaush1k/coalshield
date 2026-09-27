<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\ScopedActiveRecord;

/**
 * One changed field of a submitted or locked production entry, with its reason (brief rule 9);
 * data/schema/production_edit_log.yaml. Written only by ProductionService::editWithReason.
 *
 * @property int $id
 * @property int $production_id
 * @property string $field
 * @property string $old_value
 * @property string $new_value
 * @property string $reason
 * @property int $edited_by
 * @property string $edited_at
 */
class ProductionEditLog extends ScopedActiveRecord
{
    public static function tableName(): string
    {
        return '{{%production_edit_log}}';
    }

    public static function scopePath(): string
    {
        return 'production.mine_id';
    }

    public function fields(): array
    {
        return [
            'id', 'production_id', 'field', 'old_value', 'new_value', 'reason', 'edited_by',
            'editor_name' => fn() => $this->editor?->full_name,
            'edited_at' => fn() => Format::utc($this->edited_at),
        ];
    }

    public function getProduction()
    {
        return $this->hasOne(DailyProduction::class, ['id' => 'production_id']);
    }

    public function getEditor()
    {
        return $this->hasOne(User::class, ['id' => 'edited_by']);
    }
}
