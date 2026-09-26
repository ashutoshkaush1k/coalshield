<?php

declare(strict_types=1);

namespace app\components;

use app\models\Mine;
use yii\base\Behavior;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;

/**
 * Writes one audit_log row per insert / update / delete of the owner (brief rule 4).
 * old_values / new_values hold only the changed attributes on update; redacted attributes
 * (e.g. password_hash) are replaced by "[redacted]". Updates that change nothing are not logged.
 */
class AuditBehavior extends Behavior
{
    public function events(): array
    {
        return [
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            BaseActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    public function afterInsert(AfterSaveEvent $event): void
    {
        $owner = $this->record();
        AuditChain::append($owner::tableName(), $this->entityId(), 'insert', null, $this->redact($owner->getAttributes()), null, $this->mineId());
    }

    public function afterUpdate(AfterSaveEvent $event): void
    {
        $owner = $this->record();
        $old = [];
        $new = [];
        foreach ($event->changedAttributes as $name => $oldValue) {
            $newValue = $owner->getAttribute($name);
            if ($oldValue == $newValue && gettype($oldValue) === gettype($newValue)) {
                continue;
            }
            $old[$name] = $oldValue;
            $new[$name] = $newValue;
        }
        if ($new === []) {
            return;
        }
        AuditChain::append($owner::tableName(), $this->entityId(), 'update', $this->redact($old), $this->redact($new), null, $this->mineId());
    }

    public function afterDelete(): void
    {
        $owner = $this->record();
        AuditChain::append($owner::tableName(), $this->entityId(), 'delete', $this->redact($owner->getOldAttributes() ?: $owner->getAttributes()), null, null, $this->mineId());
    }

    private function record(): ActiveRecord
    {
        /** @var ActiveRecord $owner */
        $owner = $this->owner;
        return $owner;
    }

    /** The mine the record belongs to (its mine_id, or its own id for a mine), for scoped reading. */
    private function mineId(): ?int
    {
        $owner = $this->record();
        if ($owner instanceof Mine) {
            return $owner->id === null ? null : (int) $owner->id;
        }
        $value = $owner->hasAttribute('mine_id') ? ($owner->getAttribute('mine_id') ?? $owner->getOldAttribute('mine_id')) : null;
        return $value === null ? null : (int) $value;
    }

    private function entityId(): ?int
    {
        $pk = $this->record()->getPrimaryKey();
        return is_numeric($pk) ? (int) $pk : null;
    }

    /** @param array<string, mixed> $values */
    private function redact(array $values): array
    {
        foreach ($this->record()::auditRedacted() as $name) {
            if (array_key_exists($name, $values)) {
                $values[$name] = '[redacted]';
            }
        }
        return $values;
    }
}
