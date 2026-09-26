<?php

declare(strict_types=1);

namespace app\components;

/**
 * Base for every model: audited (brief rule 4) and transactional, so the audit row is written in the
 * same transaction as the change it records.
 */
abstract class ActiveRecord extends \yii\db\ActiveRecord
{
    /** Attributes never written to the audit log in clear. */
    public static function auditRedacted(): array
    {
        return [];
    }

    public function behaviors(): array
    {
        return ['audit' => AuditBehavior::class];
    }

    public function transactions(): array
    {
        return [self::SCENARIO_DEFAULT => self::OP_ALL];
    }
}
