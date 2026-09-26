<?php

use yii\db\Migration;

/** Inspection visits (PLAN Q11); data/schema/inspection.yaml. Closed inspections are locked (rule 9). */
class m260928_000002_create_inspection extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%inspection}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'inspector_id' => $this->integer()->notNull(),
            'inspection_type' => $this->string(16)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('scheduled'),
            'scheduled_for' => $this->date()->notNull(),
            'visited_at' => 'timestamptz NULL',
            'closed_at' => 'timestamptz NULL',
            'findings_count' => $this->integer()->notNull()->defaultValue(0),
            'is_locked' => $this->boolean()->notNull()->defaultValue(false),
        ]);
        $this->execute("ALTER TABLE {{%inspection}}
            ADD CONSTRAINT inspection_type_check CHECK (inspection_type IN ('regular', 'spot', 'complaint')),
            ADD CONSTRAINT inspection_status_check CHECK (status IN ('scheduled', 'visited', 'closed')),
            ADD CONSTRAINT inspection_findings_check CHECK (findings_count >= 0),
            ADD CONSTRAINT inspection_closed_locked_check CHECK (status <> 'closed' OR is_locked)");
        $this->addForeignKey('fk_inspection_mine', '{{%inspection}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_inspection_inspector', '{{%inspection}}', 'inspector_id', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_inspection_mine_status', '{{%inspection}}', ['mine_id', 'status']);
        $this->createIndex('idx_inspection_mine_date', '{{%inspection}}', ['mine_id', 'scheduled_for']);
        $this->createIndex('idx_inspection_inspector', '{{%inspection}}', 'inspector_id');
    }

    public function safeDown()
    {
        $this->dropTable('{{%inspection}}');
    }
}
