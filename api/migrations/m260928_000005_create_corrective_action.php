<?php

use yii\db\Migration;

/** Corrective actions for violations; data/schema/corrective_action.yaml. */
class m260928_000005_create_corrective_action extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%corrective_action}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'violation_id' => $this->integer()->notNull(),
            'alert_id' => $this->integer()->null(),
            'contractor_id' => $this->integer()->null(),
            'description' => $this->text()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('open'),
            'due_at' => 'timestamptz NOT NULL',
            'created_by' => $this->integer()->notNull(),
            'proof_image_path' => $this->string(255)->null(),
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
            'resolved_at' => 'timestamptz NULL',
        ]);
        $this->execute("ALTER TABLE {{%corrective_action}}
            ADD CONSTRAINT corrective_action_status_check CHECK (status IN ('open', 'resolved')),
            ADD CONSTRAINT corrective_action_resolved_check CHECK ((status = 'resolved') = (resolved_at IS NOT NULL))");
        $this->addForeignKey('fk_corrective_action_mine', '{{%corrective_action}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_corrective_action_violation', '{{%corrective_action}}', 'violation_id', '{{%violation}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_corrective_action_alert', '{{%corrective_action}}', 'alert_id', '{{%alert}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_corrective_action_created_by', '{{%corrective_action}}', 'created_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_corrective_action_mine_status', '{{%corrective_action}}', ['mine_id', 'status']);
        $this->createIndex('idx_corrective_action_mine_due', '{{%corrective_action}}', ['mine_id', 'due_at']);
        $this->createIndex('idx_corrective_action_violation', '{{%corrective_action}}', 'violation_id');
        $this->createIndex('idx_corrective_action_alert', '{{%corrective_action}}', 'alert_id');
        $this->createIndex('idx_corrective_action_contractor', '{{%corrective_action}}', 'contractor_id');
        $this->createIndex('idx_corrective_action_created_by', '{{%corrective_action}}', 'created_by');
    }

    public function safeDown()
    {
        $this->dropTable('{{%corrective_action}}');
    }
}
