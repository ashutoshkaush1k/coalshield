<?php

use yii\db\Migration;

/**
 * status_history: one row per workflow transition (brief rule 5) for the Phase 2 entities
 * (alert, corrective_action, inspection, observation). Later modules with their own history
 * table in the data (grievance_action, production_edit_log) keep theirs.
 * context carries {code, params}-style data: proof text, file id, reason.
 *
 * record_edit_log: edits of locked records (brief rule 9) - field, old/new value, the required
 * reason and who made it.
 */
class m260928_000009_create_status_history_and_edit_log extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%status_history}}', [
            'id' => $this->bigPrimaryKey(),
            'entity' => $this->string(48)->notNull(),
            'entity_id' => $this->bigInteger()->notNull(),
            'mine_id' => $this->integer()->null(),
            'from_status' => $this->string(24)->notNull(),
            'to_status' => $this->string(24)->notNull(),
            'user_id' => $this->integer()->null(),
            'context' => "jsonb NOT NULL DEFAULT '{}'::jsonb",
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->addForeignKey('fk_status_history_mine', '{{%status_history}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_status_history_user', '{{%status_history}}', 'user_id', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_status_history_entity', '{{%status_history}}', ['entity', 'entity_id', 'id']);
        $this->createIndex('idx_status_history_mine', '{{%status_history}}', 'mine_id');
        $this->createIndex('idx_status_history_user', '{{%status_history}}', 'user_id');

        $this->createTable('{{%record_edit_log}}', [
            'id' => $this->bigPrimaryKey(),
            'entity' => $this->string(48)->notNull(),
            'entity_id' => $this->bigInteger()->notNull(),
            'mine_id' => $this->integer()->null(),
            'field' => $this->string(64)->notNull(),
            'old_value' => $this->text()->null(),
            'new_value' => $this->text()->null(),
            'reason' => $this->text()->notNull(),
            'edited_by' => $this->integer()->notNull(),
            'edited_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%record_edit_log}} ADD CONSTRAINT record_edit_log_reason_check CHECK (length(trim(reason)) > 0)");
        $this->addForeignKey('fk_record_edit_log_mine', '{{%record_edit_log}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_record_edit_log_user', '{{%record_edit_log}}', 'edited_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_record_edit_log_entity', '{{%record_edit_log}}', ['entity', 'entity_id']);
        $this->createIndex('idx_record_edit_log_mine', '{{%record_edit_log}}', 'mine_id');
        $this->createIndex('idx_record_edit_log_user', '{{%record_edit_log}}', 'edited_by');
    }

    public function safeDown()
    {
        $this->dropTable('{{%record_edit_log}}');
        $this->dropTable('{{%status_history}}');
    }
}
