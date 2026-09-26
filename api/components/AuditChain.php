<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\db\Connection;
use yii\web\Application as WebApplication;

/**
 * Tamper-evident audit log (brief rule 4).
 *
 * The chain lives in the database, not in PHP: a BEFORE INSERT trigger on audit_log (migration
 * m260927_000006) takes a transaction-level advisory lock, assigns the id, links prev_hash to the
 * last row and computes row_hash = SHA-256(prev_hash || canonical JSON of the row) with the SQL
 * function audit_row_hash(). verify() recomputes the same function over every row, so hashing can
 * never drift between writer and verifier. UPDATE and DELETE on audit_log are rejected by a trigger.
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public static function append(string $entity, ?int $entityId, string $action, ?array $old, ?array $new, ?Connection $db = null): void
    {
        $db ??= Yii::$app->db;
        [$userId, $ip] = self::actor();
        $db->createCommand()->insert('{{%audit_log}}', [
            'entity' => trim($entity, '{}%'),
            'entity_id' => $entityId,
            'action' => $action,
            // Arrays, not JSON text: Yii encodes values bound to json/jsonb columns itself.
            'old_values' => $old,
            'new_values' => $new,
            'user_id' => $userId,
            'ip' => $ip,
        ])->execute();
    }

    /**
     * Recomputes every hash. Returns the broken rows (empty when the chain is intact).
     * @return list<array{id: int, reason: string}>
     */
    public static function verify(?Connection $db = null, int $limit = 20): array
    {
        $db ??= Yii::$app->db;
        $rows = $db->createCommand(<<<'SQL'
            SELECT id,
                   CASE WHEN prev_hash IS DISTINCT FROM coalesce(expected_prev, :genesis) THEN 'PREV_HASH_MISMATCH'
                        ELSE 'ROW_HASH_MISMATCH' END AS reason
              FROM (SELECT id, prev_hash, row_hash,
                           lag(row_hash) OVER (ORDER BY id) AS expected_prev,
                           audit_row_hash(prev_hash, id, entity, entity_id, action, old_values, new_values,
                                          user_id, ip, created_at) AS recomputed
                      FROM audit_log) chain
             WHERE prev_hash IS DISTINCT FROM coalesce(expected_prev, :genesis)
                OR row_hash IS DISTINCT FROM recomputed
             ORDER BY id
             LIMIT :limit
            SQL, [':genesis' => self::GENESIS, ':limit' => $limit])->queryAll();
        return array_map(static fn(array $r) => ['id' => (int) $r['id'], 'reason' => $r['reason']], $rows);
    }

    /** @return array{0: ?int, 1: ?string} */
    private static function actor(): array
    {
        if (!Yii::$app instanceof WebApplication) {
            return [null, null];
        }
        $identity = Yii::$app->user->identity;
        return [$identity?->getId() === null ? null : (int) $identity->getId(), Yii::$app->request->userIP];
    }
}
