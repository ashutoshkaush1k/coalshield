<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\components\AuditChain;
use app\models\Mine;
use app\models\User;
use app\tests\Support\Helper\Auth;
use Codeception\Test\Unit;
use Yii;
use yii\db\Exception as DbException;

/** Brief rule 4: every model change is logged, the chain verifies, and tampering is detected. */
class AuditChainTest extends Unit
{
    public function testSeedStartsAnIntactChain(): void
    {
        $first = Yii::$app->db->createCommand('SELECT entity, action, prev_hash FROM audit_log ORDER BY id LIMIT 1')->queryOne();
        $this->assertSame(['entity' => 'seed', 'action' => 'seed', 'prev_hash' => AuditChain::GENESIS], $first);
        $this->assertSame([], AuditChain::verify());
    }

    public function testInsertUpdateDeleteAreLoggedAndChained(): void
    {
        $before = $this->lastId();
        $mine = new Mine([
            'code' => 'TEST-01', 'name' => 'Audit Test Mine', 'type' => 'opencast', 'subsidiary_id' => 1,
            'status' => 'active', 'district' => 'Dhanbad', 'state' => 'Jharkhand', 'region' => 'East',
            'location_quality' => 'district_centroid',
        ]);
        $this->assertTrue($mine->save(), json_encode($mine->errors));
        $mine->name = 'Audit Test Mine (renamed)';
        $this->assertTrue($mine->save());
        $mine->name = 'Audit Test Mine (renamed)';   // no change: no entry
        $this->assertTrue($mine->save());
        $this->assertSame(1, $mine->delete());

        $rows = Yii::$app->db->createCommand('SELECT action, entity_id, old_values, new_values, prev_hash, row_hash FROM audit_log WHERE id > :id ORDER BY id', [':id' => $before])->queryAll();
        $this->assertSame(['insert', 'update', 'delete'], array_column($rows, 'action'));
        $update = json_decode($rows[1]['new_values'], true);
        $this->assertSame(['name' => 'Audit Test Mine (renamed)'], $update);
        $this->assertSame($rows[0]['row_hash'], $rows[1]['prev_hash']);
        $this->assertSame($rows[1]['row_hash'], $rows[2]['prev_hash']);
        $this->assertSame([], AuditChain::verify());
    }

    public function testPasswordHashIsRedacted(): void
    {
        $user = Auth::user(Auth::GOVERNMENT);
        $user->setPassword('another-demo-password');
        $this->assertTrue($user->save());
        $values = Yii::$app->db->createCommand("SELECT old_values, new_values FROM audit_log WHERE entity = 'user' ORDER BY id DESC LIMIT 1")->queryOne();
        $this->assertSame('[redacted]', json_decode($values['new_values'], true)['password_hash']);
        $this->assertSame('[redacted]', json_decode($values['old_values'], true)['password_hash']);
    }

    public function testUpdateAndDeleteOnAuditLogAreRejected(): void
    {
        $db = Yii::$app->db;
        foreach (["UPDATE audit_log SET action = 'x'", 'DELETE FROM audit_log'] as $sql) {
            $savepoint = $db->beginTransaction();
            try {
                $db->createCommand($sql)->execute();
                $this->fail("$sql should be rejected");
            } catch (DbException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            } finally {
                $savepoint->rollBack();
            }
        }
    }

    public function testTamperingIsDetected(): void
    {
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        $user->preferred_language = 'te';
        $this->assertTrue($user->save());
        $user->preferred_language = 'mr';
        $this->assertTrue($user->save());
        $this->assertSame([], AuditChain::verify());

        $db = Yii::$app->db;
        $target = (int) $db->createCommand("SELECT id FROM audit_log WHERE entity = 'user' ORDER BY id DESC OFFSET 1 LIMIT 1")->queryScalar();
        // An attacker with table-owner rights disables the guard and edits history.
        $db->createCommand('ALTER TABLE audit_log DISABLE TRIGGER audit_log_immutable')->execute();
        $db->createCommand("UPDATE audit_log SET new_values = '{\"preferred_language\": \"en\"}' WHERE id = :id", [':id' => $target])->execute();
        $db->createCommand('ALTER TABLE audit_log ENABLE TRIGGER audit_log_immutable')->execute();

        $this->assertSame([['id' => $target, 'reason' => 'ROW_HASH_MISMATCH']], AuditChain::verify());
    }

    public function testRecomputedHashCannotHideTampering(): void
    {
        $user = Auth::user(Auth::CORPORATE_SECL);
        $user->preferred_language = 'or';
        $this->assertTrue($user->save());
        $user->preferred_language = 'hi';
        $this->assertTrue($user->save());

        $db = Yii::$app->db;
        $target = (int) $db->createCommand("SELECT id FROM audit_log WHERE entity = 'user' ORDER BY id DESC OFFSET 1 LIMIT 1")->queryScalar();
        // Rewrite a row AND its own hash: the next row's prev_hash no longer links.
        $db->createCommand('ALTER TABLE audit_log DISABLE TRIGGER audit_log_immutable')->execute();
        $db->createCommand(<<<'SQL'
            UPDATE audit_log SET new_values = '{"preferred_language": "en"}',
                   row_hash = audit_row_hash(prev_hash, id, entity, entity_id, action, old_values,
                                             '{"preferred_language": "en"}', user_id, ip, created_at)
             WHERE id = :id
            SQL, [':id' => $target])->execute();
        $db->createCommand('ALTER TABLE audit_log ENABLE TRIGGER audit_log_immutable')->execute();

        $broken = AuditChain::verify();
        $this->assertNotEmpty($broken);
        $this->assertSame('PREV_HASH_MISMATCH', $broken[0]['reason']);
        $this->assertGreaterThan($target, $broken[0]['id']);
    }

    public function testActorIsRecordedForWebRequests(): void
    {
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        Yii::$app->user->setIdentity($user);
        AuditChain::append('test', 7, 'custom_action', null, ['k' => 'v']);
        $row = Yii::$app->db->createCommand('SELECT user_id, entity_id FROM audit_log ORDER BY id DESC LIMIT 1')->queryOne();
        $this->assertSame(['user_id' => $user->id, 'entity_id' => 7], $row);
    }

    private function lastId(): int
    {
        return (int) Yii::$app->db->createCommand('SELECT coalesce(max(id), 0) FROM audit_log')->queryScalar();
    }
}
