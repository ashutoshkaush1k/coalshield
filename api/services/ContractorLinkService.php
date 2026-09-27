<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\models\Contractor;
use yii\db\Query;

/**
 * Validates a contractor chosen for a violation or corrective action: it must be in the caller's
 * scope (else 404) and hold a contract at the record's mine (else 422 NOT_CONTRACTED_AT_MINE).
 */
final class ContractorLinkService
{
    public static function contractorFor(int $mineId, mixed $contractorId): ?int
    {
        if ($contractorId === null || $contractorId === '') {
            return null;
        }
        if (!is_numeric($contractorId)) {
            throw ApiException::fields(['contractor_id' => ['INVALID_VALUE']]);
        }
        $contractor = Contractor::findScoped((int) $contractorId);
        $contracted = (new Query())->from('{{%contract}}')->where(['contractor_id' => $contractor->id, 'mine_id' => $mineId])->exists();
        if (!$contracted) {
            throw ApiException::fields(['contractor_id' => ['NOT_CONTRACTED_AT_MINE']]);
        }
        return (int) $contractor->id;
    }
}
