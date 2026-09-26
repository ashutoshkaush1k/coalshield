<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Read-only view of the audit chain. Rows are written only through AuditChain::append() and are
 * immutable in the database (trigger); this class is deliberately not audited itself.
 *
 * @property int $id
 * @property string $entity
 * @property int|null $entity_id
 * @property string $action
 * @property array|null $old_values
 * @property array|null $new_values
 * @property int|null $user_id
 * @property string|null $ip
 * @property string $created_at
 * @property string $prev_hash
 * @property string $row_hash
 */
class AuditLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%audit_log}}';
    }

    public function beforeSave($insert): bool
    {
        return false;
    }

    public function beforeDelete(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            'id', 'entity', 'entity_id', 'action', 'old_values', 'new_values', 'user_id', 'ip',
            'created_at' => fn() => User::isoUtc($this->created_at),
            'prev_hash', 'row_hash',
        ];
    }
}
